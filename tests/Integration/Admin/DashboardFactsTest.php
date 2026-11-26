<?php
declare(strict_types=1);

namespace Tests\Integration\Admin;

use PrestaShop\Module\SmailyForPrestaShop\Admin\Dashboard\BackgroundTasks;
use PrestaShop\Module\SmailyForPrestaShop\Admin\Dashboard\DashboardFacts;
use PrestaShop\Module\SmailyForPrestaShop\Admin\Dashboard\DashboardQueries;
use PrestaShop\Module\SmailyForPrestaShop\Admin\Dashboard\EngineFacts;
use PrestaShop\Module\SmailyForPrestaShop\Automation\AbandonedCart\AbandonedCartHandler;
use PrestaShop\Module\SmailyForPrestaShop\Config\AdminSettings;
use PrestaShop\Module\SmailyForPrestaShop\Config\ContactsSettings;
use PrestaShop\Module\SmailyForPrestaShop\Config\EngineSettings;
use PrestaShop\Module\SmailyForPrestaShop\Config\ReportSettings;
use PrestaShop\Module\SmailyForPrestaShop\Config\TickSettings;
use PrestaShop\Module\SmailyForPrestaShop\Contacts\Import\ContactImport;
use PrestaShop\Module\SmailyForPrestaShop\Contacts\Import\JobStore;
use PrestaShop\Module\SmailyForPrestaShop\Engine\Client;
use PrestaShop\Module\SmailyForPrestaShop\Engine\Connection;
use PrestaShop\Module\SmailyForPrestaShop\Engine\Connections;
use PrestaShop\Module\SmailyForPrestaShop\Engine\History\HistoryJobs;
use PrestaShop\Module\SmailyForPrestaShop\Engine\Ingest\CatalogManifest;
use PrestaShop\Module\SmailyForPrestaShop\Engine\Ingest\IngestQueue;
use PrestaShop\Module\SmailyForPrestaShop\Engine\Payload\CatalogPayloadBuilder;
use PrestaShop\Module\SmailyForPrestaShop\Queue\EventQueue;
use PrestaShop\Module\SmailyForPrestaShop\Report\ConnectionHealth;
use PrestaShop\Module\SmailyForPrestaShop\Runtime\Services;
use PrestaShop\Module\SmailyForPrestaShop\Scope\AccountResolver;
use PrestaShop\Module\SmailyForPrestaShop\Scope\StoreLayout;
use PrestaShop\Module\SmailyForPrestaShop\Smaily\Account;
use PrestaShop\Module\SmailyForPrestaShop\Smaily\AccountStore;
use Tests\Integration\IntegrationTestCase;

/**
 * The Dashboard reads its facts from stored state only: the counts of the tiles over the windows
 * Magento uses, the newest queue rows and import jobs with each entity as stored, and the Smaily
 * account of the selected shop. While the upgrade is not finished it reads no table of the module.
 * A render costs a small, fixed number of queries.
 */
final class DashboardFactsTest extends IntegrationTestCase
{
    private const DAY = 86400;

    private const OTHER_SHOP = 990;

    private int $now;

    private EventQueue $queue;

    protected function setUp(): void
    {
        parent::setUp();
        $this->now = time();
        $this->queue = new EventQueue(fn (): int => $this->now);
        $this->clean();
    }

    protected function tearDown(): void
    {
        if (defined('_PS_ROOT_DIR_')) {
            $this->clean();
        }
        parent::tearDown();
    }

    public function testTheTilesCountOverMagentosWindows(): void
    {
        $this->row('contact.sync', 'sent', 1);
        $this->row('contact.sync', 'sent', 29);
        $this->row('contact.sync', 'sent', 31);
        $this->row('automation.welcome', 'sent', 1);
        $this->row('contact.sync', 'skipped', 1);
        $this->row('contact.sync', 'failed', 0, 2 * 3600);
        $this->row('contact.sync', 'failed', 2);
        $this->row('contact.sync', 'pending', 0);
        $this->row('automation.welcome', 'pending', 0);
        $this->row('contact.sync', 'pending', 2);
        $this->row('contact.sync', 'processing', 0);

        $facts = $this->collect();

        self::assertSame(2, $facts['contact_syncs'], 'every contact sync delivered in 30 days, as Magento counts them');
        self::assertSame(2, $facts['queued_today']);
        self::assertSame(1, $facts['failed_24h']);
    }

