<?php
declare(strict_types=1);

namespace Tests\Unit\Report;

use PHPUnit\Framework\TestCase;
use PrestaShop\Module\SmailyForPrestaShop\Report\Credentials;
use PrestaShop\Module\SmailyForPrestaShop\Report\Report;
use PrestaShop\Module\SmailyForPrestaShop\Report\ReportBuilder;
use PrestaShop\Module\SmailyForPrestaShop\Scope\StoreLayout;

require_once __DIR__ . '/ReportFixture.php';
require_once __DIR__ . '/../../Integration/Fixtures/mock-subscription/contract.php';

/**
 * The installation report's body: one connection per shop with a connected Smaily account (per
 * language in mode `a`), the shop id as `website`, the main account, the features, the health and
 * the engine store. Every body is held against the contract's rules, and holds no secret.
 */
final class ReportBuilderTest extends TestCase
{
    private const API_KEY = 'sk_not_a_real_key';

    private ReportFixture $store;

    protected function setUp(): void
    {
        $this->store = new ReportFixture();
    }

    public function testOneShopWithOneAccountIsOneConnection(): void
    {
        $this->store->accounts = [ReportFixture::account(0, '', 'example')];
        $this->store->contacts = [0 => ReportFixture::syncOn()];
        $this->store->health->recordSmaily('example', '', true);

        $report = $this->build(ReportBuilder::EVENT_SNAPSHOT, ReportBuilder::TRIGGER_DAILY);

        self::assertSame([
            'product' => 'connect_prestashop',
            'product_version' => '3.0.0',
            'platforms' => [['name' => 'prestashop', 'version' => '8.2.8']],
            'php_version' => PHP_VERSION,
            'installation_url' => 'https://example-store.test',
            'smaily_subdomain' => 'example',
            'event' => 'snapshot',
            'trigger' => 'daily',
            'sent_at' => '2025-11-20T07:00:00Z',
            'connections' => [[
                'role' => 'marketing',
                'store_url' => 'https://example-store.test',
                'website' => '1',
                'smaily_subdomain' => 'example',
                'features' => ['subscriber_sync'],
                'smaily' => ['status' => 'ok', 'last_sync_at' => '2025-11-20T07:00:00Z', 'last_error_code' => null],
            ]],
        ], $report->body());
        self::assertSame('Basic ' . base64_encode('api-user-example:' . ReportFixture::PASSWORD), $report->credentials()->authorization());
    }

    public function testNothingIsBuiltWithoutAConnectedAccount(): void
    {
        self::assertNull($this->store->builder()->build(ReportBuilder::EVENT_SNAPSHOT, ReportBuilder::TRIGGER_DAILY), 'no account');
        self::assertNull($this->store->builder()->mainCredentials());

        foreach ([
            'a password that does not decrypt' => ReportFixture::account(0, '', 'example', ''),
            'a subdomain outside the contract\'s rule' => ReportFixture::account(0, '', 'exa_mple'),
            'a subdomain longer than a DNS label' => ReportFixture::account(0, '', str_repeat('a', 64)),
        ] as $case => $account) {
            $this->store->accounts = [$account];

            self::assertNull($this->store->builder()->build(ReportBuilder::EVENT_SNAPSHOT, ReportBuilder::TRIGGER_DAILY), $case);
            self::assertNull($this->store->builder()->build(ReportBuilder::EVENT_DEACTIVATED), $case);
            self::assertSame([], $this->store->builder()->accountKeys(), $case);
        }
    }

    public function testNothingIsBuiltWithoutAnAddressOrAVersion(): void
    {
        $this->store->accounts = [ReportFixture::account(0, '', 'example')];

        $this->store->shops = [1 => 'ftp://example-store.test/'];
        self::assertNull($this->store->builder()->build(ReportBuilder::EVENT_SNAPSHOT, ReportBuilder::TRIGGER_DAILY));

        $this->store->shops = [1 => 'https://example-store.test/'];
        $this->store->moduleVersion = '';
        self::assertNull($this->store->builder()->build(ReportBuilder::EVENT_SNAPSHOT, ReportBuilder::TRIGGER_DAILY));
    }

