<?php
declare(strict_types=1);

namespace Tests\Integration\Automation\AbandonedCart\Capture;

use PrestaShop\Module\SmailyForPrestaShop\Automation\AbandonedCart\Capture\CheckoutScript;
use PrestaShop\Module\SmailyForPrestaShop\Automation\Trigger;
use PrestaShop\Module\SmailyForPrestaShop\Config\AutomationSettings;
use PrestaShop\Module\SmailyForPrestaShop\Hook\Dispatcher;
use Tests\Integration\Automation\AbandonedCart\AbandonedCartTestCase;

require_once __DIR__ . '/../AbandonedCartTestCase.php';

/**
 * The capture script is on a guest's checkout page while the abandoned-cart automation is on, and
 * nowhere else.
 */
final class CheckoutScriptTest extends AbandonedCartTestCase
{
    /** @var mixed */
    private $controller;

    /** @var mixed */
    private $customer;

    /** @var array<string, mixed> */
    private array $jsDef;

    protected function setUp(): void
    {
        parent::setUp();
        $this->controller = \Context::getContext()->controller;
        $this->customer = \Context::getContext()->customer;
        $this->jsDef = \Media::getJsDef();
    }

    protected function tearDown(): void
    {
        if (defined('_PS_ROOT_DIR_')) {
            \Context::getContext()->controller = $this->controller;
            \Context::getContext()->customer = $this->customer;
            $property = new \ReflectionProperty(\Media::class, 'js_def');
            $property->setAccessible(true);
            $property->setValue(null, $this->jsDef);
        }
        parent::tearDown();
    }

    public function testAGuestsCheckoutGetsTheScriptTheEndpointAndTheToken(): void
    {
        $controller = $this->page('order');
        \Context::getContext()->customer = new \Customer();

        $this->setMedia();

        self::assertSame([[CheckoutScript::SCRIPT_ID, 'modules/smailyforprestashop/views/js/front/checkout-capture.js']], $controller->scripts);
        $definition = \Media::getJsDef()[CheckoutScript::JS_DEF];
        self::assertSame(\Tools::getToken(false), $definition['token']);
        self::assertSame(\Context::getContext()->link->getModuleLink('smailyforprestashop', 'capture'), $definition['url']);
    }

    /**
     * @return array<string, array{string}>
     */
    public function withoutScript(): array
    {
        return ['another page' => ['another page'], 'automation off' => ['automation off'], 'customer in the context' => ['customer in the context']];
    }

    /**
     * @dataProvider withoutScript
     */
    public function testNoScriptOutsideAGuestsCheckoutWithTheAutomationOn(string $case): void
    {
        $controller = $this->page($case === 'another page' ? 'index' : 'order');
        \Context::getContext()->customer = new \Customer();
        if ($case === 'automation off') {
            (new AutomationSettings())->setEnabled(Trigger::ABANDONED_CART, false, 0);
        }
        if ($case === 'customer in the context') {
            \Context::getContext()->customer = new \Customer($this->fixture->customer('capture5@example.test'));
        }

        $this->setMedia();

        self::assertSame([], $controller->scripts);
        self::assertArrayNotHasKey(CheckoutScript::JS_DEF, \Media::getJsDef());
    }

    /**
     * Runs actionFrontControllerSetMedia through the dispatcher, as the main class does.
     */
    private function setMedia(): void
    {
        (new Dispatcher(static fn (): bool => true, fn () => $this->services, [Dispatcher::class, 'logFailure']))->run('actionFrontControllerSetMedia', []);
    }

    /**
     * A front controller of the page $phpSelf that records the scripts it is given.
     *
     * @return \FrontController&object{scripts: list<array{string, string}>}
     */
    private function page(string $phpSelf): \FrontController
    {
        $controller = new class() extends \FrontController {
            /** @var list<array{string, string}> */
            public array $scripts = [];

            public function __construct()
            {
            }

            public function registerJavascript($id, $relativePath, $params = [])
            {
                $this->scripts[] = [$id, $relativePath];
            }
        };
        $controller->php_self = $phpSelf;
        \Context::getContext()->controller = $controller;

        return $controller;
    }
}
