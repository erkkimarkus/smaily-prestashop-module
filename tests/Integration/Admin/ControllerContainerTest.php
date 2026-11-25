<?php
declare(strict_types=1);

namespace Tests\Integration\Admin;

use PrestaShop\Module\SmailyForPrestaShop\Admin\Api\AbstractJsonAction;
use PrestaShop\Module\SmailyForPrestaShop\Admin\Api\DiscountRules;
use PrestaShop\Module\SmailyForPrestaShop\Admin\Api\DismissInvitation;
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
use PrestaShop\Module\SmailyForPrestaShop\Admin\Log\PrivacyErase;
use PrestaShop\Module\SmailyForPrestaShop\Admin\Log\PrivacyExport;
use PrestaShop\Module\SmailyForPrestaShop\Admin\Log\SendAgain;
use PrestaShop\Module\SmailyForPrestaShop\Admin\Settings\SettingsController;
use PrestaShop\Module\SmailyForPrestaShop\Admin\Setup\SetupController;
use PrestaShop\Module\SmailyForPrestaShop\Admin\Setup\SetupScope;
use PrestaShop\Module\SmailyForPrestaShop\Config\AdminSettings;
use PrestaShop\Module\SmailyForPrestaShop\Config\MigrationNotices;
use PrestaShop\Module\SmailyForPrestaShop\Migration\MigrationPlan;
use PrestaShop\Module\SmailyForPrestaShop\Queue\EventQueue;
use PrestaShop\Module\SmailyForPrestaShop\Runtime\Services;
use PrestaShop\Module\SmailyForPrestaShop\Scope\StoreLayout;
use PrestaShop\Module\SmailyForPrestaShop\Smaily\Account;
use PrestaShop\Module\SmailyForPrestaShop\Smaily\AccountStore;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Tests\Integration\IntegrationTestCase;
use Tests\Integration\MultistoreShop;
use Twig\Environment;

