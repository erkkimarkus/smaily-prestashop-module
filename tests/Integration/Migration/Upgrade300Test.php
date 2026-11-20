<?php
declare(strict_types=1);

namespace Tests\Integration\Migration;

use PrestaShop\Module\SmailyForPrestaShop\Config\MigrationNotices;
use PrestaShop\Module\SmailyForPrestaShop\Install\HookList;
use PrestaShop\Module\SmailyForPrestaShop\Install\Schema;
use PrestaShop\Module\SmailyForPrestaShop\Migration\LegacyConfigMapper;
use PrestaShop\Module\SmailyForPrestaShop\Migration\LegacyOverride;
use PrestaShop\Module\SmailyForPrestaShop\Migration\LegacyReader;
use PrestaShop\Module\SmailyForPrestaShop\Migration\LegacyState;
use PrestaShop\Module\SmailyForPrestaShop\Migration\MigrationPlan;
use PrestaShop\Module\SmailyForPrestaShop\Migration\Upgrade300;
use PrestaShop\Module\SmailyForPrestaShop\Runtime\Provider\MigrationProvider;
use PrestaShop\Module\SmailyForPrestaShop\Runtime\Services;
use PrestaShop\Module\SmailyForPrestaShop\Smaily\AccountStore;
use Tests\Integration\IntegrationTestCase;

require_once __DIR__ . '/LegacyStore.php';

/**
 * A store that 2.x or 1.6.x left upgrades in place: the plan's settings, accounts, mapping rows and
 * reminded carts exist afterwards, the legacy keys but the two cron tokens are gone, and a migration
 * that stops at any step leaves the legacy state and an inert runtime until a later run completes it.
 *
 * No assertion compares the password itself, so a failure cannot print it.
 */
final class Upgrade300Test extends IntegrationTestCase
{
    private const PASSWORD = 'not-a-real-password';

    private const TOKENS = ['SMAILY_CUSTOMER_CRON_TOKEN', 'SMAILY_CART_CRON_TOKEN'];

    protected function setUp(): void
    {
        parent::setUp();
        LegacyStore::clear();
        $this->removeOverride();
    }

    protected function tearDown(): void
    {
        if (defined('_PS_ROOT_DIR_')) {
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

    public function testHooksOf16xAreSwappedForTheHookList(): void
    {
        LegacyStore::loadFixture('1.6.2-configured.sql');
        $this->recordModuleVersion('1.6.2');
        $module = self::module();
        $module->unregisterHook('actionCartSave');
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
            $beforeStep
        );
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
     * What the migration produces, without row ids, dates, the migration's own progress record and
     * the ciphertexts (each encryption differs); passwords as hashes.
     *
     * @return array<string, mixed>
     */
    private function snapshot(): array
    {
        $accounts = [];
        $store = Services::instance()->get(AccountStore::class);
        foreach (self::db()->executeS('SELECT `id_shop`, `language` FROM `' . _DB_PREFIX_ . 'smaily_connect_account` ORDER BY `id_shop`') ?: [] as $row) {
            $account = $store->find((int) $row['id_shop'], $row['language']);
            $accounts[] = [$account->idShop(), $account->language(), $account->subdomain(), $account->username(), sha1($account->password())];
        }

        return [
            'configuration' => self::masked(self::db()->executeS(
                'SELECT `id_shop_group`, `id_shop`, `name`, `value` FROM `' . _DB_PREFIX_ . 'configuration`'
                . ' WHERE (`name` LIKE \'SMAILY\\_%\' AND `name` <> \'' . Upgrade300::STATE_KEY . '\')'
                . ' ORDER BY `name`, `id_shop_group`, `id_shop`'
            ) ?: []),
            'accounts' => $accounts,
            'mappings' => self::db()->executeS(
                'SELECT `id_shop`, `trigger`, `language`, `account_key`, `workflow_id`, `optin_trigger`, `is_default_fallback`'
                . ' FROM `' . _DB_PREFIX_ . 'smaily_connect_automation_mapping` ORDER BY `id_shop`, `trigger`'
            ),
            'carts' => self::db()->executeS('SELECT * FROM `' . _DB_PREFIX_ . 'smaily_connect_cart` ORDER BY `id_cart`'),
            'hooks' => $this->registeredHooks(),
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
        ];
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