    public function testQueuedTodayAndFailedCountTheRowsThatGoToCampaignIntelligenceToo(): void
    {
        $connection = $this->connection(0);
        $this->row('contact.sync', 'pending', 0);
        $this->row('contact.sync', 'failed', 0, 3600);
        $this->ingestRow($connection, 'catalog', '412', 'pending', 0);
        $this->ingestRow($connection, 'orders', '77', 'pending', 0);
        $this->ingestRow($connection, 'orders', '78', 'pending', 2);
        $this->ingestRow($connection, 'catalog', '413', 'processing', 0);
        $this->ingestRow($connection, 'catalog', '414', 'sent', 0);
        $this->ingestRow($connection, 'customers', '9', 'failed', 0, 2 * 3600);
        $this->ingestRow($connection, 'customers', '10', 'failed', 2);
        $this->ingestRow($connection, 'catalog_manifest', '2026-11-22', 'failed', 0, 3 * 3600);

        $facts = $this->collect();

        self::assertSame(3, $facts['queued_today'], 'one row for Smaily and two for Campaign Intelligence');
        self::assertSame(3, $facts['failed_24h'], 'one row for Smaily, one customer and one nightly list for Campaign Intelligence');
    }

    public function testRecentActivityListsTheNewestRowsAndJobsWithTheEntityAsStored(): void
    {
        for ($i = 1; $i <= 10; ++$i) {
            $this->row('contact.sync', 'sent', 0, 100 - $i, 'shopper' . $i . '@example.test');
        }
        $this->row('contact.sync', 'sent', 0, 89, str_repeat('ab12', 16));
        (new JobStore())->create(ContactImport::TYPE_IMPORT, 0, JobStore::STATUS_RUNNING, ['total' => 3]);

        $activity = $this->collect()['activity'];

        self::assertCount(10, $activity);
        self::assertSame(['kind' => 'job', 'source' => 'smaily', 'type' => 'contacts_import', 'total' => 3, 'status' => 'running'], array_diff_key($activity[0], ['updated_at' => 0]));
        self::assertSame('smaily', $activity[1]['source']);
        self::assertSame('contact.sync', $activity[1]['type']);
        self::assertSame('ab12ab12ab12…', $activity[1]['entity'], 'the keyed hash of a long address, cut');
        self::assertSame('shopper10@example.test', $activity[2]['entity'], 'an address as stored');
        self::assertGreaterThanOrEqual($activity[3]['updated_at'], $activity[2]['updated_at']);
    }

    public function testRecentActivityShowsAQueueRowsStatusAsTheLogShowsIt(): void
    {
        $withdrawn = $this->row(AbandonedCartHandler::EVENT_TYPE, 'skipped', 0, 30);
        self::db()->execute(sprintf(
            'UPDATE `%ssmaily_connect_event_queue` SET `last_error` = \'%s\' WHERE `id` = %d',
            _DB_PREFIX_,
            pSQL(AbandonedCartHandler::SKIPPED_ORDERED),
            $withdrawn
        ));
        $this->row(AbandonedCartHandler::EVENT_TYPE, 'skipped', 0, 20);
        $this->row('contact.sync', 'processing', 0, 10);

        self::assertSame(['sending', 'skipped', 'withdrawn'], array_column($this->collect()['activity'], 'status'));
    }

    public function testRecentActivityNamesCampaignIntelligenceForWhatGoesToTheEngine(): void
    {
        $connection = $this->connection(0);
        $this->row('contact.sync', 'sent', 0, 50);
        $this->row('engine.identity_merge', 'sent', 0, 40);
        $this->ingestRow($connection, 'catalog', '412', 'sent', 0, 30);
        $this->ingestRow($connection, 'orders', '77', 'failed', 0, 20);
        (new HistoryJobs(fn (): int => $this->now))->start($connection, HistoryJobs::CATALOG);
        self::db()->execute('UPDATE `' . _DB_PREFIX_ . 'smaily_connect_job` SET `counts` = \'{"total":19}\' WHERE `type` = \'engine_catalog\'');

        $activity = array_map(
            static fn (array $row): array => [$row['source'], $row['kind'], $row['type'], $row['entity'] ?? $row['total'], $row['status']],
            $this->collect()['activity']
        );

        self::assertSame([
            ['intelligence', 'job', 'engine_catalog', 19, 'queued'],
            ['intelligence', 'queue', 'orders', '77', 'failed'],
            ['intelligence', 'queue', 'catalog', '412', 'sent'],
            ['intelligence', 'queue', 'engine.identity_merge', 'shopper1@example.test', 'sent'],
            ['smaily', 'queue', 'contact.sync', 'shopper1@example.test', 'sent'],
        ], $activity);
    }