require_once __DIR__ . '/../MultistoreShop.php';

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
    use MultistoreShop;

    private const SETUP_ROUTE = ['_legacy_controller' => 'AdminSmailyConnectSetup', '_route' => 'smailyforprestashop_api_setup_save'];

    /** An Automations save that is refused before anything is written or asked of Smaily. */
    private const CART_DELAY_TOO_SHORT = ['triggers' => ['abandoned_cart' => ['enabled' => true]], 'cart_delay' => '9'];

    /** PrestaShop's GDPR module, whose own screens run the same two privacy requests. */
    private const GDPR_MODULE = 'psgdpr';

    private ?string $setupState = null;

    /** The shop whose Smaily account a test stored for the steps after Connect, removed after it. */
    private ?int $storedAccountShop = null;

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
            \Cache::clean('Module::isEnabled' . self::GDPR_MODULE);
            self::db()->delete(EventQueue::TABLE);
            \Configuration::deleteByName(MigrationNotices::KEY);
            if ($this->storedAccountShop !== null) {
                Services::instance()->get(AccountStore::class)->delete($this->storedAccountShop, '');
            }
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
        self::assertSame(AbstractJsonAction::pageScope(), $this->rendered['smaily']['scope']);
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

    /**
     * A page refused for missing rights reads nothing that only the opened page shows: the
     * controller builds no service of the module before the refusal.
     *
     * @dataProvider pages
     *
     * @param class-string<BaseController> $class
     */
    public function testAPageRefusedForMissingRightsBuildsNoService(string $class, string $page, string $title): void
    {
        $services = Services::instance();
        $factories = new \ReflectionProperty(Services::class, 'factories');
        self::accessible($factories);
        $instances = new \ReflectionProperty(Services::class, 'instances');
        self::accessible($instances);
        $saved = [$factories->getValue($services), $instances->getValue($services)];
        $built = [];
        $recording = [];
        foreach ($saved[0] as $id => $factory) {
            $recording[$id] = static function (Services $services) use ($id, $factory, &$built): object {
                $built[] = $id;

                return $factory($services);
            };
        }
        $factories->setValue($services, $recording);
        $instances->setValue($services, []);
        try {
            $controller = new $class();
            $controller->setContainer($this->locator(false));
            $response = $controller->index(new Request());
        } finally {
            $factories->setValue($services, $saved[0]);
            $instances->setValue($services, $saved[1]);
        }

        self::assertSame(403, $response->getStatusCode());
        self::assertSame(['layoutTitle' => $title], $this->rendered);
        self::assertSame([], $built);
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
        $this->storeSetupAccount();
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

    /**
     * The invitation to Campaign Intelligence on top of the Dashboard and of the Settings tabs is
     * dismissed for 30 days, for every admin, apart from the invitation to a Smaily account, as
     * the Magento connector keeps the two.
     */
    public function testTheInvitationToCampaignIntelligenceIsDismissedForThirtyDaysOnItsOwn(): void
    {
        $settings = new AdminSettings();
        $route = ['_legacy_controller' => 'AdminSmailyConnectSettings', '_route' => 'smailyforprestashop_api_dismiss_invitation'];
        $keys = [AdminSettings::INVITATION_DISMISSED_AT, AdminSettings::INTELLIGENCE_INVITATION_DISMISSED_AT];
        array_map([\Configuration::class, 'deleteByName'], $keys);
        try {
            $now = time();
            self::assertFalse($settings->isIntelligenceInvitationDismissed($now));

            $response = $this->execute(new DismissInvitation(), $this->locator(), ['card' => 'intelligence'], $route);

            self::assertSame(['ok' => true, 'data' => []], json_decode((string) $response->getContent(), true));
            self::assertTrue($settings->isIntelligenceInvitationDismissed($now));
            self::assertFalse($settings->isInvitationDismissed($now), 'the invitation to a Smaily account still shows');
            self::assertTrue($settings->isIntelligenceInvitationDismissed($now + 30 * 86400 - 60));
            self::assertFalse($settings->isIntelligenceInvitationDismissed($now + 30 * 86400 + 60), 'after 30 days it shows again');
            self::assertSame(
                [['SMAILY_CONNECT_CI_INVITATION_DISMISSED_AT', null, null]],
                array_map('array_values', (array) self::db()->executeS('SELECT `name`, `id_shop_group`, `id_shop` FROM `' . _DB_PREFIX_ . 'configuration` WHERE `name` LIKE \'SMAILY\\_CONNECT\\_%INVITATION\\_DISMISSED\\_AT\'', true, false)),
                'one value for the whole installation'
            );

            $controller = new DashboardController();
            $controller->setContainer($this->locator());
            $controller->index(new Request());
            self::assertTrue($this->rendered['smaily']['intelligence_invitation_dismissed']);
            self::assertFalse($this->rendered['smaily']['invitation_dismissed']);
            self::assertFalse($this->rendered['smaily_intelligence_invitation'], 'the test shop has no Smaily account, so the other invitation is the one to show');

            $this->execute(new DismissInvitation(), $this->locator(), [], $route);
            self::assertTrue($settings->isInvitationDismissed(time()), 'without a card name, the invitation to a Smaily account');
        } finally {
            array_map([\Configuration::class, 'deleteByName'], $keys);
        }
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

        $body = ['id' => $id, 'ids' => [$id], 'email' => 'shopper1@example.test'];
        foreach ([LogDetails::class, SendAgain::class, MassRetry::class, PrivacyExport::class, PrivacyErase::class] as $action) {
            self::assertError(403, 'forbidden', 'You do not have permission to do this.', $this->execute(new $action(), $this->locator(false), $body, $route));
            self::assertError(403, 'invalid_token', 'The page has expired. Reload it and try again.', $this->execute(new $action(), $this->locator(true, false), $body, $route));
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

    public function testTheLogsPrivacyRequestFormAnswersInWords(): void
    {
        $route = ['_legacy_controller' => 'AdminSmailyConnectLog', '_route' => 'smailyforprestashop_api_log_privacy'];
        $kept = $this->queueRow(['entity_id' => 'shopper2@example.test', 'payload' => '{"email":"shopper2@example.test"}']);
        $sent = $this->queueRow();
        $waiting = $this->queueRow(['status' => 'pending']);

        foreach ([PrivacyExport::class, PrivacyErase::class] as $action) {
            self::assertError(400, 'invalid_email', 'Enter a valid email address.', $this->execute(new $action(), $this->locator(), ['email' => 'not an address'], $route));
            self::assertError(400, 'invalid_email', 'Enter a valid email address.', $this->execute(new $action(), $this->locator(), [], $route));
        }
        $answer = json_decode((string) $this->execute(new PrivacyExport(), $this->locator(), ['email' => ' Shopper1@Example.test '], $route)->getContent(), true);
        self::assertTrue($answer['ok']);
        self::assertMatchesRegularExpression('/^smaily-connect-privacy-export-\d{8}-\d{6}\.json$/', $answer['data']['filename'], 'the file\'s name does not hold the address');
        self::assertSame(['exported_at', 'email', 'store', 'campaign_intelligence'], array_keys($answer['data']['export']));
        self::assertSame('Shopper1@Example.test', $answer['data']['export']['email']);
        self::assertCount(2, $answer['data']['export']['store']['queue'], 'the rows of the address alone');
        self::assertSame('The export was saved as a file.', $answer['data']['message']);
        self::assertStringNotContainsString('shopper2', (string) json_encode($answer));

        $answer = json_decode((string) $this->execute(new PrivacyErase(), $this->locator(), ['email' => 'shopper1@example.test'], $route)->getContent(), true);
        self::assertTrue($answer['ok']);
        self::assertSame(
            'The data of this address was erased. Queued rows deleted: 1; Log rows cleared: 1; cart reminders closed: 0; discount codes removed: 0. The deletion in Campaign Intelligence is queued; connections: 0.',
            $answer['data']['message']
        );
        $rows = array_column((array) self::db()->executeS('SELECT `id`, `entity_id` FROM `' . _DB_PREFIX_ . EventQueue::TABLE . '`', true, false), 'entity_id', 'id');
        self::assertSame([$kept => 'shopper2@example.test', $sent => '[erased]'], $rows, 'the waiting row ' . $waiting . ' is gone, the delivered one cleared, the other shopper\'s untouched');
    }

    public function testTheLogsPrivacyRequestFormRunsBesideTheGdprModule(): void
    {
        $route = ['_legacy_controller' => 'AdminSmailyConnectLog', '_route' => 'smailyforprestashop_api_log_privacy'];
        $sent = $this->queueRow();

        // psgdpr 1.4.3 cannot show a customer in its admin screen on PrestaShop 9: the form is the export there.
        $this->gdprModule(true);
        $answer = json_decode((string) $this->execute(new PrivacyExport(), $this->locator(), ['email' => 'shopper1@example.test'], $route)->getContent(), true);
        self::assertTrue($answer['ok']);
        self::assertCount(1, $answer['data']['export']['store']['queue']);

        $answer = json_decode((string) $this->execute(new PrivacyErase(), $this->locator(), ['email' => 'shopper1@example.test'], $route)->getContent(), true);
        self::assertTrue($answer['ok']);
        self::assertSame('[erased]', self::db()->getValue('SELECT `entity_id` FROM `' . _DB_PREFIX_ . EventQueue::TABLE . '` WHERE `id` = ' . $sent));
    }

    public function testTheLogShowsThePrivacyRequestFormWithAndWithoutTheGdprModule(): void
    {
        foreach ([false, true] as $gdprModule) {
            $this->gdprModule($gdprModule);
            $controller = new LogController();
            $controller->setContainer($this->locator());

            $controller->index(new Request());

            self::assertTrue($this->rendered['smaily_log']['can_update']);
            self::assertArrayNotHasKey('privacy_form', $this->rendered['smaily_log'], 'the page has no switch that hides the form');
        }
        // The template shows the form to every employee who may change the Log.
        $template = (string) file_get_contents(__DIR__ . '/../../../src/views/templates/admin/log/list.html.twig');
        self::assertSame(1, preg_match('/\{% if ([^%]+) %\}\s*(?:\{#[^#]*#\}\s*)?<div class="smaily-card smaily-log__privacy"/', $template, $condition));
        self::assertSame('log.can_update', $condition[1]);
    }

    /**
     * The endpoints of the Settings page, the Initial setup and the Log, each with a request that
     * would write, call Smaily or read a shop's values: [action, body].
     *
     * @return array<string, array{class-string<AbstractJsonAction>, array<string, mixed>}>
     */
    public function scopedEndpoints(): array
    {
        $account = ['subdomain' => 'demo', 'username' => 'api-user', 'password' => 'not-a-real-password', 'language_mode' => 'single'];
        $contacts = ['mode' => 'all_customers', 'fields' => ['first_name'], 'guests' => true, 'enabled' => true];
        $automations = ['triggers' => ['welcome' => ['enabled' => true, 'workflow' => '']], 'cart_delay' => '30'];

        return [
            'Save Connection' => [SaveTab::class, ['tab' => 'connection', 'own' => true, 'data' => $account]],
            'Save Contacts' => [SaveTab::class, ['tab' => 'contacts', 'own' => true, 'data' => $contacts]],
            'Save Automations' => [SaveTab::class, ['tab' => 'automations', 'own' => true, 'data' => $automations]],
            'Save RSS' => [SaveTab::class, ['tab' => 'rss', 'data' => ['limit' => '7']]],
            'Test connection' => [TestConnection::class, $account],
            'the layout change' => [ChangeLayout::class, ['shape' => 'separate']],
            'Start import' => [ContactImportAction::class, ['action' => 'start']],
            'the import progress' => [ContactImportProgress::class, []],
            'the workflow lists' => [Workflows::class, ['account' => 'default', 'own' => true]],
            'the discount pickers' => [DiscountRules::class, ['own' => true]],
            'the setup layout step' => [SaveStep::class, ['step' => 'shape', 'data' => ['shape' => 'one']]],
            'the setup Connect step' => [SaveStep::class, ['step' => 'connect', 'data' => $account]],
            'the setup Contacts step' => [SaveStep::class, ['step' => 'contacts', 'data' => $contacts]],
            'the setup Automations step' => [SaveStep::class, ['step' => 'automations', 'data' => $automations]],
            'the setup Finish' => [SaveStep::class, ['step' => 'finish']],
            'the Log retry of every row' => [MassRetry::class, ['all' => true, 'filter' => []]],
            'the Log privacy export' => [PrivacyExport::class, ['email' => 'shopper1@example.test']],
            'the Log privacy erasure' => [PrivacyErase::class, ['email' => 'shopper1@example.test']],
        ];
    }

    /**
     * The back office keeps the selected shop in one cookie: another tab changes it under an open
     * page. A request then runs for another selection than its page shows, in either direction.
     *
     * @dataProvider scopedEndpoints
     *
     * @param class-string<AbstractJsonAction> $action
     * @param array<string, mixed> $body
     */
    public function testARequestOfAPageDrawnForAnotherShopSelectionIsRefusedAndWritesNothing(string $action, array $body): void
    {
        $this->queueRow(['status' => 'failed', 'attempts' => 5, 'last_error' => 'x']);
        $route = ['_legacy_controller' => 'AdminSmailyConnectSettings', '_route' => 'smailyforprestashop_api_settings_save'];
        $message = 'The shop selected at the top of the page has changed since this page was opened. Reload the page and try again.';
        $this->openMultistore();
        try {
            $shop = $this->addShop($this->addShopGroup(false));
            $before = $this->stored();

            // The page was drawn for the shop; the selection is "All shops" now.
            \Shop::setContext(\Shop::CONTEXT_ALL);
            self::assertSame('all', AbstractJsonAction::pageScope());
            self::assertError(409, 'scope_changed', $message, $this->execute(new $action(), $this->locator(), $body, $route, 'shop-' . $shop));
            // The page was drawn for "All shops"; the selection is the shop now.
            \Shop::setContext(\Shop::CONTEXT_SHOP, $shop);
            self::assertSame('shop-' . $shop, AbstractJsonAction::pageScope());
            self::assertError(409, 'scope_changed', $message, $this->execute(new $action(), $this->locator(), $body, $route, 'all'));
            // A page of another shop, of the shop's group, and a request that names no selection.
            self::assertError(409, 'scope_changed', $message, $this->execute(new $action(), $this->locator(), $body, $route, 'shop-' . $this->defaultShopId()));
            self::assertError(409, 'scope_changed', $message, $this->execute(new $action(), $this->locator(), $body, $route, 'group-' . (int) \Shop::getContextShopGroupID()));
            self::assertError(409, 'scope_changed', $message, $this->execute(new $action(), $this->locator(), $body, $route, ''));

            self::assertSame($before, $this->stored(), 'a refused request changes nothing');
        } finally {
            $this->closeMultistore();
        }
    }

    public function testThePageScopeNamesTheSelectedShopGroupOrAllShops(): void
    {
        self::assertSame('all', AbstractJsonAction::pageScope(), 'a store without multishop has one selection');
        $this->openMultistore();
        try {
            $group = $this->addShopGroup(false);
            $shop = $this->addShop($group);

            \Shop::setContext(\Shop::CONTEXT_SHOP, $shop);
            self::assertSame(['shop-' . $shop, $shop], [AbstractJsonAction::pageScope(), AbstractJsonAction::shopId()]);
            \Shop::setContext(\Shop::CONTEXT_GROUP, $group);
            self::assertSame(['group-' . $group, 0], [AbstractJsonAction::pageScope(), AbstractJsonAction::shopId()]);
            \Shop::setContext(\Shop::CONTEXT_ALL);
            self::assertSame(['all', 0], [AbstractJsonAction::pageScope(), AbstractJsonAction::shopId()]);

            // The matching selection passes the check: the request reaches its own validation.
            \Shop::setContext(\Shop::CONTEXT_SHOP, $shop);
            $response = $this->execute(new SaveTab(), $this->locator(), ['tab' => 'no-such-tab'], ['_legacy_controller' => 'AdminSmailyConnectSettings', '_route' => 'smailyforprestashop_api_settings_save'], 'shop-' . $shop);
            self::assertError(400, 'unknown_tab', 'Saving failed.', $response);
        } finally {
            $this->closeMultistore();
        }
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
     * Plays a store with or without PrestaShop's GDPR module enabled: Module::isEnabled() answers
     * from PrestaShop's cache, which tearDown() empties again.
     */
    private function gdprModule(bool $enabled): void
    {
        \Cache::store('Module::isEnabled' . self::GDPR_MODULE, $enabled);
    }

    /**
     * A Smaily account of the setup's scope, which the steps after Connect save only with; none is
     * asked of Smaily here.
     */
    private function storeSetupAccount(): void
    {
        $target = (int) SetupScope::current(new StoreLayout(), AbstractJsonAction::shopId())->targetShop();
        $accounts = Services::instance()->get(AccountStore::class);
        if ($accounts->find($target, '') === null) {
            $accounts->save(new Account($target, '', 'example', 'api-user-example', 'not-a-real-password'));
            $this->storedAccountShop = $target;
        }
    }

    /**
     * The container as PrestaShop 8.2.8 hands it to a controller: a locator of the services that
     * AbstractController subscribes to, of which the pages and endpoints use these three.
     */
    private function locator(bool $granted = true, bool $validToken = true): ServiceLocator
    {
        $twig = $this->createMock(Environment::class);
        $twig->method('render')->willReturnCallback(function ($view, array $parameters = []): string {
            $this->rendered = $parameters;

            return '';
        });

        return $this->adminLocator($granted, $validToken, ['twig' => static fn () => $twig]);
    }

    /**
     * @param array<string, mixed> $body
     * @param array<string, string> $route
     * @param string|null $scope the shop selection the page was drawn for; null: the current one
     */
    private function execute(AbstractJsonAction $action, ServiceLocator $locator, array $body, array $route = self::SETUP_ROUTE, ?string $scope = null): JsonResponse
    {
        return $this->adminRequest($action, $body, $route, $locator, $scope);
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

    /**
     * Everything a request of the module's pages can change: its configuration rows, accounts,
     * workflow rows, jobs and queue rows.
     *
     * @return array<string, list<array<string, mixed>>>
     */
    private function stored(): array
    {
        $stored = ['configuration' => (array) self::db()->executeS(
            'SELECT `id_shop_group`, `id_shop`, `name`, `value` FROM `' . _DB_PREFIX_ . 'configuration` WHERE `name` LIKE \'SMAILY\\_%\' ORDER BY `id_configuration`',
            true,
            false
        )];
        foreach (['smaily_connect_account', 'smaily_connect_automation_mapping', 'smaily_connect_job', EventQueue::TABLE] as $table) {
            $stored[$table] = (array) self::db()->executeS('SELECT * FROM `' . _DB_PREFIX_ . $table . '`', true, false);
        }

        return $stored;
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
