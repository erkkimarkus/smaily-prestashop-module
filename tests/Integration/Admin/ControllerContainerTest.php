<?php
declare(strict_types=1);

namespace Tests\Integration\Admin;

use PrestaShop\Module\SmailyForPrestaShop\Admin\Api\AbstractJsonAction;
use PrestaShop\Module\SmailyForPrestaShop\Admin\Api\Settings\ChangeLayout;
use PrestaShop\Module\SmailyForPrestaShop\Admin\Api\Settings\SaveTab;
use PrestaShop\Module\SmailyForPrestaShop\Admin\Api\Settings\TestConnection;
use PrestaShop\Module\SmailyForPrestaShop\Admin\Api\Setup\ContactImportAction;
use PrestaShop\Module\SmailyForPrestaShop\Admin\Api\Setup\ContactImportProgress;
use PrestaShop\Module\SmailyForPrestaShop\Admin\Api\Setup\SaveStep;
use PrestaShop\Module\SmailyForPrestaShop\Admin\BaseController;
use PrestaShop\Module\SmailyForPrestaShop\Admin\Dashboard\DashboardController;
use PrestaShop\Module\SmailyForPrestaShop\Admin\Log\LogController;
use PrestaShop\Module\SmailyForPrestaShop\Admin\Settings\SettingsController;
use PrestaShop\Module\SmailyForPrestaShop\Admin\Setup\SetupController;
use PrestaShop\Module\SmailyForPrestaShop\Config\AdminSettings;
use PrestaShop\Module\SmailyForPrestaShop\Config\MigrationNotices;
use PrestaShop\Module\SmailyForPrestaShop\Migration\MigrationPlan;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Tests\Integration\IntegrationTestCase;
use Twig\Environment;

/**
 * On PrestaShop 8.2.8 the container of an admin controller is a service locator that holds what
 * Symfony's AbstractController subscribes to and no "translator" (facts file 6.6); every page and
 * every JSON error answered HTTP 500 there. These tests give each controller such a locator, on
 * whichever PrestaShop the suite runs: every page renders with its title and its notices, every
 * JSON error path answers with its worded error, and so does each endpoint of the Settings page.
 *
 * Twig, the authorization checker and the CSRF token manager are stand-ins; the browser check of
 * the pages covers the real ones.
 */
final class ControllerContainerTest extends IntegrationTestCase
{
    private const SETUP_ROUTE = ['_legacy_controller' => 'AdminSmailyConnectSetup', '_route' => 'smailyforprestashop_api_setup_save'];

    private ?string $setupState = null;