    public function testWithoutAConnectionNothingIsSaidOfCampaignIntelligence(): void
    {
        self::assertSame(
            ['per_shop' => false, 'connected' => false, 'name' => '', 'state' => '', 'unreachable' => false, 'consent' => false, 'catalog_items' => null, 'unsent_nights' => 0],
            $this->collect()['engine']
        );
        self::assertNull($this->collect('pending')['engine'], 'the connection table is not read during the upgrade');
    }

    /**
     * Each state of a stored connection: what its last health check left in its settings, then
     * the state and whether the engine is unreachable.
     *
     * @return array<string, array{array<string, mixed>, string, bool}>
     */
    public function connectionStates(): array
    {
        $refused = static fn (string $error): array => ['refused_at' => '2026-11-20T10:00:00Z', 'refused_error' => $error, 'health' => ['status' => 'refused', 'error' => $error]];

        return [
            'never checked' => [[], 'connected', false],
            'the engine answered' => [['health' => ['status' => 'connected']], 'connected', false],
            'the engine did not answer' => [['health' => ['status' => 'unreachable', 'error' => 'network_error']], 'connected', true],
            'the account is not active' => [$refused('tenant_inactive'), 'not_active', false],
            'the key is refused' => [$refused('unauthorised'), 'key_refused', false],
            'a newer module is needed' => [$refused('plugin_update_required'), 'update_required', false],
            'refused, and then no answer' => [['health' => ['status' => 'unreachable', 'error' => 'network_error']] + $refused('tenant_inactive'), 'not_active', false],
        ];
    }

    /**
     * @dataProvider connectionStates
     *
     * @param array<string, mixed> $settings
     */
    public function testTheConnectionsStateIsWhatItsLastHealthCheckFound(array $settings, string $state, bool $unreachable): void
    {
        $this->connection(0, $settings + ['tenant_name' => 'Demo Store']);

        $engine = $this->collect()['engine'];

        self::assertSame([true, 'Demo Store', $state, $unreachable], [$engine['connected'], $engine['name'], $engine['state'], $engine['unreachable']]);
    }

    public function testTheCatalogItemsCountWhatReachedTheEngineInThirtyDays(): void
    {
        $connection = $this->connection(0);
        $other = $this->connection(self::OTHER_SHOP);
        $this->ingestRow($connection, 'catalog', '1', 'sent', 1);
        $this->ingestRow($connection, 'catalog', '2', 'sent', 29);
        $this->ingestRow($connection, 'catalog', '3', 'sent', 31);
        $this->ingestRow($connection, 'catalog', '4', 'failed', 1);
        $this->ingestRow($connection, 'catalog', '5', 'pending', 0);
        $this->ingestRow($connection, 'customers', '6', 'sent', 1);
        $this->ingestRow($other, 'catalog', '7', 'sent', 1);

        self::assertSame(['count' => 2, 'more' => false], $this->collect()['engine']['catalog_items'], 'the rows of the connection that shows');
    }

    public function testTheMerchantsConfirmationIsReadForTheSelectedSetup(): void
    {
        $this->connection(0);
        self::assertFalse($this->collect()['engine']['consent']);

        (new EngineSettings())->setConsentConfirmed(true, 0);

        self::assertTrue($this->collect()['engine']['consent']);
    }