    public function testAShopWithItsOwnAccountIsItsOwnConnectionWithItsShopAndItsAddress(): void
    {
        $this->store->shops = [1 => 'https://example-store.test/', 2 => 'https://second-example-store.test/shop/', 3 => 'https://third-example-store.test'];
        $this->store->accounts = [ReportFixture::account(0, '', 'example'), ReportFixture::account(2, '', 'example-second')];
        $this->store->contacts = [0 => ReportFixture::syncOn(), 2 => ['SMAILY_CONNECT_CONTACTS_ENABLED' => '0']];

        $report = $this->build(ReportBuilder::EVENT_SNAPSHOT, ReportBuilder::TRIGGER_SETUP_SAVED);

        self::assertSame('example', $report->body()['smaily_subdomain'], 'the account set up for all shops is the main account');
        self::assertSame('trigger', array_keys($report->body())[7]);
        self::assertSame([
            ['1', 'https://example-store.test', 'example', ['subscriber_sync']],
            ['2', 'https://second-example-store.test/shop', 'example-second', []],
            ['3', 'https://third-example-store.test', 'example', ['subscriber_sync']],
        ], array_map(
            static fn (array $connection): array => [$connection['website'], $connection['store_url'], $connection['smaily_subdomain'], $connection['features']],
            $report->body()['connections']
        ));
        self::assertSame(['example', 'example-second'], $this->store->builder()->accountKeys());
    }

    public function testAShopWithoutAnAccountIsLeftOutAndSeparateStoresHaveNoSharedMainAccount(): void
    {
        $this->store->layout = [0 => [StoreLayout::SEPARATE_STORES => '1']];
        $this->store->shops = [1 => 'https://example-store.test', 2 => 'https://second-example-store.test', 3 => 'https://third-example-store.test'];
        // A row of the shared scope that separate stores never read.
        $this->store->accounts = [ReportFixture::account(0, '', 'leftover'), ReportFixture::account(2, '', 'example-second'), ReportFixture::account(3, '', 'example-third')];

        $report = $this->build(ReportBuilder::EVENT_SNAPSHOT, ReportBuilder::TRIGGER_DAILY);

        self::assertSame(['2', '3'], array_column($report->body()['connections'], 'website'));
        self::assertSame('example-second', $report->body()['smaily_subdomain'], 'the first connection authenticates');
        self::assertSame('Basic ' . base64_encode('api-user-example-second:' . ReportFixture::PASSWORD), $report->credentials()->authorization());
    }

    public function testTheSharedAccountIsNotTheMainAccountWhenNoShopUsesIt(): void
    {
        $this->store->shops = [1 => 'https://example-store.test'];
        $this->store->accounts = [ReportFixture::account(0, '', 'example'), ReportFixture::account(1, '', 'example-own')];

        self::assertSame('example-own', $this->build(ReportBuilder::EVENT_SNAPSHOT, ReportBuilder::TRIGGER_DAILY)->body()['smaily_subdomain']);
    }

    public function testAnAccountPerLanguageGivesOneConnectionPerLanguage(): void
    {
        $this->store->layout = [0 => [StoreLayout::SEPARATE_STORES => '0', StoreLayout::LANGUAGE_MODE => 'a', StoreLayout::FALLBACK_LANGUAGE => 'en']];
        $this->store->languages = [1 => ['en', 'et', 'fi', 'qqq']];
        $this->store->accounts = [
            ReportFixture::account(0, '', 'example'),
            ReportFixture::account(0, 'en', 'example'),
            ReportFixture::account(0, 'et', 'example-et'),
        ];
        $this->store->workflows = [
            'welcome|1|en' => $this->store->accounts[1],
            'welcome|1|et' => $this->store->accounts[1],
            'abandoned_cart|1|et' => $this->store->accounts[2],
        ];

        $report = $this->build(ReportBuilder::EVENT_SNAPSHOT, ReportBuilder::TRIGGER_DAILY);

        self::assertSame([
            ['en', 'example', ['welcome_automation']],
            ['et', 'example-et', ['abandoned_cart']],
            ['fi', 'example', []],
        ], array_map(
            static fn (array $connection): array => [$connection['language'], $connection['smaily_subdomain'], $connection['features']],
            $report->body()['connections']
        ), 'a language without an account goes to the fallback account; a workflow of another account is not this connection\'s');
        self::assertSame(['language', 'features'], array_slice(array_keys($report->body()['connections'][0]), 4, 2));
    }

