<?php
declare(strict_types=1);

namespace Tests\Integration\Admin;

use PrestaShop\Module\SmailyForPrestaShop\Admin\Dashboard\DashboardFacts;
use PrestaShop\Module\SmailyForPrestaShop\Admin\Dashboard\DashboardQueries;
use PrestaShop\Module\SmailyForPrestaShop\Admin\Dashboard\DashboardSettings;
use PrestaShop\Module\SmailyForPrestaShop\Config\ContactsSettings;
use PrestaShop\Module\SmailyForPrestaShop\Config\TickSettings;
use PrestaShop\Module\SmailyForPrestaShop\Contacts\Import\ContactImport;
use PrestaShop\Module\SmailyForPrestaShop\Contacts\Import\JobStore;
use PrestaShop\Module\SmailyForPrestaShop\Queue\EventQueue;
use PrestaShop\Module\SmailyForPrestaShop\Runtime\Services;
use PrestaShop\Module\SmailyForPrestaShop\Scope\AccountResolver;
use PrestaShop\Module\SmailyForPrestaShop\Scope\StoreLayout;
use PrestaShop\Module\SmailyForPrestaShop\Smaily\Account;
use PrestaShop\Module\SmailyForPrestaShop\Smaily\AccountStore;
use Tests\Integration\IntegrationTestCase;

/**
 * The Dashboard reads its facts from stored state only: the counts of the tiles over the windows
 * Magento uses, the newest queue rows and import jobs without an address, the Smaily account of the
 * selected shop, and the cron line with a token that it never creates. While the upgrade is not
 * finished it reads no table of the module. A render costs a small, fixed number of queries.
 */
final class DashboardFactsTest extends IntegrationTestCase
{
    private const DAY = 86400;

    private const OTHER_SHOP = 990;

    private int $now;

    private EventQueue $queue;

    private ?string $token = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->now = time();
        $this->queue = new EventQueue(fn (): int => $this->now);
        $this->token = \Configuration::hasKey(TickSettings::TICK_TOKEN) ? (string) \Configuration::getGlobalValue(TickSettings::TICK_TOKEN) : null;
        $this->clean();
    }

    protected function tearDown(): void
    {
        if (defined('_PS_ROOT_DIR_')) {
            $this->clean();
            if ($this->token !== null) {
                \Configuration::updateGlobalValue(TickSettings::TICK_TOKEN, $this->token);
            }
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

        self::assertSame(['count' => 2, 'more' => false], $facts['contact_syncs']);
        self::assertSame(2, $facts['queued_today']);
        self::assertSame(1, $facts['failed_24h']);
    }

    public function testRecentActivityListsTheNewestRowsAndJobsWithoutAnAddress(): void
    {
        for ($i = 1; $i <= 11; ++$i) {
            $this->row('contact.sync', 'sent', 0, 100 - $i, 'shopper' . $i . '@example.test');
        }
        (new JobStore())->create(ContactImport::TYPE_IMPORT, 0, JobStore::STATUS_RUNNING, ['total' => 3]);

        $facts = $this->collect();
        $activity = $facts['activity'];

        self::assertCount(10, $activity);
        self::assertSame(['kind' => 'job', 'type' => 'contacts_import', 'total' => 3, 'status' => 'running'], array_diff_key($activity[0], ['updated_at' => 0]));
        self::assertSame('contact.sync', $activity[1]['type']);
        self::assertMatchesRegularExpression('/^[0-9a-f]{12}…$/u', $activity[1]['entity']);
        self::assertGreaterThanOrEqual($activity[2]['updated_at'], $activity[1]['updated_at']);
        self::assertStringNotContainsString('@', (string) json_encode($facts), 'no address among the facts');
        self::assertStringNotContainsString('shopper', (string) json_encode($facts));
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
        self::assertSame(['subdomain' => 'demo', 'connected' => true], $facts['account']);
        self::assertStringNotContainsString('api-user', (string) json_encode($facts));
        self::assertStringNotContainsString('not-a-real-password', (string) json_encode($facts));
    }

    public function testAnAccountWithoutAPasswordIsNotConnected(): void
    {
        $this->accounts()->save(new Account(0, '', 'demo', 'api-user', ''));

        $facts = $this->collect();

        self::assertSame(['subdomain' => 'demo', 'connected' => false], $facts['account']);
        self::assertFalse($facts['any_account']);
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

    public function testTheCronLineCarriesTheStoredTokenAndNeverCreatesOne(): void
    {
        \Configuration::deleteByName(TickSettings::TICK_TOKEN);

        self::assertNull($this->collect()['cron_line']);
        self::assertFalse(\Configuration::hasKey(TickSettings::TICK_TOKEN), 'the read stored no token');
        self::assertNull((new DashboardSettings())->tickToken());

        \Configuration::updateGlobalValue(TickSettings::TICK_TOKEN, 'abc123');
        self::assertSame('* * * * * curl -fsS "https://shop.example.test/tick?token=abc123" > /dev/null', $this->collect()['cron_line']);
        self::assertNull($this->collect(null, false)['cron_line'], 'only an admin who may configure sees the token');
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

    public function testARenderCostsAFewQueries(): void
    {
        $this->accounts()->save(new Account(0, '', 'demo', 'api-user', 'not-a-real-password'));
        $this->row('contact.sync', 'sent', 0);
        \Configuration::loadConfiguration();
        $before = self::questions();

        $this->collect();

        // Three counts, the newest rows, the newest jobs and the selected shop's account.
        self::assertSame(6, self::questions() - $before - 1);
    }

    /**
     * @return array<string, mixed>
     */
    private function collect(?string $upgrade = null, bool $canConfigure = true): array
    {
        $services = Services::instance();
        $facts = new DashboardFacts(
            new DashboardQueries(),
            new DashboardSettings(),
            $services->get(StoreLayout::class),
            $services->get(AccountResolver::class),
            $services->get(AccountStore::class),
            $services->get(TickSettings::class),
            'test-key',
            static fn (string $token): string => 'https://shop.example.test/tick?token=' . $token
        );

        return $facts->collect($upgrade, 0, false, $canConfigure, true, $this->now);
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
        self::db()->execute('DELETE FROM `' . _DB_PREFIX_ . 'smaily_connect_account` WHERE `id_shop` IN (0, ' . self::OTHER_SHOP . ')');
        \Configuration::deleteByName(ContactsSettings::ENABLED);
        \Configuration::deleteByName(ContactsSettings::CONFIRMED);
    }
}
