<?php
declare(strict_types=1);

namespace Tests\Integration\Migration;

use PrestaShop\Module\SmailyForPrestaShop\Admin\BaseController;
use PrestaShop\Module\SmailyForPrestaShop\Admin\Settings\SettingsScope;
use PrestaShop\Module\SmailyForPrestaShop\Admin\Settings\ShopOverrides;
use PrestaShop\Module\SmailyForPrestaShop\Admin\Settings\TabSaver;
use PrestaShop\Module\SmailyForPrestaShop\Admin\Setup\SetupPanels;
use PrestaShop\Module\SmailyForPrestaShop\Automation\MappingRepository;
use PrestaShop\Module\SmailyForPrestaShop\Config\MigrationNotices;
use PrestaShop\Module\SmailyForPrestaShop\Config\TickSettings;
use PrestaShop\Module\SmailyForPrestaShop\Database\SqlException;
use PrestaShop\Module\SmailyForPrestaShop\Http\Transport;
use PrestaShop\Module\SmailyForPrestaShop\Install\HookList;
use PrestaShop\Module\SmailyForPrestaShop\Install\Schema;
use PrestaShop\Module\SmailyForPrestaShop\Migration\LegacyConfigMapper;
use PrestaShop\Module\SmailyForPrestaShop\Migration\LegacyFiles;
use PrestaShop\Module\SmailyForPrestaShop\Migration\LegacyOverride;
use PrestaShop\Module\SmailyForPrestaShop\Migration\LegacyReader;
use PrestaShop\Module\SmailyForPrestaShop\Migration\LegacyState;
use PrestaShop\Module\SmailyForPrestaShop\Migration\MigrationPlan;
use PrestaShop\Module\SmailyForPrestaShop\Migration\Upgrade300;
use PrestaShop\Module\SmailyForPrestaShop\Runtime\Provider\MigrationProvider;
use PrestaShop\Module\SmailyForPrestaShop\Runtime\Services;
use PrestaShop\Module\SmailyForPrestaShop\Scope\StoreLayout;
use PrestaShop\Module\SmailyForPrestaShop\Smaily\AccountStore;
use Tests\Integration\Admin\MenuTest;
use Tests\Integration\Fixtures\FailingQueries;
use Tests\Integration\Fixtures\SmailyMockServer;
use Tests\Integration\IntegrationTestCase;

require_once __DIR__ . '/LegacyStore.php';
require_once __DIR__ . '/../Admin/MenuTest.php';
require_once __DIR__ . '/../Fixtures/FailingQueries.php';
require_once __DIR__ . '/../Fixtures/SmailyMockServer.php';

/**
 * A store that 2.x or 1.6.x left upgrades in place: the plan's settings, accounts, mapping rows and
 * reminded carts exist afterwards, the legacy keys but the two cron tokens are gone, and a migration
 * that stops at any step leaves the legacy state and an inert runtime until a later run completes it.
 *
 * No assertion compares the password itself, so a failure cannot print it. The step that deletes
 * the files of the previous version works on a folder under the system's temporary folder that
 * holds those files, never on the module's own folder.
 */
final class Upgrade300Test extends IntegrationTestCase
{
    private const PASSWORD = 'not-a-real-password';

    private const TOKENS = ['SMAILY_CUSTOMER_CRON_TOKEN', 'SMAILY_CART_CRON_TOKEN'];

    private const SAVED_PASSWORD = 'another-invented-password';

    /** A folder that stands for the module's, with every file that 2.2.0 and 1.6.2 left. */
    private string $moduleDir;

    protected function setUp(): void
    {
        parent::setUp();
        LegacyStore::clear();
        $this->removeOverride();
        $this->moduleDir = sys_get_temp_dir() . '/smaily-legacy-files-' . bin2hex(random_bytes(4));
        $this->placeLegacyFiles(LegacyFiles::FILES);
    }

    protected function tearDown(): void
    {
        if (defined('_PS_ROOT_DIR_')) {
            $this->removeModuleDir($this->moduleDir);
            LegacyStore::removeSecondShop();
            LegacyStore::restore();
            $this->removeOverride();
            (new MigrationProvider())->register(Services::instance());
            self::db()->execute('UPDATE `' . _DB_PREFIX_ . 'module` SET `version` = \'3.0.0\' WHERE `name` = \'smailyforprestashop\'');
        }
        parent::tearDown();
    }

    /**
     * @return array<string, array{string, string}>
     */
    public function fixtures(): array
    {
        return [
            '2.2.0 configured' => ['2.2.0-configured.sql', '2.2.0'],
            '2.2.0 fresh install' => ['2.2.0-fresh-install.sql', '2.2.0'],
            '1.6.2 configured' => ['1.6.2-configured.sql', '1.6.2'],
        ];
    }

    /**
     * @dataProvider fixtures
     */
    public function testEachFixtureStoreEndsWithThePlanApplied(string $fixture, string $from): void
    {
        LegacyStore::loadFixture($fixture);
        $this->recordModuleVersion($from);
        $plan = $this->plan($from);
        $tokens = $this->tokenRows();

        self::assertTrue($this->upgrade()->runPending());

        $this->assertPlanApplied($plan);
        self::assertSame($tokens, $this->tokenRows(), 'non-empty cron tokens stay byte-identical');
        self::assertSame($from, $this->upgrade()->state()['from']);
        self::assertSame('done', $this->upgrade()->state()['status']);
    }