    public function testAllShopsOfSeparateStoresShowNoOneConnectionAndCountThemAll(): void
    {
        \Configuration::updateGlobalValue(StoreLayout::SEPARATE_STORES, '1');
        $first = $this->connection(1, ['tenant_name' => 'Demo Store']);
        $second = $this->connection(self::OTHER_SHOP);
        $this->ingestRow($first, 'catalog', '1', 'sent', 1);
        $this->ingestRow($second, 'catalog', '2', 'sent', 1);

        $all = $this->collect(null, 0, true)['engine'];
        self::assertSame([true, false, '', ['count' => 2, 'more' => false]], [$all['per_shop'], $all['connected'], $all['name'], $all['catalog_items']]);

        $one = $this->collect(null, 1, true)['engine'];
        self::assertSame([false, true, 'Demo Store', ['count' => 1, 'more' => false]], [$one['per_shop'], $one['connected'], $one['name'], $one['catalog_items']]);

        self::assertFalse($this->collect(null, 1, false)['engine']['connected'], 'with multishop off the one store reads the connection of all shops');
    }

    public function testNoNightlyProductListIsCountedInNights(): void
    {
        $connection = $this->connection(0, ['issued_at' => gmdate('c', $this->now - 5 * self::DAY)]);
        self::assertSame(5, $this->collect()['engine']['unsent_nights'], 'since the connection was made');

        $this->ingestRow($connection, 'catalog_manifest', '', 'failed', 1);
        self::assertSame(5, $this->collect()['engine']['unsent_nights'], 'a list that the engine did not take does not count');

        $this->ingestRow($connection, 'catalog_manifest', '', 'sent', 2);
        self::assertSame(2, $this->collect()['engine']['unsent_nights'], 'since the newest list that the engine took');

        $this->ingestRow($connection, 'catalog_manifest', '', 'sent', 0, 3600);
        self::assertSame(0, $this->collect()['engine']['unsent_nights']);
    }

    public function testAConnectionThatSendsNoListByItsOwnRuleCountsNoNights(): void
    {
        $old = ['issued_at' => gmdate('c', $this->now - 5 * self::DAY)];
        $this->connection(0, $old + ['refused_at' => '2026-11-20T10:00:00Z', 'refused_error' => 'tenant_inactive']);
        self::assertSame(0, $this->collect()['engine']['unsent_nights'], 'a refused connection');

        self::db()->delete('smaily_connect_connection');
        $this->connection(0, $old);
        $this->connection(self::OTHER_SHOP, $old);
        self::assertSame(0, $this->collect()['engine']['unsent_nights'], 'two connections in one engine store');

        self::db()->delete('smaily_connect_connection');
        $this->connection(0, []);
        self::assertSame(0, $this->collect()['engine']['unsent_nights'], 'no list yet, and the engine named no time');
    }

    public function testTheSmailyCardReadsTheSelectedShopsAccount(): void
    {
        $facts = $this->collect();
        self::assertNull($facts['account']);
        self::assertFalse($facts['any_account']);

        $this->accounts()->save(new Account(self::OTHER_SHOP, '', 'othershop', 'api-user', 'not-a-real-password'));
        $facts = $this->collect();
        self::assertNull($facts['account'], 'the store has no account of its own');
        self::assertTrue($facts['any_account'], 'another shop has one');

        $this->accounts()->save(new Account(0, '', 'demo', 'api-user', 'not-a-real-password'));
        $facts = $this->collect();
        self::assertSame(['subdomain' => 'demo', 'connected' => true, 'plan_blocked' => false], $facts['account']);
        self::assertStringNotContainsString('api-user', (string) json_encode($facts));
        self::assertStringNotContainsString('not-a-real-password', (string) json_encode($facts));
    }

    public function testAnAccountWithoutAPasswordIsNotConnected(): void
    {
        $this->accounts()->save(new Account(0, '', 'demo', 'api-user', ''));

        $facts = $this->collect();

        self::assertSame(['subdomain' => 'demo', 'connected' => false, 'plan_blocked' => false], $facts['account']);
        self::assertFalse($facts['any_account']);
    }

    public function testAnAccountWhosePackageHasNoApiAccessIsSaidSo(): void
    {
        $this->accounts()->save(new Account(0, '', 'demo', 'api-user', 'not-a-real-password'));
        $health = Services::instance()->get(ConnectionHealth::class);
        $before = \Configuration::getGlobalValue(ReportSettings::HEALTH);
        try {
            $health->recordSmaily('demo', ConnectionHealth::PLAN_BLOCKED, false);
            self::assertTrue($this->collect()['account']['plan_blocked'], 'Smaily answered code 227');

            $health->recordSmaily('demo', '', false);
            self::assertFalse($this->collect()['account']['plan_blocked'], 'a later answer that is not a refusal');
        } finally {
            $before === false ? \Configuration::deleteByName(ReportSettings::HEALTH) : \Configuration::updateGlobalValue(ReportSettings::HEALTH, $before);
        }
    }

