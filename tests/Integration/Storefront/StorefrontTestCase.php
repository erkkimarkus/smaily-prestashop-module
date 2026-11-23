<?php
declare(strict_types=1);

namespace Tests\Integration\Storefront;

use PrestaShop\Module\SmailyForPrestaShop\Config\EngineSettings;
use PrestaShop\Module\SmailyForPrestaShop\Hook\Dispatcher;
use PrestaShop\Module\SmailyForPrestaShop\Runtime\Services;
use PrestaShop\Module\SmailyForPrestaShop\Storefront\CartAttribution;
use PrestaShop\Module\SmailyForPrestaShop\Storefront\OrderAttribution;
use PrestaShop\Module\SmailyForPrestaShop\Storefront\Store;
use Tests\Integration\Automation\AbandonedCart\CartFixture;
use Tests\Integration\Engine\EngineTestCase;

require_once __DIR__ . '/../Engine/EngineTestCase.php';
require_once __DIR__ . '/../Automation/AbandonedCart/CartFixture.php';

/**
 * The base of the storefront's integration tests: a shop that is connected to the engine mock, a
 * storefront request with the cookies that the test gives it, and a cache in a folder of its own.
 * Everything a test changes is put back: the consent setting, the attribution rows, the context,
 * the request's cookies and the page's JavaScript definitions.
 */
abstract class StorefrontTestCase extends EngineTestCase
{
    protected const REC_ID = '3fa85f64-5717-4562-b3fc-2c963f66afa6';

    protected const STORE_TOKEN = 'vs_0123456789ABCDEFGHIJKL';

    protected CartFixture $fixture;

    protected string $cacheDir;

    private static ?\FrontController $page = null;

    /** @var array<string, mixed> */
    private array $savedContext = [];

    /** @var array<string, mixed> */
    private array $savedCookies = [];

    /** @var array<string, mixed> */
    private array $savedJsDef = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->fixture = new CartFixture();
        $context = \Context::getContext();
        $this->savedContext = ['controller' => $context->controller, 'customer' => $context->customer, 'cart' => $context->cart, 'container' => $context->container];
        if ($context->container === null) {
            // A storefront request builds PrestaShop's front container; PHPUnit does not.
            $context->container = \PrestaShop\PrestaShop\Adapter\ContainerBuilder::getContainer('front', false);
        }
        $this->savedCookies = $_COOKIE;
        $this->savedJsDef = \Media::getJsDef();
        \Configuration::deleteByName(EngineSettings::CONSENT_CONFIRMED);
        self::db()->delete(OrderAttribution::TABLE);
        self::db()->delete(CartAttribution::TABLE);
    }

    protected function tearDown(): void
    {
        if (defined('_PS_ROOT_DIR_')) {
            $this->fixture->remove();
            foreach ($this->savedContext as $name => $value) {
                \Context::getContext()->{$name} = $value;
            }
            $_COOKIE = $this->savedCookies;
            $property = new \ReflectionProperty(\Media::class, 'js_def');
            self::accessible($property);
            $property->setValue(null, $this->savedJsDef);
            \Configuration::deleteByName(EngineSettings::CONSENT_CONFIRMED);
            self::db()->delete(OrderAttribution::TABLE);
            self::db()->delete(CartAttribution::TABLE);
            foreach (glob($this->cacheDir . '/*') ?: [] as $file) {
                unlink($file);
            }
            if (is_dir($this->cacheDir)) {
                rmdir($this->cacheDir);
            }
        }
        parent::tearDown();
    }

    /**
     * A runtime whose every request goes to the engine mock, and whose cache is the test's own.
     */
    protected function runtime(): Services
    {
        if (!isset($this->cacheDir)) {
            $this->cacheDir = sys_get_temp_dir() . '/smaily-storefront-' . bin2hex(random_bytes(6));
        }
        $cacheDir = $this->cacheDir;
        $services = parent::runtime();
        $services->set(Store::class, static fn (): Store => new Store($cacheDir, 'time'));

        return $services;
    }

    protected function confirmConsent(bool $confirmed = true, int $idShop = 0): void
    {
        $this->services->get(EngineSettings::class)->setConsentConfirmed($confirmed, $idShop);
    }

    /**
     * Makes the request a shopper's own storefront request of the page $phpSelf with these
     * cookies. Its front controller records the scripts it is given.
     *
     * @param array<string, string> $cookies
     *
     * @return \FrontController&object{scripts: list<string>}
     */
    protected function storefrontRequest(array $cookies = [], string $phpSelf = 'index'): \FrontController
    {
        $controller = new class() extends \FrontController {
            /** @var list<string> */
            public array $scripts = [];

            public function __construct()
            {
            }

            public function registerJavascript($id, $relativePath, $params = [])
            {
                $this->scripts[] = $relativePath;
            }
        };
        $controller->php_self = $phpSelf;
        \Context::getContext()->controller = $controller;
        $_COOKIE = $cookies;

        return $controller;
    }

    /**
     * Makes the request a storefront request whose front controller PrestaShop has initialised and
     * given the variables that every page's templates read, so the theme's templates render.
     *
     * PrestaShop initialises a front controller once per process, so every test shares the one.
     *
     * @param array<string, string> $cookies
     */
    protected function themedRequest(array $cookies = []): \FrontController
    {
        $context = \Context::getContext();
        if (self::$page === null) {
            $buffers = ob_get_level();
            self::$page = new \FrontController();
            $context->controller = self::$page;
            self::$page->init();
            while (ob_get_level() > $buffers) {
                ob_end_clean();
            }
        }
        $context->controller = self::$page;
        if (!$context->cart instanceof \Cart) {
            $context->cart = new \Cart();
        }
        $context->customer = new \Customer();
        $assign = new \ReflectionMethod(self::$page, 'assignGeneralPurposeVariables');
        self::accessible($assign);
        $assign->invoke(self::$page);
        $_COOKIE = $cookies;

        return self::$page;
    }

    /**
     * Runs a hook through the dispatcher, as the main class does.
     *
     * @param array<string, mixed> $params
     *
     * @return mixed
     */
    protected function hook(string $name, array $params = [])
    {
        return (new Dispatcher(static fn (): bool => true, fn () => $this->services, [Dispatcher::class, 'logFailure']))->run($name, $params);
    }

    /**
     * @return list<array{id_order: int, rec_id: ?string, context: ?string, visitor_token: ?string}>
     */
    protected static function attributionRows(): array
    {
        $rows = self::db()->executeS('SELECT `id_order`, `rec_id`, `context`, `visitor_token` FROM `' . _DB_PREFIX_ . OrderAttribution::TABLE . '` ORDER BY `id_order`', true, false) ?: [];

        return array_map(static fn (array $row): array => ['id_order' => (int) $row['id_order']] + $row, $rows);
    }
}
