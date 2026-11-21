<?php
declare(strict_types=1);

namespace Tests\Integration\Admin;

use PrestaShop\Module\SmailyForPrestaShop\Admin\Api\AbstractJsonAction;
use PrestaShop\Module\SmailyForPrestaShop\Admin\Api\DiscountRules;
use PrestaShop\Module\SmailyForPrestaShop\Admin\Api\Settings\ChangeLayout;
use PrestaShop\Module\SmailyForPrestaShop\Admin\Api\Settings\SaveTab;
use PrestaShop\Module\SmailyForPrestaShop\Admin\Api\Settings\TestConnection;
use PrestaShop\Module\SmailyForPrestaShop\Admin\Api\Setup\ContactImportAction;
use PrestaShop\Module\SmailyForPrestaShop\Admin\Api\Setup\ContactImportProgress;
use PrestaShop\Module\SmailyForPrestaShop\Admin\Api\Setup\SaveStep;
use PrestaShop\Module\SmailyForPrestaShop\Admin\Api\Workflows;
use PrestaShop\Module\SmailyForPrestaShop\Admin\BaseController;
use PrestaShop\Module\SmailyForPrestaShop\Admin\Dashboard\DashboardController;
use PrestaShop\Module\SmailyForPrestaShop\Admin\Log\Details as LogDetails;
use PrestaShop\Module\SmailyForPrestaShop\Admin\Log\LogController;
use PrestaShop\Module\SmailyForPrestaShop\Admin\Log\MassRetry;
use PrestaShop\Module\SmailyForPrestaShop\Admin\Log\SendAgain;
use PrestaShop\Module\SmailyForPrestaShop\Admin\Settings\SettingsController;
use PrestaShop\Module\SmailyForPrestaShop\Admin\Setup\SetupController;
use PrestaShop\Module\SmailyForPrestaShop\Config\AdminSettings;
use PrestaShop\Module\SmailyForPrestaShop\Config\MigrationNotices;
use PrestaShop\Module\SmailyForPrestaShop\Migration\MigrationPlan;
use PrestaShop\Module\SmailyForPrestaShop\Queue\EventQueue;
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
 * JSON error path answers with its worded error, and so does each endpoint of the Settings page
 * and of the Log.
 *
 * Twig, the authorization checker and the CSRF token manager are stand-ins; the browser check of
 * the pages covers the real ones.
 */
final class ControllerContainerTest extends IntegrationTestCase
{
    private const SETUP_ROUTE = ['_legacy_controller' => 'AdminSmailyConnectSetup', '_route' => 'smailyforprestashop_api_setup_save'];