    public function testTheSetupIsCompleteOnceFinishedOrUpgraded(): void
    {
        $before = \Configuration::getGlobalValue(AdminSettings::SETUP_STATE);
        try {
            foreach ([AdminSettings::SETUP_NEW => false, AdminSettings::SETUP_DONE => true, AdminSettings::SETUP_UPGRADED => true] as $state => $complete) {
                \Configuration::updateGlobalValue(AdminSettings::SETUP_STATE, $state);
                self::assertSame($complete, $this->collect()['setup_complete'], $state);
            }
        } finally {
            $before === false ? \Configuration::deleteByName(AdminSettings::SETUP_STATE) : \Configuration::updateGlobalValue(AdminSettings::SETUP_STATE, $before);
        }
    }

    public function testContactSyncReadsItsSwitchAndItsConfirmation(): void
    {
        \Configuration::updateGlobalValue(ContactsSettings::ENABLED, '1');
        \Configuration::deleteByName(ContactsSettings::CONFIRMED);
        $facts = $this->collect();
        self::assertTrue($facts['contacts_enabled']);
        self::assertFalse($facts['contacts_confirmed']);

        \Configuration::updateGlobalValue(ContactsSettings::CONFIRMED, '1');
        self::assertTrue($this->collect()['contacts_confirmed']);
    }

    public function testNothingOfTheModulesTablesIsReadWhileTheUpgradeIsNotFinished(): void
    {
        $this->row('contact.sync', 'failed', 0);
        // A page request has its configuration loaded; setUp()'s deleteByName() dropped it.
        \Configuration::loadConfiguration();
        $before = self::questions();

        $facts = $this->collect('pending');

        self::assertSame(0, self::questions() - $before - 1, 'no query but the count itself');
        self::assertNull($facts['contact_syncs']);
        self::assertNull($facts['queued_today']);
        self::assertNull($facts['failed_24h']);
        self::assertSame([], $facts['activity']);
        self::assertNull($facts['account']);
    }

    public function testTheCronLinesAddressDoesNotDependOnTheEmployeesLanguage(): void
    {
        $context = \Context::getContext();
        $language = $context->language;
        $url = BackgroundTasks::tickUrl('abc');
        $path = (string) parse_url($url, PHP_URL_PATH);
        try {
            $other = clone $language;
            $other->id = (int) $language->id + 1000;
            $other->iso_code = 'zz';
            $context->language = $other;

            self::assertSame($url, BackgroundTasks::tickUrl('abc'));
        } finally {
            $context->language = $language;
        }
        self::assertStringStartsWith($context->link->getBaseLink(null, true), $url);
        self::assertStringContainsString('token=abc', $url);
        self::assertDoesNotMatchRegularExpression('#/[a-z]{2}/module/#', $url, 'no language prefix');
        self::assertTrue(
            $path === $context->shop->getBaseURI() . 'module/smailyforprestashop/tick' || str_contains($url, 'controller=tick'),
            'the dispatcher\'s module route, friendly or not'
        );
    }

    public function testARenderCostsAFewQueries(): void
    {
        $this->accounts()->save(new Account(0, '', 'demo', 'api-user', 'not-a-real-password'));
        $this->row('contact.sync', 'sent', 0);
        \Configuration::loadConfiguration();
        $before = self::questions();

        $this->collect();

        // Three counts, the newest rows of the two queues, the newest jobs, the selected shop's
        // account, how Smaily last answered it and the stored connections.
        self::assertSame(9, self::questions() - $before - 1);
    }