    public function testAnAutomationIsAFeatureOnlyWithAWorkflowOfThisAccount(): void
    {
        $this->store->shops = [1 => 'https://example-store.test', 2 => 'https://second-example-store.test'];
        $this->store->languages = [2 => ['en', 'et']];
        $this->store->layout[2] = [StoreLayout::LANGUAGE_MODE => 'b'];
        $this->store->accounts = [ReportFixture::account(0, '', 'example')];
        $this->store->workflows = [
            'welcome|1|' => $this->store->accounts[0],
            'first_order|1|' => $this->store->accounts[0],
            'abandoned_cart|1|' => ReportFixture::account(0, '', 'another'),
            // The shop with a workflow per language holds none of no language.
            'abandoned_cart|2|et' => $this->store->accounts[0],
        ];

        $connections = $this->build(ReportBuilder::EVENT_SNAPSHOT, ReportBuilder::TRIGGER_DAILY)->body()['connections'];

        self::assertSame(['welcome_automation', 'first_order_automation'], $connections[0]['features']);
        self::assertSame(['abandoned_cart'], $connections[1]['features']);
    }

    public function testTheEngineStoreOfAShopIsItsIntelligenceBlock(): void
    {
        $this->store->shops = [1 => 'https://example-store.test', 2 => 'https://second-example-store.test'];
        $this->store->accounts = [ReportFixture::account(0, '', 'example')];
        $connection = ReportFixture::connection(7, 0, '2c9d7e41-5f3a-4b86-9d02-7a1e6c4f8b35', self::API_KEY);
        $this->store->engine = [1 => $connection, 2 => $connection];
        $this->store->consent = [0 => true];
        $this->store->health->recordEngine('2c9d7e41-5f3a-4b86-9d02-7a1e6c4f8b35', '', true);

        $connections = $this->build(ReportBuilder::EVENT_SNAPSHOT, ReportBuilder::TRIGGER_DAILY)->body()['connections'];

        foreach ($connections as $reported) {
            self::assertSame(['campaign_intelligence', 'browse_tracking'], $reported['features']);
            self::assertSame([
                'tenant_id' => '2c9d7e41-5f3a-4b86-9d02-7a1e6c4f8b35',
                'connected' => true,
                'status' => 'ok',
                'last_sync_at' => '2025-11-20T07:00:00Z',
                'last_error_code' => null,
            ], $reported['intelligence']);
        }
    }

    public function testARefusedOrUnreadableEngineConnectionIsNotConnectedAndCarriesItsErrorCode(): void
    {
        $this->store->shops = [1 => 'https://example-store.test', 2 => 'https://second-example-store.test'];
        $this->store->layout = [0 => [StoreLayout::SEPARATE_STORES => '1']];
        $this->store->accounts = [ReportFixture::account(1, '', 'example'), ReportFixture::account(2, '', 'example-second')];
        $this->store->engine = [
            1 => ReportFixture::connection(7, 1, 'tenant-refused', self::API_KEY, ['refused_at' => '2025-11-20T06:00:00Z']),
            2 => ReportFixture::connection(8, 2, 'tenant-unreadable', ''),
        ];
        $this->store->health->recordEngine('tenant-refused', 'tenant_inactive', false);

        $connections = $this->build(ReportBuilder::EVENT_SNAPSHOT, ReportBuilder::TRIGGER_DAILY)->body()['connections'];

        self::assertSame(['tenant_id' => 'tenant-refused', 'connected' => false, 'status' => 'failing', 'last_sync_at' => null, 'last_error_code' => 'tenant_inactive'], $connections[0]['intelligence']);
        self::assertSame(['campaign_intelligence'], $connections[0]['features'], 'browse tracking waits for the merchant\'s confirmation');
        self::assertFalse($connections[1]['intelligence']['connected']);
    }