    /** @var array<string, mixed> what the page handed to Twig */
    private array $rendered = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->setupState = (new AdminSettings())->setupState();
        // A finished setup: no page is sent to the Initial setup, which would need the router.
        (new AdminSettings())->setSetupState(AdminSettings::SETUP_DONE);
        \Configuration::deleteByName(MigrationNotices::KEY);
        $this->rendered = [];
    }

    protected function tearDown(): void
    {
        if (defined('_PS_ROOT_DIR_')) {
            \Configuration::deleteByName(MigrationNotices::KEY);
            if ($this->setupState === null) {
                \Configuration::deleteByName(AdminSettings::SETUP_STATE);
            } else {
                (new AdminSettings())->setSetupState($this->setupState);
            }
        }
        parent::tearDown();
    }

    /**
     * @return array<string, array{class-string<BaseController>, string, string}>
     */
    public function pages(): array
    {
        return [
            'Dashboard' => [DashboardController::class, 'dashboard', 'Smaily Connect — Dashboard'],
            'Initial setup' => [SetupController::class, 'setup', 'Smaily Connect — Initial setup'],
            'Settings' => [SettingsController::class, 'settings', 'Smaily Connect — Settings'],
            'Log' => [LogController::class, 'log', 'Smaily Connect — Log'],
        ];
    }

    /**
     * @dataProvider pages
     *
     * @param class-string<BaseController> $class
     */
    public function testAPageRendersWithItsTitleAndNotices(string $class, string $page, string $title): void
    {
        (new MigrationNotices())->add(MigrationPlan::NOTICE_SETTINGS_MIGRATED);
        $controller = new $class();
        $controller->setContainer($this->locator());

        $response = $controller->index(new Request());

        self::assertSame(200, $response->getStatusCode());
        self::assertSame($title, $this->rendered['layoutTitle']);
        self::assertSame($page, $this->rendered['smaily']['page']);
        self::assertSame('page-token', $this->rendered['smaily']['token']);
        self::assertCount(1, $this->rendered['smaily']['notices']);
        self::assertStringStartsWith('Your settings from the previous version', $this->rendered['smaily']['notices'][0]['text']);
    }

    public function testAPageRefusedForMissingRightsHasItsTitle(): void
    {
        $controller = new SettingsController();
        $controller->setContainer($this->locator(false));

        $response = $controller->index(new Request());

        self::assertSame(403, $response->getStatusCode());
        self::assertSame(['layoutTitle' => 'Smaily Connect — Settings'], $this->rendered);
    }

    public function testARequestWithoutTheRightIsRefusedInWords(): void
    {
        $response = $this->execute(new SaveStep(), $this->locator(false), ['step' => 'contacts']);

        self::assertError(403, 'forbidden', 'You do not have permission to do this.', $response);
    }

    public function testARequestWithAStalePageTokenIsRefusedInWords(): void
    {
        $response = $this->execute(new SaveStep(), $this->locator(true, false), ['step' => 'contacts']);

        self::assertError(403, 'invalid_token', 'The page has expired. Reload it and try again.', $response);
    }

    public function testAnUnknownStepIsRefusedInWords(): void
    {
        $response = $this->execute(new SaveStep(), $this->locator(), ['step' => 'no-such-step']);

        self::assertError(400, 'unknown_step', 'Saving failed.', $response);
    }

    public function testARefusedSaveNamesItsFieldInWords(): void
    {
        $response = $this->execute(new SaveStep(), $this->locator(), ['step' => 'contacts', 'data' => []]);

        $answer = json_decode((string) $response->getContent(), true);
        self::assertSame(422, $response->getStatusCode());
        self::assertSame('invalid_fields', $answer['error']['code']);
        self::assertNotSame('', $answer['error']['message']);
        self::assertSame($answer['error']['message'], $answer['error']['fields'][0]['message']);
    }

    /**
     * The Settings page's endpoints, each with a request that changes nothing and calls no Smaily:
     * [action, route, body, HTTP status, error code or null, error message or null].
     *
     * @return array<string, array{class-string<AbstractJsonAction>, string, array<string, mixed>, int, string|null, string|null}>
     */
    public function settingsEndpoints(): array
    {
        return [
            'save' => [SaveTab::class, 'smailyforprestashop_api_settings_save', ['tab' => 'no-such-tab'], 400, 'unknown_tab', 'Saving failed.'],
            'test-connection' => [TestConnection::class, 'smailyforprestashop_api_settings_test', [], 422, 'invalid_fields', 'Please fill in the subdomain, username and password.'],
            'layout' => [ChangeLayout::class, 'smailyforprestashop_api_settings_layout', ['shape' => 'no-such-shape'], 422, 'invalid_fields', 'Pick how your shops are run.'],
            'contact-import' => [ContactImportAction::class, 'smailyforprestashop_api_settings_import', ['action' => 'no-such-action'], 400, 'unknown_action', 'Saving failed.'],
            'contact-import-progress' => [ContactImportProgress::class, 'smailyforprestashop_api_settings_import_progress', [], 200, null, null],
        ];
    }

    /**
     * @dataProvider settingsEndpoints
     *
     * @param class-string<AbstractJsonAction> $action
     * @param array<string, mixed> $body
     */
    public function testASettingsEndpointAnswersInWords(string $action, string $route, array $body, int $status, ?string $code, ?string $message): void
    {
        $response = $this->execute(new $action(), $this->locator(), $body, ['_legacy_controller' => 'AdminSmailyConnectSettings', '_route' => $route]);

        $answer = json_decode((string) $response->getContent(), true);
        self::assertSame($status, $response->getStatusCode());
        self::assertSame($code === null, $answer['ok']);
        self::assertSame($code, $answer['error']['code'] ?? null);
        self::assertSame($message, $answer['error']['message'] ?? null);
    }

    public function testAnEndpointThatThrowsAnswersInWords(): void
    {
        $failing = new class extends AbstractJsonAction {
            protected function handle(Request $request, int $idShop)
            {
                throw new \LogicException('injected');
            }
        };

        $response = $this->execute($failing, $this->locator(), []);

        self::assertError(500, 'internal_error', 'Something went wrong. Try again; if it keeps failing, see the PrestaShop logs.', $response);
        self::db()->execute('DELETE FROM `' . _DB_PREFIX_ . 'log` WHERE `message` LIKE \'Smaily Connect: the admin endpoint smailyforprestashop_api_setup_save failed with LogicException%\'');
    }

    /**
     * The container as PrestaShop 8.2.8 hands it to a controller: a locator of the services that
     * AbstractController subscribes to, of which the pages and endpoints use these three.
     */
    private function locator(bool $granted = true, bool $validToken = true): ServiceLocator
    {
        $checker = $this->createMock(AuthorizationCheckerInterface::class);
        $checker->method('isGranted')->willReturn($granted);
        $tokens = $this->createMock(CsrfTokenManagerInterface::class);
        $tokens->method('getToken')->willReturn(new CsrfToken(BaseController::API_TOKEN_ID, 'page-token'));
        $tokens->method('isTokenValid')->willReturn($validToken);
        $twig = $this->createMock(Environment::class);
        $twig->method('render')->willReturnCallback(function ($view, array $parameters = []): string {
            $this->rendered = $parameters;

            return '';
        });

        return new ServiceLocator([
            'security.authorization_checker' => static fn () => $checker,
            'security.csrf.token_manager' => static fn () => $tokens,
            'twig' => static fn () => $twig,
        ]);
    }

    /**
     * @param array<string, mixed> $body
     * @param array<string, string> $route
     */
    private function execute(AbstractJsonAction $action, ServiceLocator $locator, array $body, array $route = self::SETUP_ROUTE): JsonResponse
    {
        $action->setContainer($locator);
        $request = new Request([], [], $route, [], [], [], (string) json_encode($body));
        $request->headers->set('X-Smaily-Token', 'page-token');

        return $action->execute($request);
    }

    private static function assertError(int $status, string $code, string $message, JsonResponse $response): void
    {
        self::assertSame($status, $response->getStatusCode());
        self::assertSame(
            ['ok' => false, 'error' => ['code' => $code, 'message' => $message]],
            json_decode((string) $response->getContent(), true)
        );
    }
}