    public function testThePlainTextPasswordIsGoneFromTheConfiguration(): void
    {
        LegacyStore::loadFixture('2.2.0-configured.sql');
        $this->recordModuleVersion('2.2.0');

        $this->upgrade()->runPending();

        self::assertSame(0, LegacyStore::countRows('SMAILY_PASSWORD'));
        self::assertSame('0', (string) self::db()->getValue(
            'SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'configuration` WHERE `value` LIKE \'%' . pSQL(self::PASSWORD) . '%\'',
            false
        ), 'no configuration row holds the password');
        $stored = (string) self::db()->getValue('SELECT `password_enc` FROM `' . _DB_PREFIX_ . 'smaily_connect_account`', false);
        self::assertFalse(strpos($stored, self::PASSWORD) !== false, 'the account row holds it encrypted');
    }

    public function testEmptyCronTokensAreReplacedAndTheOthersKept(): void
    {
        LegacyStore::loadFixture('1.6.2-configured.sql');
        self::db()->execute(
            'UPDATE `' . _DB_PREFIX_ . 'configuration` SET `value` = \'\' WHERE `name` = \'SMAILY_CART_CRON_TOKEN\''
        );
        LegacyStore::reloadConfiguration();
        $this->recordModuleVersion('1.6.2');

        self::assertTrue($this->upgrade()->runPending());

        self::assertSame('abc123def456', LegacyStore::row('SMAILY_CUSTOMER_CRON_TOKEN'));
        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', (string) LegacyStore::row('SMAILY_CART_CRON_TOKEN'));
        self::assertContains(
            ['code' => MigrationPlan::NOTICE_CRON_TOKEN_REPLACED, 'params' => ['key' => 'SMAILY_CART_CRON_TOKEN', 'id_shop_group' => 0, 'id_shop' => 0]],
            $this->notices()
        );
    }

    public function testATickTokenStoredBeforeTheUpgradeIsKept(): void
    {
        LegacyStore::loadFixture('2.2.0-configured.sql');
        LegacyStore::insertRows([[null, null, TickSettings::TICK_TOKEN, 'storedbeforetheupgrade']]);
        $this->recordModuleVersion('2.2.0');

        self::assertTrue($this->upgrade()->runPending());

        self::assertSame('storedbeforetheupgrade', LegacyStore::row(TickSettings::TICK_TOKEN));
        self::assertSame(1, LegacyStore::countRows(TickSettings::TICK_TOKEN));
    }

    public function testEverySentCartHasItsAlreadyRemindedRow(): void
    {
        LegacyStore::loadFixture('2.2.0-configured.sql');
        $this->recordModuleVersion('2.2.0');

        $this->upgrade()->runPending();

        self::assertSame(
            [
                ['id_cart' => '1', 'id_customer' => '2', 'status' => 'mailed', 'mailed_at' => '2026-11-01 10:15:00'],
                ['id_cart' => '2', 'id_customer' => '2', 'status' => 'mailed', 'mailed_at' => '2026-11-02 18:40:30'],
                ['id_cart' => '3', 'id_customer' => '2', 'status' => 'mailed', 'mailed_at' => '2026-11-03 07:05:59'],
            ],
            array_map(static fn (array $row): array => array_map('strval', $row), self::db()->executeS(
                'SELECT `id_cart`, `id_customer`, `status`, `mailed_at` FROM `' . _DB_PREFIX_ . 'smaily_connect_cart` ORDER BY `id_cart`'
            ) ?: [])
        );
        self::assertSame('3', (string) self::db()->getValue('SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'smaily_cart`', false), 'the legacy table stays');
    }

    /**
     * A 2.x store has none of the tables of 3.0.0: the upgrade creates them all, the one of the
     * carts' attribution among them, and loses no row of what 2.x left.
     *
     * @dataProvider fixtures
     */
    public function testTheUpgradeCreatesEveryTableThatAnEarlierReleaseLacks(string $fixture, string $from): void
    {
        LegacyStore::loadFixture($fixture);
        $this->recordModuleVersion($from);
        foreach (Schema::tables() as $table) {
            self::db()->execute('DROP TABLE `' . _DB_PREFIX_ . $table . '`');
        }
        $plan = $this->plan($from);
        $tokens = $this->tokenRows();
        $sentCarts = self::db()->executeS('SHOW TABLES LIKE \'' . _DB_PREFIX_ . 'smaily\\_cart\'') ? self::db()->executeS('SELECT * FROM `' . _DB_PREFIX_ . 'smaily_cart` ORDER BY 1') : null;

        self::assertTrue($this->upgrade()->runPending());

        self::assertContains('smaily_connect_cart_attribution', Schema::tables());
        foreach (Schema::tables() as $table) {
            self::assertNotEmpty(self::db()->executeS('SHOW TABLES LIKE \'' . str_replace('_', '\\_', _DB_PREFIX_ . $table) . '\''), $table);
        }
        self::assertSame('0', (string) self::db()->getValue('SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'smaily_connect_cart_attribution`', false), 'nothing is kept about a visitor by the upgrade');
        $this->assertPlanApplied($plan);
        self::assertSame($tokens, $this->tokenRows());
        if ($sentCarts !== null) {
            self::assertSame($sentCarts, self::db()->executeS('SELECT * FROM `' . _DB_PREFIX_ . 'smaily_cart` ORDER BY 1'), 'the rows of 2.x are untouched');
        }
        self::assertSame('done', $this->upgrade()->state()['status']);
    }

    /**
     * Two cron runs of 2.x that overlapped could each remind one cart and each record it (the 2.x
     * table has no unique key on the cart). The cart keeps the latest send time, whichever row
     * came first, and the legacy table keeps both rows.
     */
    public function testACartThat2xRecordedTwiceKeepsItsLatestSendTime(): void
    {
        LegacyStore::loadFixture('2.2.0-configured.sql');
        self::db()->execute(
            'INSERT INTO `' . _DB_PREFIX_ . 'smaily_cart` (`id_customer`, `id_cart`, `date_sent`) VALUES'
            . ' (2, 1, \'2026-11-01 10:19:30\'), (2, 3, \'2026-11-03 07:01:00\')'
        );
        $this->recordModuleVersion('2.2.0');

        self::assertTrue($this->upgrade()->runPending());

        self::assertSame(
            [
                ['id_cart' => '1', 'last_activity_at' => '2026-11-01 10:19:30', 'mailed_at' => '2026-11-01 10:19:30'],
                ['id_cart' => '2', 'last_activity_at' => '2026-11-02 18:40:30', 'mailed_at' => '2026-11-02 18:40:30'],
                ['id_cart' => '3', 'last_activity_at' => '2026-11-03 07:05:59', 'mailed_at' => '2026-11-03 07:05:59'],
            ],
            array_map(static fn (array $row): array => array_map('strval', $row), self::db()->executeS(
                'SELECT `id_cart`, `last_activity_at`, `mailed_at` FROM `' . _DB_PREFIX_ . 'smaily_connect_cart` ORDER BY `id_cart`'
            ) ?: [])
        );
        self::assertSame('5', (string) self::db()->getValue('SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'smaily_cart`', false), 'the legacy table stays');
    }

    public function testAStoreWithoutLegacyDataCompletesAsANewSetup(): void
    {
        $this->recordModuleVersion('2.2.0');

        self::assertTrue($this->upgrade()->runPending());

        self::assertTrue(Schema::isCurrent());
        self::assertSame('new', LegacyStore::row('SMAILY_CONNECT_SETUP_STATE'));
        foreach (self::TOKENS as $key) {
            self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', (string) LegacyStore::row($key));
        }
        self::assertSame('0', (string) self::db()->getValue('SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'smaily_connect_account`', false));
    }

    /**
     * @return array<string, array{string}>
     */
    public function steps(): array
    {
        $steps = [];
        foreach (Upgrade300::STEPS as $step) {
            $steps[$step] = [$step];
        }

        return $steps;
    }

    /**
     * @dataProvider steps
     */
    public function testAFailureAtAnyStepLeavesTheLegacyStateAndALaterRunCompletes(string $failingStep): void
    {
        LegacyStore::loadFixture('2.2.0-configured.sql');
        $this->recordModuleVersion('2.2.0');
        $this->upgrade()->runPending();
        $undisturbed = $this->snapshot();

        LegacyStore::clear();
        LegacyStore::loadFixture('2.2.0-configured.sql');
        $this->recordModuleVersion('2.2.0');
        MenuTest::removeMenu();
        $legacy = $this->legacyRows();

        $failing = $this->upgrade(static function (string $step) use ($failingStep): void {
            if ($step === $failingStep) {
                throw new \LogicException('injected');
            }
        });
        self::assertFalse($failing->runPending());

        self::assertSame($legacy, $this->legacyRows(), 'every legacy row stays');
        self::assertFalse(Schema::isCurrent(), 'the runtime stays inert');
        $state = $this->upgrade()->state();
        self::assertSame('failed', $state['status']);
        self::assertSame(['step' => $failingStep, 'error' => \LogicException::class], $state['failure']);

        $this->recordModuleVersion('3.0.0');
        self::assertTrue($this->upgrade()->runPending());
        self::assertSame($undisturbed, $this->snapshot());
        self::assertSame([], $this->legacyFiles(), 'the files of the previous version are gone');
    }

    public function testASecondRunOfACompletedMigrationChangesNothing(): void
    {
        LegacyStore::loadFixture('2.2.0-configured.sql');
        $this->recordModuleVersion('2.2.0');
        $this->upgrade()->runPending();
        $before = $this->everything();

        self::assertTrue($this->upgrade()->runPending());

        self::assertSame($before, $this->everything());
    }

    /**
     * How the store came to be, what it lost besides its schema version, and the module version
     * that PrestaShop records (2.2.0: the files were copied by FTP and nobody clicked Upgrade).
     *
     * @return array<string, array{string|null, bool, bool, string}>
     */
    public function configuredStores(): array
    {
        return [
            'upgraded from 2.2.0' => ['2.2.0-configured.sql', true, false, '3.0.0'],
            'upgraded from 2.2.0 by an FTP copy' => ['2.2.0-configured.sql', true, false, '2.2.0'],
            'upgraded, the progress record lost too' => ['2.2.0-configured.sql', true, true, '3.0.0'],
            'upgraded by an FTP copy, the progress record lost too' => ['2.2.0-configured.sql', true, true, '2.2.0'],
            'upgraded multistore store' => ['2.2.0-multistore-own-rows.sql', true, false, '3.0.0'],
            'upgraded multistore store by an FTP copy, the progress record lost too' => ['2.2.0-multistore-own-rows.sql', true, true, '2.2.0'],
            'upgraded store with multistore off and two shops, the progress record lost too' => ['2.2.0-multistore-off-two-shops.sql', true, true, '2.2.0'],
            'installed by 3.0.0 and set up' => [null, true, false, '3.0.0'],
            'installed by 3.0.0, not set up yet' => [null, false, false, '3.0.0'],
        ];
    }

    /**
     * A store in use that loses its schema version gets the version back by the pending-migration
     * run, and nothing else changes: no setting, mapping, account, token or reminded cart, and the
     * store is not sent to the Initial setup.
     *
     * @dataProvider configuredStores
     */
    public function testAReRunOnAConfiguredStoreChangesNothing(?string $fixture, bool $setUp, bool $recordLost, string $version): void
    {
        if ($fixture === null) {
            Schema::markCurrent();
        } else {
            LegacyStore::loadFixture($fixture);
            $this->recordModuleVersion('2.2.0');
            self::assertTrue($this->upgrade()->runPending());
        }
        if ($setUp) {
            // The Initial setup is done, the layout answered, a consent mode chosen and confirmed,
            // an automation switched on and its workflow chosen.
            foreach ([
                'SMAILY_CONNECT_SETUP_STATE' => 'done',
                'SMAILY_CONNECT_SEPARATE_STORES' => '0',
                'SMAILY_CONNECT_LANGUAGE_MODE' => 'b',
                'SMAILY_CONNECT_CONTACTS_MODE' => 'all_customers',
                'SMAILY_CONNECT_CONTACTS_CONFIRMED' => '1',
                'SMAILY_CONNECT_FIRST_ORDER_ENABLED' => '1',
                'SMAILY_CONNECT_CART_DELAY_MINUTES' => '120',
            ] as $key => $value) {
                \Configuration::updateGlobalValue($key, $value);
            }
            self::db()->execute(
                'REPLACE INTO `' . _DB_PREFIX_ . 'smaily_connect_automation_mapping`'
                . ' (`id_shop`, `trigger`, `language`, `account_key`, `workflow_id`, `optin_trigger`, `is_default_fallback`, `date_upd`)'
                . ' VALUES (0, \'welcome\', \'\', \'default\', 777, 0, 1, \'2026-11-20 08:00:00\')'
            );
            // Every notice of the upgrade was read and dismissed.
            foreach ((new MigrationNotices())->all() as $notice) {
                (new MigrationNotices())->dismiss($notice['id']);
            }
        }
        $this->recordModuleVersion($version);
        if ($recordLost) {
            \Configuration::deleteByName(Upgrade300::STATE_KEY);
        }
        \Configuration::deleteByName(Schema::VERSION_KEY);
        LegacyStore::reloadConfiguration();
        // What the module's folder of such a store holds: files of 3.0.0, and the files of the
        // previous version only where no upgrade ran.
        $this->placeFiles(['smailyforprestashop.php', 'src/Model/RssFeedProductsCollection.php']);
        $files = $this->moduleFiles();
        $before = $this->held();
        self::assertFalse(Schema::isCurrent());

        self::assertTrue($this->upgrade()->runPending());

        self::assertTrue(Schema::isCurrent());
        self::assertSame($before, $this->held());
        self::assertSame($files, $this->moduleFiles(), 'and no file is deleted');
        self::assertSame(!$setUp, BaseController::isSetupForced(), 'only a store nobody set up opens the Initial setup');
    }

    /**
     * The module version that PrestaShop records does not decide alone: a store that still holds
     * legacy keys is migrated, also when the record already says 3.0.0.
     */
    public function testAStoreWithLegacyKeysIsMigratedAlsoWhenPrestaShopRecords300(): void
    {
        LegacyStore::loadFixture('2.2.0-configured.sql');
        $this->recordModuleVersion('3.0.0');
        $plan = $this->plan('3.0.0');

        self::assertTrue($this->upgrade()->runPending());

        $this->assertPlanApplied($plan);
    }

    /**
     * What a merchant saved while the upgrade was not finished stays: a setting and a mapping row
     * that the store holds are not written again, and the upgrade still completes.
     */
    public function testAnUnfinishedUpgradeKeepsWhatWasSavedMeanwhile(): void
    {
        LegacyStore::loadFixture('2.2.0-configured.sql');
        $this->recordModuleVersion('2.2.0');
        // Saved in the admin after the files were copied and before the first run.
        \Configuration::updateGlobalValue('SMAILY_CONNECT_CONTACTS_MODE', 'all_customers');
        self::db()->execute(
            'INSERT INTO `' . _DB_PREFIX_ . 'smaily_connect_automation_mapping`'
            . ' (`id_shop`, `trigger`, `language`, `account_key`, `workflow_id`, `optin_trigger`, `is_default_fallback`, `date_upd`)'
            . ' VALUES (0, \'abandoned_cart\', \'\', \'default\', 555, 0, 1, NOW())'
        );
        $failing = $this->upgrade(static function (string $step): void {
            if ($step === 'carts') {
                throw new \LogicException('injected');
            }
        });
        self::assertFalse($failing->runPending());
        // Saved after the settings step, while the upgrade waits for its next run.
        \Configuration::updateGlobalValue('SMAILY_CONNECT_FIRST_ORDER_ENABLED', '1');

        self::assertTrue($this->upgrade()->runPending());

        self::assertTrue(Schema::isCurrent());
        self::assertSame('all_customers', LegacyStore::row('SMAILY_CONNECT_CONTACTS_MODE'));
        self::assertSame('1', LegacyStore::row('SMAILY_CONNECT_FIRST_ORDER_ENABLED'));
        self::assertSame('45', LegacyStore::row('SMAILY_CONNECT_CART_DELAY_MINUTES'), 'a setting the store did not hold is the plan\'s');
        self::assertSame(
            [['trigger' => 'abandoned_cart', 'workflow_id' => '555'], ['trigger' => 'welcome', 'workflow_id' => '101']],
            array_map(static fn (array $row): array => array_map('strval', $row), self::db()->executeS(
                'SELECT `trigger`, `workflow_id` FROM `' . _DB_PREFIX_ . 'smaily_connect_automation_mapping` ORDER BY `trigger`'
            ) ?: [])
        );
    }

    /**
     * Where the upgrade stopped before the merchant saved an account in Settings > Connection:
     * before the accounts step (the 2.x account is not written yet), or after it (the 2.x account
     * is written, and the save replaces it).
     *
     * @return array<string, array{string}>
     */
    public function stops(): array
    {
        return [
            'stopped before the accounts step' => ['accounts'],
            'stopped after the accounts step' => ['carts'],
        ];
    }

    /**
     * A Smaily account that a merchant saves while the upgrade is not finished stays: the next run
     * does not write the 2.x account over it, `verify` passes on the account the store holds, and
     * the upgrade completes. The plain-text password of 2.x goes as on any upgrade.
     *
     * @dataProvider stops
     */
    public function testAnAccountSavedWhileTheUpgradeIsUnfinishedStays(string $stoppedAt): void
    {
        LegacyStore::loadFixture('2.2.0-configured.sql');
        $this->recordModuleVersion('2.2.0');
        $failing = $this->upgrade(static function (string $step) use ($stoppedAt): void {
            if ($step === $stoppedAt) {
                throw new \LogicException('injected');
            }
        });
        self::assertFalse($failing->runPending());
        self::assertSame($stoppedAt, $this->upgrade()->state()['failure']['step']);

        $mock = SmailyMockServer::start();
        $mock->acceptCredentials('merchant-user', self::SAVED_PASSWORD);
        $transport = $mock->transport();
        $services = Services::fromProviders(Services::PROVIDERS);
        $services->set(Transport::class, static fn (): Transport => $transport);
        $saver = new TabSaver(
            new SetupPanels($services, \Context::getContext()->getTranslator()),
            new ShopOverrides($services->get(AccountStore::class), $services->get(MappingRepository::class))
        );
        $saved = $saver->saveConnection(
            SettingsScope::current(new StoreLayout(), 0),
            ['subdomain' => 'merchant-store', 'username' => 'merchant-user', 'password' => self::SAVED_PASSWORD, 'language_mode' => 'single'],
            false
        );
        self::assertSame([[], true], [$saved['errors'], $saved['connected']]);

        self::assertTrue($this->upgrade()->runPending());

        $state = $this->upgrade()->state();
        self::assertSame(['done', null], [$state['status'], $state['failure']]);
        self::assertContains('verify', $state['done']);
        self::assertTrue(Schema::isCurrent());
        $stored = Services::instance()->get(AccountStore::class)->find(0, '');
        self::assertNotNull($stored);
        self::assertSame(['merchant-store', 'merchant-user'], [$stored->subdomain(), $stored->username()]);
        self::assertTrue(hash_equals(self::SAVED_PASSWORD, $stored->password()), 'the saved password stays');
        self::assertSame('1', (string) self::db()->getValue('SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'smaily_connect_account`', false));
        self::assertSame(0, LegacyStore::countRows('SMAILY_PASSWORD'), 'the plain-text password of 2.x is gone');
    }

    /**
     * An account that the accounts step wrote must read back as the legacy one. A 2.x username
     * longer than the column does not (the database cuts it, or refuses the row): the step fails
     * on every run and leaves no account row, so no later run takes a faulty row for an account
     * that the store held, and the legacy keys stay.
     */
    public function testAnAccountThatDoesNotReadBackAsWrittenFailsTheStepAndLeavesNoRow(): void
    {
        LegacyStore::loadFixture('2.2.0-configured.sql');
        self::db()->execute(
            'UPDATE `' . _DB_PREFIX_ . 'configuration` SET `value` = \'' . str_repeat('u', 150) . '\' WHERE `name` = \'SMAILY_USERNAME\''
        );
        LegacyStore::reloadConfiguration();
        $this->recordModuleVersion('2.2.0');

        foreach ([1, 2] as $run) {
            self::assertFalse($this->upgrade()->runPending(), 'run ' . $run);

            self::assertSame('accounts', $this->upgrade()->state()['failure']['step']);
            self::assertSame('0', (string) self::db()->getValue('SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'smaily_connect_account`', false));
            self::assertSame(1, LegacyStore::countRows('SMAILY_PASSWORD'));
            self::assertFalse(Schema::isCurrent());
        }
    }

    public function testOfTwoConcurrentRunsOneMigratesAndTheOtherReturnsAtOnce(): void
    {
        LegacyStore::loadFixture('2.2.0-configured.sql');
        $this->recordModuleVersion('2.2.0');
        $this->upgrade()->runPending();
        $undisturbed = $this->snapshot();
        LegacyStore::clear();
        LegacyStore::loadFixture('2.2.0-configured.sql');
        $this->recordModuleVersion('2.2.0');

        $first = self::worker(['hold'], $firstPipes);
        try {
            $line = fgets($firstPipes[1]);
            self::assertSame("started\n", $line, $line === false ? (string) stream_get_contents($firstPipes[2]) : '');

            $second = self::worker([], $secondPipes);
            $line = fgets($secondPipes[1]);
            $error = (string) stream_get_contents($secondPipes[2]);
            self::closeWorker($second, $secondPipes);
            self::assertIsString($line, $error);
            [$result, $seconds] = explode(' ', trim($line));
            self::assertSame('0', $result, 'the second run returns without the schema current');
            self::assertLessThan(1.0, (float) $seconds, 'at once');
            LegacyStore::reloadConfiguration();
            self::assertSame(['pending', []], [$this->upgrade()->state()['status'], $this->upgrade()->state()['done']], 'and runs no step');

            fwrite($firstPipes[0], "go\n");
            $line = fgets($firstPipes[1]);
            self::assertIsString($line, (string) stream_get_contents($firstPipes[2]));
            self::assertSame('1', explode(' ', trim($line))[0], 'the first run completes');
        } finally {
            self::closeWorker($first, $firstPipes);
        }

        LegacyStore::reloadConfiguration();
        self::assertTrue(Schema::isCurrent());
        self::assertSame($undisturbed, $this->snapshot());
    }

    public function testTheOverrideThat2xShippedIsRemoved(): void
    {
        $this->placeOverride((string) file_get_contents(__DIR__ . '/../../fixtures/legacy/override-2.x.ps_emailsubscription.php.txt'));
        LegacyStore::loadFixture('2.2.0-configured.sql');
        $this->recordModuleVersion('2.2.0');

        $this->upgrade()->runPending();

        self::assertFileDoesNotExist(_PS_OVERRIDE_DIR_ . LegacyOverride::RELATIVE_PATH);
        self::assertNotContains(MigrationNotices::OVERRIDE_LEFT_IN_PLACE, array_column($this->notices(), 'code'));
    }

    public function testAnotherOverrideStaysWithANotice(): void
    {
        $this->placeOverride("<?php\n// another module's override\n");
        LegacyStore::loadFixture('2.2.0-configured.sql');
        $this->recordModuleVersion('2.2.0');

        $this->upgrade()->runPending();

        self::assertFileExists(_PS_OVERRIDE_DIR_ . LegacyOverride::RELATIVE_PATH);
        self::assertContains(
            ['code' => MigrationNotices::OVERRIDE_LEFT_IN_PLACE, 'params' => ['path' => 'override/' . LegacyOverride::RELATIVE_PATH]],
            $this->notices()
        );
    }

    /**
     * PrestaShop unpacks 3.0.0 over the module's folder and deletes nothing. The upgrade deletes
     * the files that 2.2.0 and 1.6.2 shipped and 3.0.0 does not, with the folders they leave
     * empty, and no other file.
     */
    public function testTheFilesOfThePreviousVersionAreDeleted(): void
    {
        LegacyStore::loadFixture('2.2.0-configured.sql');
        $this->recordModuleVersion('2.2.0');
        $this->placeFiles(['smailyforprestashop.php', 'src/Model/RssFeedProductsCollection.php', 'lib/MerchantsOwn.php']);
        self::assertCount(47, $this->legacyFiles());

        self::assertTrue($this->upgrade()->runPending());

        self::assertSame([], $this->legacyFiles());
        self::assertFileExists($this->moduleDir . '/smailyforprestashop.php');
        self::assertFileExists($this->moduleDir . '/src/Model/RssFeedProductsCollection.php');
        self::assertFileExists($this->moduleDir . '/lib/MerchantsOwn.php', 'a file that no release shipped stays, and its folder with it');
        self::assertDirectoryDoesNotExist($this->moduleDir . '/src/Form');
        self::assertNotContains(MigrationNotices::FILES_LEFT_IN_PLACE, array_column($this->notices(), 'code'));
    }

    /**
     * A file that cannot be deleted does not stop the upgrade: the step is done, the upgrade
     * completes, and one notice names the files, so that the merchant can delete them by hand.
     */
    public function testAFileThatCannotBeDeletedIsNamedInANoticeAndTheUpgradeCompletes(): void
    {
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            self::markTestSkipped('root deletes from a read-only folder');
        }
        LegacyStore::loadFixture('2.2.0-configured.sql');
        $this->recordModuleVersion('2.2.0');
        chmod($this->moduleDir . '/lib', 0555);

        self::assertTrue($this->upgrade()->runPending());

        $state = $this->upgrade()->state();
        self::assertSame('done', $state['status']);
        self::assertContains('files', $state['done']);
        $left = ['lib/Api.php', 'lib/Logger.php', 'lib/Rss.php', 'lib/index.php'];
        self::assertSame($left, $this->legacyFiles());
        self::assertContains(
            ['code' => MigrationNotices::FILES_LEFT_IN_PLACE, 'params' => ['files' => implode(', ', $left)]],
            $this->notices()
        );
        self::assertSame(
            [MigrationNotices::FILES_LEFT_IN_PLACE],
            array_values(array_intersect(array_column($this->notices(), 'code'), [MigrationNotices::FILES_LEFT_IN_PLACE])),
            'one notice'
        );
    }

    public function testHooksOf16xAreSwappedForTheHookList(): void
    {
        LegacyStore::loadFixture('1.6.2-configured.sql');
        $this->recordModuleVersion('1.6.2');
        $module = self::module();
        $module->unregisterHook('actionValidateOrder');
        // As the 1.6.2 fixture's part 2 does: 3.0.0 has no method for this hook, so it cannot register it.
        self::db()->execute(
            'INSERT INTO `' . _DB_PREFIX_ . 'hook_module` (`id_module`, `id_shop`, `id_hook`, `position`)'
            . ' SELECT ' . (int) $module->id . ', 1, `id_hook`, 99 FROM `' . _DB_PREFIX_ . 'hook` WHERE `name` = \'displayFooterBefore\''
        );

        $this->upgrade()->runPending();

        self::assertSame(
            array_map('strtolower', HookList::HOOKS),
            array_values(array_intersect(array_map('strtolower', HookList::HOOKS), $this->registeredHooks()))
        );
        self::assertNotContains('displayfooterbefore', $this->registeredHooks());
    }

    /**
     * A store that ran a development build of 3.0.0 holds the two hooks that had no listener.
     * The main class has no method for them any more, so the rows are written as that build left
     * them; a migration run takes them away, also the one of a store that has nothing to migrate.
     *
     * @dataProvider migrationRuns
     */
    public function testTheTwoHooksWithoutAListenerLeaveOnAMigrationRun(?string $fixture): void
    {
        if ($fixture === null) {
            Schema::markCurrent();
            \Configuration::deleteByName(Schema::VERSION_KEY);
            LegacyStore::reloadConfiguration();
        } else {
            LegacyStore::loadFixture($fixture);
            $this->recordModuleVersion('2.2.0');
        }
        $module = self::module();
        foreach (['actionCartSave', 'actionObjectCustomerDeleteAfter'] as $name) {
            // PrestaShop lists a hook of an object model only once a module registered it.
            if (!\Hook::getIdByName($name, false)) {
                $hook = new \Hook();
                $hook->name = $name;
                $hook->title = $name;
                self::assertTrue($hook->add());
            }
            self::db()->execute(
                'INSERT INTO `' . _DB_PREFIX_ . 'hook_module` (`id_module`, `id_shop`, `id_hook`, `position`)'
                . ' SELECT ' . (int) $module->id . ', 1, `id_hook`, 99 FROM `' . _DB_PREFIX_ . 'hook` WHERE `name` = \'' . $name . '\''
            );
        }
        self::assertCount(32, $this->registeredHooks());

        self::assertTrue($this->upgrade()->runPending());

        $expected = array_map('strtolower', HookList::HOOKS);
        sort($expected);
        self::assertSame($expected, $this->registeredHooks());
        self::assertCount(30, $this->registeredHooks());
    }

    /**
     * @return array<string, array{string|null}>
     */
    public function migrationRuns(): array
    {
        return [
            'an upgrade from 2.2.0' => ['2.2.0-configured.sql'],
            'a store with nothing to migrate' => [null],
        ];
    }

    /**
     * How the store came to be, and the step that stopped its upgrade (null: the upgrade completed
     * and the store lost its schema version since).
     *
     * @return array<string, array{string|null, string|null}>
     */
    public function laterRuns(): array
    {
        return [
            'installed by 3.0.0, the schema version lost' => [null, null],
            'upgraded from 2.2.0, the schema version lost' => ['2.2.0-configured.sql', null],
            'an upgrade from 2.2.0 that stopped after the hooks step' => ['2.2.0-configured.sql', 'carts'],
        ];
    }

    /**
     * The merchant gave the recommendations block a place in Design > Positions: a display hook
     * that HookList does not name. A later migration run keeps that place.
     *
     * @dataProvider laterRuns
     */
    public function testALaterRunKeepsThePlaceAMerchantGaveTheBlock(?string $fixture, ?string $stoppedAt): void
    {
        if ($fixture === null) {
            Schema::markCurrent();
        } else {
            LegacyStore::loadFixture($fixture);
            $this->recordModuleVersion('2.2.0');
            $first = $this->upgrade(static function (string $step) use ($stoppedAt): void {
                if ($step === $stoppedAt) {
                    throw new \LogicException('injected');
                }
            });
            self::assertSame($stoppedAt === null, $first->runPending());
        }
        try {
            // As the Positions screen does.
            self::assertTrue((bool) self::module()->registerHook('displayHome'));
            \Configuration::deleteByName(Schema::VERSION_KEY);
            LegacyStore::reloadConfiguration();

            self::assertTrue($this->upgrade()->runPending());

            self::assertContains('displayhome', $this->registeredHooks());
        } finally {
            self::module()->unregisterHook('displayHome');
        }
    }

    public function testAStoreWithoutTheMenuGetsItUnderCustomers(): void
    {
        LegacyStore::loadFixture('2.2.0-configured.sql');
        $this->recordModuleVersion('2.2.0');
        MenuTest::removeMenu();

        self::assertTrue($this->upgrade()->runPending());

        self::assertSame([
            ['AdminSmailyConnect', 'AdminParentCustomer', 'smailyforprestashop_index', 'Smaily Connect'],
            ['AdminSmailyConnectDashboard', 'AdminSmailyConnect', 'smailyforprestashop_dashboard', 'Dashboard'],
            ['AdminSmailyConnectSetup', 'AdminSmailyConnect', 'smailyforprestashop_setup', 'Initial setup'],
            ['AdminSmailyConnectSettings', 'AdminSmailyConnect', 'smailyforprestashop_settings', 'Settings'],
            ['AdminSmailyConnectLog', 'AdminSmailyConnect', 'smailyforprestashop_log', 'Log'],
        ], MenuTest::menu());
    }

    public function testTheHiddenAdminTabOf16xIsRemoved(): void
    {
        LegacyStore::loadFixture('1.6.2-configured.sql');
        $this->recordModuleVersion('1.6.2');
        // As 1.6.x installs it: hidden, top-level, with its authorization roles.
        $tab = new \Tab();
        $tab->class_name = 'AdminSmailyforprestashopAjax';
        $tab->module = 'smailyforprestashop';
        $tab->id_parent = 0;
        $tab->active = false;
        foreach (\Language::getLanguages(false) as $language) {
            $tab->name[(int) $language['id_lang']] = 'Smaily for PrestaShop';
        }
        self::assertTrue($tab->add());

        self::assertTrue($this->upgrade()->runPending());

        self::assertSame('0', (string) self::db()->getValue(
            'SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'tab` WHERE `class_name` = \'AdminSmailyforprestashopAjax\'',
            false
        ));
        self::assertSame('0', (string) self::db()->getValue(
            'SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'tab_lang` WHERE `id_tab` = ' . (int) $tab->id,
            false
        ));
        self::assertSame('0', (string) self::db()->getValue(
            'SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'authorization_role` WHERE `slug` LIKE \'ROLE\\_MOD\\_TAB\\_ADMINSMAILYFORPRESTASHOPAJAX\\_%\'',
            false
        ));
    }

    /**
     * @return array<string, array{string}>
     */
    public function multistoreFixtures(): array
    {
        return [
            'shared setup' => ['2.2.0-multistore-shared.sql'],
            'shop 2 with its own rows' => ['2.2.0-multistore-own-rows.sql'],
        ];
    }

    /**
     * @dataProvider multistoreFixtures
     */
    public function testAMultistoreStoreGetsEachShopsSettingsAtTheScopeOfThePlan(string $fixture): void
    {
        LegacyStore::loadFixture($fixture);
        $this->recordModuleVersion('2.2.0');
        $plan = $this->plan('2.2.0');
        self::assertTrue((new LegacyReader())->read('2.2.0')->multishopActive());

        self::assertTrue($this->upgrade()->runPending());

        $this->assertPlanApplied($plan);
        self::assertArrayHasKey(2, $plan->settings(), 'shop 2 differs from the global setup in each case');
    }

    /**
     * Multistore off with a second row in `shop`: PrestaShop writes the global row whatever shop a
     * call names (docs/internal/spikes/platform-facts.md 6.9), so the upgrade plans and writes the
     * global scope alone. The reminder that 2.x sent stays on, shop 2 gets no row, and the dead
     * legacy rows of shop 2 go with the others.
     */
    public function testAStoreWithMultistoreOffAndASecondShopUpgradesAsOneStore(): void
    {
        LegacyStore::loadFixture('2.2.0-multistore-off-two-shops.sql');
        $this->recordModuleVersion('2.2.0');
        $state = (new LegacyReader())->read('2.2.0');
        self::assertFalse($state->multishopActive());
        self::assertSame([1 => 1, 2 => 1], $state->shopGroups());
        $plan = $this->plan('2.2.0');
        $tokens = $this->tokenRows();

        self::assertTrue($this->upgrade()->runPending());

        self::assertSame('done', $this->upgrade()->state()['status']);
        $this->assertPlanApplied($plan);
        self::assertSame([0], array_keys($plan->settings()));
        self::assertSame('1', LegacyStore::row('SMAILY_CONNECT_CART_ENABLED'), 'the reminder that 2.x sent stays on');
        self::assertSame('0', (string) self::db()->getValue(
            'SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'configuration` WHERE `name` LIKE \'SMAILY\\_CONNECT\\_%\''
            . ' AND (`id_shop` IS NOT NULL OR `id_shop_group` IS NOT NULL)',
            false
        ), 'no setting at a shop or a shop group');
        self::assertSame([0], array_map('intval', array_column(self::db()->executeS(
            'SELECT DISTINCT `id_shop` FROM `' . _DB_PREFIX_ . 'smaily_connect_automation_mapping`'
        ) ?: [], 'id_shop')));
        self::assertSame($tokens, $this->tokenRows(), 'every cron token row stays as stored, also the empty one of shop 2');
    }

    public function testTheUpgradeScriptReturnsTrueWhenTheMigrationFails(): void
    {
        LegacyStore::loadFixture('2.2.0-configured.sql');
        $this->recordModuleVersion('2.2.0');
        Services::instance()->set(Upgrade300::class, fn (): Upgrade300 => $this->upgrade(static function (string $step): void {
            if ($step === 'accounts') {
                throw new \LogicException('injected');
            }
        }));

        require_once _PS_MODULE_DIR_ . 'smailyforprestashop/upgrade/upgrade-3.0.0.php';

        self::assertTrue(upgrade_module_3_0_0(self::module()));
        self::assertFalse(Schema::isCurrent());
        self::assertSame(['step' => 'accounts', 'error' => \LogicException::class], $this->upgrade()->state()['failure']);
        self::assertSame(1, LegacyStore::countRows('SMAILY_PASSWORD'));

        (new MigrationProvider())->register(Services::instance());
        self::assertTrue(upgrade_module_3_0_0(self::module()));
        self::assertTrue(Schema::isCurrent());
    }

    /**
     * The reads of what 2.x left, each failing the way a live PrestaShop 9.2.0 store reports it
     * (the driver answers `false`, facts file 6.11), and the step that then fails.
     *
     * @return array<string, array{string, string}>
     */
    public function legacyReads(): array
    {
        return [
            'the legacy settings' => ['/SELECT `id_shop_group`, `id_shop`, `name`, `value` FROM `[^`]+configuration`/', 'notices'],
            'the shops' => ['/SELECT `id_shop`, `id_shop_group` FROM `[^`]+shop`/', 'notices'],
            'the multistore switch' => ['/`name` = \'PS_MULTISHOP_FEATURE_ACTIVE\'/', 'notices'],
            'the count of reminded carts' => ['/SELECT COUNT\(\*\) FROM `[^`]+smaily_cart`/', 'notices'],
        ];
    }

    /**
     * A read of the 2.x state that fails is not an empty store. Read as empty, the plan held no
     * account and no setting, its check passed, and the last step deleted every legacy key: the
     * merchant's credentials and settings were gone, and the reminded carts were not carried over.
     *
     * @dataProvider legacyReads
     */
    public function testAFailedReadOfTheLegacyStateKeepsItAndTheNextRunFinishes(string $failing, string $step): void
    {
        LegacyStore::loadFixture('2.2.0-configured.sql');
        $this->recordModuleVersion('2.2.0');
        $plan = $this->plan('2.2.0');
        self::assertTrue($plan->copySentCarts(), 'the store holds reminded carts');
        $legacy = $this->legacyRows();
        require_once _PS_MODULE_DIR_ . 'smailyforprestashop/upgrade/upgrade-3.0.0.php';
        (new MigrationProvider())->register(Services::instance());

        $failed = FailingQueries::during($failing, static function (): void {
            self::assertTrue(upgrade_module_3_0_0(self::module()), 'the upgrade script never fails outward');
        });

        self::assertGreaterThan(0, $failed);
        self::assertFalse(Schema::isCurrent(), 'the migration stays pending');
        self::assertSame(['step' => $step, 'error' => SqlException::class], $this->upgrade()->state()['failure']);
        self::assertSame($legacy, $this->legacyRows(), 'every legacy key is still there');
        self::assertSame('0', (string) self::db()->getValue('SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'smaily_connect_account`', false));
        self::assertSame('0', (string) self::db()->getValue('SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'smaily_connect_cart`', false));

        self::assertTrue($this->upgrade()->runPending(), 'the next run reads again and finishes');
        $this->assertPlanApplied($plan);
        self::assertSame('2.2.0', $this->upgrade()->state()['from']);
        self::assertSame('done', $this->upgrade()->state()['status']);
    }

    /**
     * The upgrade writes a setting only where its scope holds no row. A count that fails is not
     * zero: read as zero, the upgrade wrote its own value over the one a merchant saved while the
     * upgrade was not finished.
     */
    public function testAFailedCountOfASettingsRowsOverwritesNothing(): void
    {
        LegacyStore::loadFixture('2.2.0-configured.sql');
        $this->recordModuleVersion('2.2.0');
        $key = (string) array_key_first($this->plan('2.2.0')->settings()[0]);
        LegacyStore::insertRows([[null, null, $key, 'saved-by-the-merchant']]);

        $failed = FailingQueries::during('/SELECT COUNT\(\*\) FROM `[^`]+configuration` WHERE `name` = \'' . $key . '\'/', function (): void {
            self::assertFalse($this->upgrade()->runPending());
        });

        self::assertSame(1, $failed);
        self::assertSame(['step' => 'settings', 'error' => SqlException::class], $this->upgrade()->state()['failure']);
        self::assertSame('saved-by-the-merchant', LegacyStore::row($key));
        self::assertSame(1, LegacyStore::countRows('SMAILY_PASSWORD'));

        self::assertTrue($this->upgrade()->runPending());
        self::assertSame('saved-by-the-merchant', LegacyStore::row($key));
        self::assertSame(1, LegacyStore::countRows($key));
    }

    /**
     * An empty cron token is replaced in its row. A count of that row that fails is not zero: read
     * as zero, the upgrade added a second row for the token.
     */
    public function testAFailedCountOfATokensRowsAddsNoSecondRow(): void
    {
        LegacyStore::loadFixture('1.6.2-configured.sql');
        self::db()->execute(
            'UPDATE `' . _DB_PREFIX_ . 'configuration` SET `value` = \'\' WHERE `name` = \'SMAILY_CART_CRON_TOKEN\''
        );
        LegacyStore::reloadConfiguration();
        $this->recordModuleVersion('1.6.2');

        $failed = FailingQueries::during('/SELECT COUNT\(\*\) FROM `[^`]+configuration` WHERE `name` = \'SMAILY_CART_CRON_TOKEN\'/', function (): void {
            self::assertFalse($this->upgrade()->runPending());
        });

        self::assertSame(1, $failed);
        self::assertSame(['step' => 'tokens', 'error' => SqlException::class], $this->upgrade()->state()['failure']);
        self::assertSame(1, LegacyStore::countRows('SMAILY_CART_CRON_TOKEN'));
        self::assertSame('', (string) LegacyStore::row('SMAILY_CART_CRON_TOKEN'));

        self::assertTrue($this->upgrade()->runPending());
        self::assertSame(1, LegacyStore::countRows('SMAILY_CART_CRON_TOKEN'));
        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', (string) LegacyStore::row('SMAILY_CART_CRON_TOKEN'));
    }

    /**
     * A store that only lost its schema version is told from a store to migrate by reads. When one
     * fails, the run decides nothing and never throws.
     */
    public function testAFailedReadBeforeTheStepsDecidesNothing(): void
    {
        LegacyStore::loadFixture('2.2.0-configured.sql');
        $this->recordModuleVersion('3.0.0');
        $before = $this->everything();

        $failed = FailingQueries::during('/SELECT `version` FROM `[^`]+module`/', function (): void {
            self::assertFalse($this->upgrade()->runPending());
        });

        self::assertSame(1, $failed);
        self::assertSame($before, $this->everything());
    }

    /**
     * Starts migration-worker.php with $arguments in a process of its own.
     *
     * @param list<string> $arguments
     * @param array<int, resource>|null $pipes its standard input, output and error
     *
     * @return resource
     */
    private static function worker(array $arguments, ?array &$pipes)
    {
        $process = proc_open(
            array_merge([PHP_BINARY, __DIR__ . '/migration-worker.php'], $arguments),
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );
        self::assertIsResource($process);

        return $process;
    }

    /**
     * @param resource $process
     * @param array<int, resource> $pipes
     */
    private static function closeWorker($process, array $pipes): void
    {
        foreach ($pipes as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }
        proc_close($process);
    }

    private function upgrade(?callable $beforeStep = null): Upgrade300
    {
        $services = Services::instance();

        return new Upgrade300(
            new LegacyReader(),
            new LegacyConfigMapper(),
            $services->get(AccountStore::class),
            new MigrationNotices(),
            new LegacyOverride(_PS_OVERRIDE_DIR_),
            new LegacyFiles($this->moduleDir),
            $beforeStep
        );
    }

    /**
     * @param list<string> $paths files that 2.2.0 or 1.6.2 shipped, relative to the module's folder
     */
    private function placeLegacyFiles(array $paths): void
    {
        self::assertSame([], array_values(array_diff($paths, LegacyFiles::FILES)));
        $this->placeFiles($paths);
    }

    /**
     * @param list<string> $paths
     */
    private function placeFiles(array $paths): void
    {
        foreach ($paths as $path) {
            $file = $this->moduleDir . '/' . $path;
            if (!is_dir(dirname($file))) {
                mkdir(dirname($file), 0777, true);
            }
            file_put_contents($file, "<?php\n");
        }
    }

    /**
     * @return list<string> the listed files that the folder holds
     */
    private function legacyFiles(): array
    {
        return array_values(array_filter(
            LegacyFiles::FILES,
            fn (string $path): bool => file_exists($this->moduleDir . '/' . $path)
        ));
    }

    /**
     * @return list<string> every file of the folder, relative to it
     */
    private function moduleFiles(): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->moduleDir, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            $files[] = substr($file->getPathname(), strlen($this->moduleDir) + 1);
        }
        sort($files);

        return $files;
    }

    private function removeModuleDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        chmod($dir, 0755);
        foreach (array_diff((array) scandir($dir), ['.', '..']) as $name) {
            is_dir($dir . '/' . $name) ? $this->removeModuleDir($dir . '/' . $name) : unlink($dir . '/' . $name);
        }
        rmdir($dir);
    }

    private function plan(string $from): MigrationPlan
    {
        return (new LegacyConfigMapper())->map((new LegacyReader())->read($from));
    }

    private function recordModuleVersion(string $version): void
    {
        self::db()->execute(
            'UPDATE `' . _DB_PREFIX_ . 'module` SET `version` = \'' . pSQL($version) . '\' WHERE `name` = \'smailyforprestashop\''
        );
    }

    private function assertPlanApplied(MigrationPlan $plan): void
    {
        self::assertTrue(Schema::isCurrent());
        foreach ($plan->settings() as $idShop => $settings) {
            foreach ($settings as $key => $value) {
                $stored = $idShop === 0 ? LegacyStore::row($key) : LegacyStore::row($key, 1, $idShop);
                self::assertSame($value, (string) $stored, sprintf('%s at shop %d', $key, $idShop));
            }
        }

        $store = Services::instance()->get(AccountStore::class);
        foreach ($plan->accounts() as $account) {
            $stored = $store->find($account['id_shop'], $account['language']);
            self::assertNotNull($stored);
            self::assertSame([$account['subdomain'], $account['username']], [$stored->subdomain(), $stored->username()]);
            self::assertTrue(hash_equals($plan->passwordToEncrypt($account['id_shop']), $stored->password()), 'the password decrypts');
        }
        self::assertSame(count($plan->accounts()), (int) self::db()->getValue(
            'SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'smaily_connect_account`',
            false
        ));

        $mappings = array_map(static function (array $row): array {
            return [
                'id_shop' => (int) $row['id_shop'],
                'trigger' => $row['trigger'],
                'language' => $row['language'],
                'account_key' => $row['account_key'],
                'workflow_id' => $row['workflow_id'] === null ? null : (int) $row['workflow_id'],
                'optin_trigger' => (int) $row['optin_trigger'],
                'is_default_fallback' => (int) $row['is_default_fallback'],
            ];
        }, self::db()->executeS(
            'SELECT `id_shop`, `trigger`, `language`, `account_key`, `workflow_id`, `optin_trigger`, `is_default_fallback`'
            . ' FROM `' . _DB_PREFIX_ . 'smaily_connect_automation_mapping` ORDER BY `id`'
        ) ?: []);
        self::assertSame($plan->mappings(), $mappings);

        $legacyCarts = self::db()->executeS('SHOW TABLES LIKE \'' . _DB_PREFIX_ . 'smaily\\_cart\'')
            ? (int) self::db()->getValue('SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'smaily_cart` WHERE `id_cart` IS NOT NULL', false)
            : 0;
        self::assertSame($legacyCarts, (int) self::db()->getValue(
            'SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'smaily_connect_cart` WHERE `status` = \'mailed\'',
            false
        ));

        foreach ($plan->legacyKeysToDelete() as $key) {
            self::assertSame(0, LegacyStore::countRows($key), $key . ' is deleted');
        }
        foreach (self::TOKENS as $key) {
            self::assertGreaterThan(0, LegacyStore::countRows($key), $key . ' stays');
        }
        self::assertSame(1, LegacyStore::countRows(TickSettings::TICK_TOKEN), 'the tick URL has its token');
        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', (string) LegacyStore::row(TickSettings::TICK_TOKEN));

        $stored = $this->notices();
        foreach ($plan->notices() as $notice) {
            self::assertContains($notice, $stored);
        }
    }

    /**
     * @return list<array{code: string, params: array<string, int|string>}>
     */
    private function notices(): array
    {
        return array_map(
            static fn (array $notice): array => ['code' => $notice['code'], 'params' => $notice['params']],
            (new MigrationNotices())->all()
        );
    }

    /**
     * @return array<int, array<string, string|null>>
     */
    private function tokenRows(): array
    {
        return self::db()->executeS(
            'SELECT `id_shop_group`, `id_shop`, `name`, `value` FROM `' . _DB_PREFIX_ . 'configuration`'
            . ' WHERE `name` IN (\'' . implode('\', \'', self::TOKENS) . '\') ORDER BY `id_configuration`'
        ) ?: [];
    }

    /**
     * @return array<int, array<string, string|null>>
     */
    private function legacyRows(): array
    {
        return self::masked(self::db()->executeS(
            'SELECT `id_shop_group`, `id_shop`, `name`, `value` FROM `' . _DB_PREFIX_ . 'configuration`'
            . ' WHERE `name` IN (\'' . implode('\', \'', LegacyState::KEYS) . '\') ORDER BY `id_configuration`'
        ) ?: []);
    }

    /**
     * Configuration rows with the value of SMAILY_PASSWORD replaced by its hash, so that a failing
     * comparison cannot print it.
     *
     * @param array<int, array<string, mixed>> $rows
     *
     * @return array<int, array<string, mixed>>
     */
    private static function masked(array $rows): array
    {
        foreach ($rows as &$row) {
            if ($row['name'] === 'SMAILY_PASSWORD') {
                $row['value'] = sha1((string) $row['value']);
            }
        }

        return $rows;
    }

    /**
     * What the migration produces, without row ids, dates, the migration's own progress record, the
     * ciphertexts (each encryption differs) and the tick token (each run creates another); passwords
     * as hashes.
     *
     * @return array<string, mixed>
     */
    private function snapshot(): array
    {
        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', (string) LegacyStore::row(TickSettings::TICK_TOKEN));
        $accounts = [];
        $store = Services::instance()->get(AccountStore::class);
        foreach (self::db()->executeS('SELECT `id_shop`, `language` FROM `' . _DB_PREFIX_ . 'smaily_connect_account` ORDER BY `id_shop`') ?: [] as $row) {
            $account = $store->find((int) $row['id_shop'], $row['language']);
            $accounts[] = [$account->idShop(), $account->language(), $account->subdomain(), $account->username(), sha1($account->password())];
        }

        return [
            'configuration' => self::masked(self::db()->executeS(
                'SELECT `id_shop_group`, `id_shop`, `name`, `value` FROM `' . _DB_PREFIX_ . 'configuration`'
                . ' WHERE `name` LIKE \'SMAILY\\_%\' AND `name` NOT IN (\'' . Upgrade300::STATE_KEY . '\', \'' . TickSettings::TICK_TOKEN . '\')'
                . ' ORDER BY `name`, `id_shop_group`, `id_shop`'
            ) ?: []),
            'accounts' => $accounts,
            'mappings' => self::db()->executeS(
                'SELECT `id_shop`, `trigger`, `language`, `account_key`, `workflow_id`, `optin_trigger`, `is_default_fallback`'
                . ' FROM `' . _DB_PREFIX_ . 'smaily_connect_automation_mapping` ORDER BY `id_shop`, `trigger`'
            ),
            'carts' => self::db()->executeS('SELECT * FROM `' . _DB_PREFIX_ . 'smaily_connect_cart` ORDER BY `id_cart`'),
            'hooks' => $this->registeredHooks(),
            'menu' => MenuTest::menu(),
        ];
    }

    /**
     * Every row the module owns, with dates and ciphertexts.
     *
     * @return array<string, mixed>
     */
    private function everything(): array
    {
        $tables = [];
        foreach (Schema::tables() as $table) {
            $tables[$table] = self::db()->executeS('SELECT * FROM `' . _DB_PREFIX_ . $table . '`');
        }

        return [
            'configuration' => self::masked(self::db()->executeS(
                'SELECT * FROM `' . _DB_PREFIX_ . 'configuration` WHERE `name` LIKE \'SMAILY\\_%\' ORDER BY `id_configuration`'
            ) ?: []),
            'tables' => $tables,
            'hooks' => $this->registeredHooks(),
            'menu' => MenuTest::menu(),
        ];
    }

    /**
     * Every row the module owns but the schema version and the migration's progress record, and
     * the rows of the legacy `smaily_cart`.
     *
     * @return array<string, mixed>
     */
    private function held(): array
    {
        $held = $this->everything();
        $held['configuration'] = array_values(array_filter(
            $held['configuration'],
            static fn (array $row): bool => !in_array($row['name'], [Schema::VERSION_KEY, Upgrade300::STATE_KEY], true)
        ));
        $held['smaily_cart'] = self::db()->executeS('SHOW TABLES LIKE \'' . _DB_PREFIX_ . 'smaily\\_cart\'')
            ? self::db()->executeS('SELECT * FROM `' . _DB_PREFIX_ . 'smaily_cart` ORDER BY `id_smaily_cart`')
            : null;

        return $held;
    }

    /**
     * @return list<string> lower case
     */
    private function registeredHooks(): array
    {
        $rows = self::db()->executeS(
            'SELECT DISTINCT h.`name` FROM `' . _DB_PREFIX_ . 'hook_module` hm'
            . ' JOIN `' . _DB_PREFIX_ . 'hook` h ON h.`id_hook` = hm.`id_hook`'
            . ' WHERE hm.`id_module` = ' . (int) self::module()->id . ' ORDER BY h.`name`'
        ) ?: [];

        return array_map('strtolower', array_column($rows, 'name'));
    }

    private function placeOverride(string $content): void
    {
        $file = _PS_OVERRIDE_DIR_ . LegacyOverride::RELATIVE_PATH;
        if (!is_dir(dirname($file))) {
            mkdir(dirname($file), 0755, true);
        }
        file_put_contents($file, $content);
    }

    private function removeOverride(): void
    {
        $file = _PS_OVERRIDE_DIR_ . LegacyOverride::RELATIVE_PATH;
        if (is_file($file)) {
            unlink($file);
        }
        if (is_dir(dirname($file)) && scandir(dirname($file)) === ['.', '..']) {
            rmdir(dirname($file));
        }
    }
}