    public function testAFailingSmailyAccountCarriesItsCodeAndNoText(): void
    {
        $this->store->accounts = [ReportFixture::account(0, '', 'example')];
        $this->store->health->recordSmaily('example', 'Smaily said: contact shopper@example.test is invalid', false);

        $report = $this->build(ReportBuilder::EVENT_SNAPSHOT, ReportBuilder::TRIGGER_DAILY);

        self::assertSame(['status' => 'failing', 'last_sync_at' => null, 'last_error_code' => 'unknown_error'], $report->body()['connections'][0]['smaily']);
    }

    public function testADisconnectedEventLeavesTheRemovedEngineStoreOut(): void
    {
        $this->store->accounts = [ReportFixture::account(0, '', 'example')];
        $this->store->engine = [1 => ReportFixture::connection(7, 0, 'tenant-1')];

        $report = $this->build(ReportBuilder::EVENT_DISCONNECTED, '', [7]);

        self::assertSame('disconnected', $report->body()['event']);
        self::assertArrayNotHasKey('trigger', $report->body());
        self::assertCount(1, $report->body()['connections']);
        self::assertArrayNotHasKey('intelligence', $report->body()['connections'][0]);
        self::assertSame([], $report->body()['connections'][0]['features']);
    }

    public function testADisconnectedEventOfTheLastAccountCarriesNoConnectionAndThePreviousMainAccount(): void
    {
        $previous = new Credentials('example', 'api-user-example', ReportFixture::PASSWORD);

        self::assertNull($this->store->builder()->build(ReportBuilder::EVENT_DISCONNECTED), 'nothing to authenticate with');
        self::assertNull($this->store->builder()->build(ReportBuilder::EVENT_DEACTIVATED, '', [], $previous), 'only a disconnect is sent without an account');

        $report = $this->build(ReportBuilder::EVENT_DISCONNECTED, '', [], $previous);

        self::assertSame('example', $report->body()['smaily_subdomain']);
        self::assertSame([], $report->body()['connections']);
        self::assertSame($previous, $report->credentials());
    }

    public function testADeactivatedEventCarriesTheConnectionsAsTheyAre(): void
    {
        $this->store->accounts = [ReportFixture::account(0, '', 'example')];
        $this->store->engine = [1 => ReportFixture::connection(7, 0, 'tenant-1')];

        $report = $this->build(ReportBuilder::EVENT_DEACTIVATED);

        self::assertSame('deactivated', $report->body()['event']);
        self::assertArrayNotHasKey('trigger', $report->body());
        self::assertSame('tenant-1', $report->body()['connections'][0]['intelligence']['tenant_id']);
    }

    public function testAReportHoldsAtMostAHundredConnections(): void
    {
        $this->store->shops = [];
        for ($idShop = 1; $idShop <= 120; ++$idShop) {
            $this->store->shops[$idShop] = 'https://shop-' . $idShop . '.example-store.test';
        }
        $this->store->accounts = [ReportFixture::account(0, '', 'example')];

        self::assertCount(100, $this->build(ReportBuilder::EVENT_SNAPSHOT, ReportBuilder::TRIGGER_DAILY)->body()['connections']);
    }

    /**
     * Builds a report that must exist, and holds its body against the contract's rules and
     * against the secrets of the fixture.
     *
     * @param list<int> $removedConnections
     */
    private function build(string $event, string $trigger = '', array $removedConnections = [], ?Credentials $previousMain = null): Report
    {
        $report = $this->store->builder()->build($event, $trigger, $removedConnections, $previousMain);
        self::assertNotNull($report);

        self::assertSame([], installation_report_errors($report->body()), 'the body follows the contract');
        self::assertSame([], installation_report_unknown_fields($report->body()), 'the body holds only the contract\'s fields');
        $json = (string) json_encode($report->body());
        foreach ([ReportFixture::PASSWORD, 'api-user', self::API_KEY, 'shopper@'] as $secret) {
            self::assertStringNotContainsString($secret, $json);
        }

        return $report;
    }
}