    /**
     * @return array<string, mixed>
     */
    private function collect(?string $upgrade = null, int $idShop = 0, bool $multishop = false): array
    {
        $services = Services::instance();
        $queries = new DashboardQueries();
        $connections = $services->get(Connections::class);
        $facts = new DashboardFacts(
            $queries,
            new ContactsSettings(),
            $services->get(StoreLayout::class),
            $services->get(AccountResolver::class),
            $services->get(AccountStore::class),
            $services->get(TickSettings::class),
            new EngineFacts(
                $connections,
                new EngineSettings(),
                new CatalogManifest($connections, new IngestQueue(), $services->get(Client::class), $services->get(CatalogPayloadBuilder::class), fn (): int => $this->now),
                $queries
            ),
            new AdminSettings(),
            $services->get(ConnectionHealth::class)
        );

        return $facts->collect($upgrade, $idShop, $multishop, true, true, $this->now);
    }

    /**
     * A stored connection of an id_shop, as an exchange and its health checks left it. Its key is
     * invented and nothing asks the engine.
     *
     * @param array<string, mixed> $settings
     */
    private function connection(int $idShop, array $settings = []): Connection
    {
        return Services::instance()->get(Connections::class)->save($idShop, 'demo', '550e8400-e29b-41d4-a716-446655440000', 'invented-key-' . $idShop, $settings);
    }

    /**
     * A row of the ingest queue that reached $status $daysAgo days and $secondsAgo seconds before now.
     */
    private function ingestRow(Connection $connection, string $domain, string $key, string $status, int $daysAgo, int $secondsAgo = 0): void
    {
        $at = gmdate('Y-m-d H:i:s', $this->now - $daysAgo * self::DAY - $secondsAgo);
        self::db()->execute(sprintf(
            'INSERT INTO `%ssmaily_connect_ingest_queue` (`id_connection`, `domain`, `entity_key`, `status`, `created_at`, `updated_at`, `sent_at`) VALUES (%d, \'%s\', \'%s\', \'%s\', \'%s\', \'%s\', %s)',
            _DB_PREFIX_,
            $connection->id(),
            pSQL($domain),
            pSQL($key),
            pSQL($status),
            $at,
            $at,
            $status === 'sent' ? '\'' . $at . '\'' : 'NULL'
        ));
    }

    /**
     * A row of $type that reached $status $daysAgo days and $secondsAgo seconds before now.
     */
    private function row(string $type, string $status, int $daysAgo, int $secondsAgo = 0, string $address = 'shopper1@example.test'): int
    {
        $id = $this->queue->enqueue($type, 1, ['email' => $address], $address, 'en');
        $at = gmdate('Y-m-d H:i:s', $this->now - $daysAgo * self::DAY - $secondsAgo);
        self::db()->execute(sprintf(
            'UPDATE `%ssmaily_connect_event_queue` SET `status` = \'%s\', `created_at` = \'%s\', `updated_at` = \'%s\', `sent_at` = %s WHERE `id` = %d',
            _DB_PREFIX_,
            pSQL($status),
            $at,
            $at,
            $status === 'sent' ? '\'' . $at . '\'' : 'NULL',
            $id
        ));

        return $id;
    }

    private function accounts(): AccountStore
    {
        return Services::instance()->get(AccountStore::class);
    }

    private static function questions(): int
    {
        $rows = self::db()->executeS('SHOW SESSION STATUS LIKE \'Questions\'', true, false);

        return (int) $rows[0]['Value'];
    }

    private function clean(): void
    {
        self::db()->execute('DELETE FROM `' . _DB_PREFIX_ . 'smaily_connect_event_queue`');
        self::db()->execute('DELETE FROM `' . _DB_PREFIX_ . 'smaily_connect_job`');
        self::db()->execute('DELETE FROM `' . _DB_PREFIX_ . 'smaily_connect_ingest_queue`');
        // No connection may outlive its test: a later test that runs the whole tick would ask
        // the real engine about it.
        self::db()->execute('DELETE FROM `' . _DB_PREFIX_ . 'smaily_connect_connection`');
        \Configuration::deleteByName(EngineSettings::CONSENT_CONFIRMED);
        \Configuration::deleteByName(StoreLayout::SEPARATE_STORES);
        // Every account: any_account reads them all.
        self::db()->execute('DELETE FROM `' . _DB_PREFIX_ . 'smaily_connect_account`');
        \Configuration::deleteByName(ContactsSettings::ENABLED);
        \Configuration::deleteByName(ContactsSettings::CONFIRMED);
    }
}
