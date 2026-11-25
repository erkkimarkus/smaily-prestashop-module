<?php
declare(strict_types=1);

namespace Tests\Integration;

use PHPUnit\Framework\TestCase;
use PrestaShop\Module\SmailyForPrestaShop\Admin\Api\AbstractJsonAction;
use PrestaShop\Module\SmailyForPrestaShop\Admin\BaseController;
use PrestaShop\Module\SmailyForPrestaShop\Engine\Payload\StoreCurrency;
use PrestaShop\Module\SmailyForPrestaShop\Runtime\Services;
use PrestaShop\Module\SmailyForPrestaShop\Storefront\ActiveConnection;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

/**
 * The base of every test that runs inside the docker shop against its real database, with the
 * module installed. tests/bootstrap.php boots the shop when PS_ROOT_DIR is set; without a shop the
 * test is skipped. Each test seeds the state it needs and puts back what it changed.
 */
abstract class IntegrationTestCase extends TestCase
{
    /** The shop's own runtime while a test's stands in for it. */
    private ?Services $shopRuntime = null;

    private bool $runtimeSwapped = false;

    protected function setUp(): void
    {
        if (!defined('_PS_ROOT_DIR_')) {
            self::markTestSkipped('Needs a PrestaShop shop: set PS_ROOT_DIR to its root (see tests/ci/validate.sh).');
        }
        // The storefront reads a shop's connection, and the payloads a shop's currency, once in a
        // request. A test is a request of its own, and tests write the connection table past
        // Engine\Connections.
        ActiveConnection::reset();
        StoreCurrency::reset();
    }

    /**
     * Opens a private or protected property or method to the test. PHP 8.0 needs the call; from
     * PHP 8.1 reflection reads such a member without it, and PHP 8.5 deprecates the call.
     *
     * @param \ReflectionProperty|\ReflectionMethod $member
     */
    protected static function accessible($member): void
    {
        if (PHP_VERSION_ID < 80100) {
            $member->setAccessible(true);
        }
    }

    /**
     * Makes $services the shop's own runtime, so that the module's hooks, its uninstall and its
     * admin endpoints run on them; restoreShopRuntime() in tearDown() puts the shop's own back.
     */
    protected function useAsShopRuntime(Services $services): void
    {
        if (!$this->runtimeSwapped) {
            $this->shopRuntime = self::shopRuntime()->getValue();
            $this->runtimeSwapped = true;
        }
        self::shopRuntime()->setValue(null, $services);
    }

    protected function restoreShopRuntime(): void
    {
        if ($this->runtimeSwapped) {
            self::shopRuntime()->setValue(null, $this->shopRuntime);
            $this->runtimeSwapped = false;
        }
    }

    private static function shopRuntime(): \ReflectionProperty
    {
        $property = new \ReflectionProperty(Services::class, 'instance');
        self::accessible($property);

        return $property;
    }

    /**
     * The container that an admin endpoint or page takes its services from, as PrestaShop 8.2.8
     * hands it to a controller: the employee's right on the route's tab ($granted) and the page's
     * CSRF token ($validToken), and $services besides.
     *
     * @param array<string, callable(): object> $services
     */
    protected function adminLocator(bool $granted = true, bool $validToken = true, array $services = []): ServiceLocator
    {
        $checker = $this->createMock(AuthorizationCheckerInterface::class);
        $checker->method('isGranted')->willReturn($granted);
        $tokens = $this->createMock(CsrfTokenManagerInterface::class);
        $tokens->method('getToken')->willReturn(new CsrfToken(BaseController::API_TOKEN_ID, 'page-token'));
        $tokens->method('isTokenValid')->willReturn($validToken);

        return new ServiceLocator([
            'security.authorization_checker' => static fn () => $checker,
            'security.csrf.token_manager' => static fn () => $tokens,
        ] + $services);
    }

    /**
     * Executes an admin JSON endpoint as the page's script calls it: the page's token in
     * X-Smaily-Token and the shop selection the page was drawn for in X-Smaily-Scope (CLAUDE.md,
     * "The selected shop is one cookie").
     *
     * @param array<string, mixed> $body
     * @param array<string, string> $route the request's `_legacy_controller` and `_route`
     * @param ServiceLocator|null $locator null: adminLocator(), the right and a valid token
     * @param string|null $scope the selection the page was drawn for; null: the current one
     */
    protected function adminRequest(AbstractJsonAction $action, array $body, array $route, ?ServiceLocator $locator = null, ?string $scope = null): JsonResponse
    {
        $action->setContainer($locator ?? $this->adminLocator());
        $request = new Request([], [], $route, [], [], [], (string) json_encode($body));
        $request->headers->set('X-Smaily-Token', 'page-token');
        $request->headers->set('X-Smaily-Scope', $scope ?? AbstractJsonAction::pageScope());

        return $action->execute($request);
    }

    protected static function db(): \Db
    {
        return \Db::getInstance();
    }

    protected static function module(): \SmailyForPrestaShop
    {
        $module = \Module::getInstanceByName('smailyforprestashop');
        self::assertInstanceOf(\SmailyForPrestaShop::class, $module, 'The module is installed.');

        return $module;
    }
}