    /** An Automations save that is refused before anything is written or asked of Smaily. */
    private const CART_DELAY_TOO_SHORT = ['triggers' => ['abandoned_cart' => ['enabled' => true]], 'cart_delay' => '9'];

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
        self::db()->delete(EventQueue::TABLE);
        $this->rendered = [];
    }

    protected function tearDown(): void
    {
        if (defined('_PS_ROOT_DIR_')) {
            self::db()->delete(EventQueue::TABLE);
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
            'save automations' => [SaveTab::class, 'smailyforprestashop_api_settings_save', ['tab' => 'automations', 'data' => self::CART_DELAY_TOO_SHORT], 422, 'invalid_fields', 'The abandoned-cart wait must be from 10 to 1435 minutes. A longer wait would send no reminder.'],
            'workflows' => [Workflows::class, 'smailyforprestashop_api_settings_workflows', ['account' => 'no such account'], 200, null, null],
            'discount-rules' => [DiscountRules::class, 'smailyforprestashop_api_settings_discount_rules', [], 200, null, null],
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

    public function testTheSetupsAutomationsStepAndItsWorkflowListAnswerInWords(): void
    {
        $response = $this->execute(new SaveStep(), $this->locator(), ['step' => 'automations', 'data' => self::CART_DELAY_TOO_SHORT]);
        $answer = json_decode((string) $response->getContent(), true);
        self::assertSame(422, $response->getStatusCode());
        self::assertSame([['field' => 'cart_delay', 'message' => 'The abandoned-cart wait must be from 10 to 1435 minutes. A longer wait would send no reminder.']], $answer['error']['fields']);

        $response = $this->execute(new Workflows(), $this->locator(), ['account' => 'no such account'], ['_legacy_controller' => 'AdminSmailyConnectSetup', '_route' => 'smailyforprestashop_api_setup_workflows']);
        self::assertSame(['ok' => true, 'data' => ['workflows' => [], 'error' => null]], json_decode((string) $response->getContent(), true));

        $response = $this->execute(new DiscountRules(), $this->locator(), [], ['_legacy_controller' => 'AdminSmailyConnectSetup', '_route' => 'smailyforprestashop_api_setup_discount_rules']);
        $answer = json_decode((string) $response->getContent(), true);
        self::assertSame([true, ['rules']], [$answer['ok'], array_keys($answer['data'])]);
        self::assertIsArray($answer['data']['rules']);
    }

    /**
     * @return array<string, array{class-string<AbstractJsonAction>, array<string, mixed>}>
     */
    public function automationEndpoints(): array
    {
        return [
            'Save Automations' => [SaveTab::class, ['tab' => 'automations', 'data' => self::CART_DELAY_TOO_SHORT]],
            'the workflow lists' => [Workflows::class, ['account' => 'default']],
            'the discount pickers' => [DiscountRules::class, []],
        ];
    }

    /**
     * @dataProvider automationEndpoints
     *
     * @param class-string<AbstractJsonAction> $action
     * @param array<string, mixed> $body
     */
    public function testAnAutomationsEndpointRefusesAMissingRightAndAStalePageToken(string $action, array $body): void
    {
        $route = ['_legacy_controller' => 'AdminSmailyConnectSettings', '_route' => 'smailyforprestashop_api_settings_save'];

        self::assertError(403, 'forbidden', 'You do not have permission to do this.', $this->execute(new $action(), $this->locator(false), $body, $route));
        self::assertError(403, 'invalid_token', 'The page has expired. Reload it and try again.', $this->execute(new $action(), $this->locator(true, false), $body, $route));
    }

    public function testTheLogHandsItsFilteredListToTwig(): void
    {
        $failed = $this->queueRow(['status' => 'failed', 'attempts' => 5, 'last_error' => 'Smaily API request failed with HTTP 500']);
        $this->queueRow(['entity_id' => 'shopper2@example.test']);
        $controller = new LogController();
        $controller->setContainer($this->locator());

        $response = $controller->index(new Request(['status' => 'failed', '_token' => 'url-token', 'page' => '7']));

        self::assertSame(200, $response->getStatusCode());
        $log = $this->rendered['smaily_log'];
        self::assertSame(
            [['status' => 'failed'], 'url-token', 1, 1, 1, 1, true],
            [$log['filter'], $log['token'], $log['total'], $log['page'], $log['pages'], $log['failed'], $log['can_update']]
        );
        self::assertSame(
            ['smaily-' . $failed, 'Smaily', 'shopper1@example.test', 'Failed', 'Smaily API request failed with HTTP 500', true],
            [$log['rows'][0]['log_id'], $log['rows'][0]['source'], $log['rows'][0]['entity'], $log['rows'][0]['status']['label'], $log['rows'][0]['error'], $log['rows'][0]['resend']]
        );
        self::assertSame(['Pending', 'Sending', 'Sent', 'Failed', 'Withdrawn', 'Skipped', 'Queued', 'Running', 'Done', 'Cancelled'], array_column($log['statuses'], 'label'));
        self::assertSame(['Smaily', 'Campaign Intelligence'], array_column($log['sources'], 'label'));
    }

    public function testTheLogWithoutRightsHandsNothingToTwig(): void
    {
        $this->queueRow();
        $controller = new LogController();
        $controller->setContainer($this->locator(false));

        $response = $controller->index(new Request());

        self::assertSame(403, $response->getStatusCode());
        self::assertSame(['layoutTitle' => 'Smaily Connect — Log'], $this->rendered);
    }

    /**
     * The Log's endpoints: [action, body, HTTP status, error code or null, error or answer message].
     * `:sent` and `:failed` stand for the log ids of a delivered and a failed row.
     *
     * @return array<string, array{class-string<AbstractJsonAction>, array<string, mixed>, int, string|null, string|null}>
     */
    public function logEndpoints(): array
    {
        $gone = 'This log row no longer exists — it has likely been pruned by retention.';

        return [
            'details of a row that is gone' => [LogDetails::class, ['id' => 'smaily-999999999'], 404, 'not_found', $gone],
            'details of no log id' => [LogDetails::class, ['id' => '1 OR 1=1'], 404, 'not_found', $gone],
            'details of a row' => [LogDetails::class, ['id' => ':failed'], 200, null, null],
            'send again of a row that is gone' => [SendAgain::class, [], 404, 'not_found', $gone],
            'send again of a delivered row' => [SendAgain::class, ['id' => ':sent'], 409, 'refused', 'Only a failed event can be sent again.'],
            'send again of a failed row' => [SendAgain::class, ['id' => ':failed'], 200, null, 'Event queued to be sent again.'],
            'retry of nothing' => [MassRetry::class, [], 200, null, '0 event(s) queued for retry.'],
            'retry of a delivered row' => [MassRetry::class, ['ids' => [':sent', 'job-1', 'x']], 200, null, '0 event(s) queued for retry.'],
            'retry of the failed rows' => [MassRetry::class, ['all' => true, 'filter' => ['status' => 'failed']], 200, null, '1 event(s) queued for retry.'],
        ];
    }

    /**
     * @dataProvider logEndpoints
     *
     * @param class-string<AbstractJsonAction> $action
     * @param array<string, mixed> $body
     */
    public function testALogEndpointAnswersInWords(string $action, array $body, int $status, ?string $code, ?string $message): void
    {
        $ids = [
            ':failed' => 'smaily-' . $this->queueRow(['status' => 'failed', 'attempts' => 5, 'last_error' => 'Smaily API request failed with HTTP 500']),
            ':sent' => 'smaily-' . $this->queueRow(['entity_id' => 'shopper2@example.test']),
        ];
        array_walk_recursive($body, static function (&$value) use ($ids): void {
            $value = $ids[$value] ?? $value;
        });

        $response = $this->execute(new $action(), $this->locator(), $body, ['_legacy_controller' => 'AdminSmailyConnectLog', '_route' => 'smailyforprestashop_api_log']);

        $answer = json_decode((string) $response->getContent(), true);
        self::assertSame($status, $response->getStatusCode());
        self::assertSame($code, $answer['error']['code'] ?? null);
        self::assertSame($message, $answer['error']['message'] ?? $answer['data']['message'] ?? null);
        if ($action === LogDetails::class && $status === 200) {
            self::assertSame(['html' => ''], $answer['data'], 'the panel is what Twig rendered');
            self::assertSame(['Event #' . substr($ids[':failed'], 7), true], [$this->rendered['details']['title'], $this->rendered['details']['resend']]);
        }
    }

    public function testALogEndpointRefusesAMissingRightAndAStalePageToken(): void
    {
        $id = 'smaily-' . $this->queueRow(['status' => 'failed', 'attempts' => 5, 'last_error' => 'x']);
        $route = ['_legacy_controller' => 'AdminSmailyConnectLog', '_route' => 'smailyforprestashop_api_log'];

        foreach ([LogDetails::class, SendAgain::class, MassRetry::class] as $action) {
            self::assertError(403, 'forbidden', 'You do not have permission to do this.', $this->execute(new $action(), $this->locator(false), ['id' => $id, 'ids' => [$id]], $route));
            self::assertError(403, 'invalid_token', 'The page has expired. Reload it and try again.', $this->execute(new $action(), $this->locator(true, false), ['id' => $id, 'ids' => [$id]], $route));
        }
        self::assertSame(
            [['failed', '5']],
            array_map(
                static fn (array $row): array => array_map('strval', array_values($row)),
                self::db()->executeS('SELECT `status`, `attempts` FROM `' . _DB_PREFIX_ . EventQueue::TABLE . '`', true, false)
            ),
            'a refused request changes nothing'
        );
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

    /**
     * An event-queue row of the default shop, last changed now; delivered unless $columns say else.
     *
     * @param array<string, mixed> $columns
     */
    private function queueRow(array $columns = []): int
    {
        $now = gmdate('Y-m-d H:i:s');
        self::db()->insert(EventQueue::TABLE, $columns + [
            'id_shop' => (int) \Configuration::get('PS_SHOP_DEFAULT'),
            'event_type' => 'contact.sync',
            'entity_id' => 'shopper1@example.test',
            'payload' => '{"email":"shopper1@example.test"}',
            'status' => 'sent',
            'attempts' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return (int) self::db()->Insert_ID();
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
