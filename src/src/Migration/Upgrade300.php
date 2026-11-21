<?php
/**
 * 2024 Smaily
 *
 * NOTICE OF LICENSE
 *
 * Smaily for PrestaShop is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * any later version.
 *
 * Smaily for PrestaShop is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with Smaily for PrestaShop. If not, see <http://www.gnu.org/licenses/>.
 *
 * @author    Smaily <info@smaily.com>
 * @copyright 2024 Smaily
 * @license   GPL3
 */
declare(strict_types=1);

namespace PrestaShop\Module\SmailyForPrestaShop\Migration;

if (!defined('_PS_VERSION_')) {
    exit;
}

use PrestaShop\Module\SmailyForPrestaShop\Admin\Menu;
use PrestaShop\Module\SmailyForPrestaShop\Config\MigrationNotices;
use PrestaShop\Module\SmailyForPrestaShop\Config\Settings;
use PrestaShop\Module\SmailyForPrestaShop\Config\TickSettings;
use PrestaShop\Module\SmailyForPrestaShop\Database\Sql;
use PrestaShop\Module\SmailyForPrestaShop\Install\HookList;
use PrestaShop\Module\SmailyForPrestaShop\Install\Schema;
use PrestaShop\Module\SmailyForPrestaShop\Runtime\Log;
use PrestaShop\Module\SmailyForPrestaShop\Scheduler\Lock;
use PrestaShop\Module\SmailyForPrestaShop\Smaily\Account;
use PrestaShop\Module\SmailyForPrestaShop\Smaily\AccountStore;

/**
 * Applies the 3.0.0 migration plan that LegacyConfigMapper derives from what 2.x or 1.6.x left.
 *
 * PrestaShop stores the new module version even when an upgrade script fails, so the upgrade script
 * never fails: it calls runPending(), and so can anything else that finds the migration pending
 * (files copied by FTP are never upgraded by PrestaShop until the admin asks). runPending() is
 * idempotent, runs under a database lock of its own so that one run works at a time, and runs the
 * steps in STEPS order. Each step but `tables` and `hooks` (which always run)
 * is skipped once it has succeeded. A failed step stops the run and records its name and the class of
 * the error, never the error's message. The last step deletes the legacy keys and marks the schema
 * current in one transaction; until then the runtime stays inert and every legacy key stays in
 * place. `smaily_cart` stays (a later release drops it).
 *
 * A database query that fails is a failed step, never an empty answer (Database\Sql): a read of
 * what 2.x left that fails makes no plan, so no legacy key is deleted on it, and the next run reads
 * again.
 *
 * A run never resets a store in use. A store that has nothing to migrate (3.0.0 installed it, or
 * its migration completed) and has lost its schema version gets only its tables, hooks and schema
 * version back. Where the plan is applied, a setting, a mapping row and an account are written
 * only where the store holds none, a cron token only where it is empty or missing, and a reminded
 * cart only where the cart has no row.
 */
final class Upgrade300
{
    public const STATE_KEY = 'SMAILY_CONNECT_MIGRATION';

    public const STEPS = [
        'tables',
        'hooks',
        'menu',
        'notices',
        'settings',
        'accounts',
        'mappings',
        'tokens',
        'tick_token',
        'carts',
        'override',
        'tab',
        'verify',
        'finish',
    ];

    private const ALWAYS = ['tables', 'hooks'];

    private const MODULE = 'smailyforprestashop';

    private const LOCK = 'migration';

    /** The hidden admin tab of the AJAX controller of 1.6.x, which 3.0.0 does not have. */
    private const LEGACY_TAB = 'AdminSmailyforprestashopAjax';

    private LegacyReader $reader;

    private LegacyConfigMapper $mapper;

    private AccountStore $accounts;

    private MigrationNotices $notices;

    private LegacyOverride $override;

    /** @var callable(string): void|null */
    private $beforeStep;

    private ?MigrationPlan $plan = null;

    private string $from = '';

    /**
     * @param callable(string): void|null $beforeStep called with each step's name before it runs
     */
    public function __construct(
        LegacyReader $reader,
        LegacyConfigMapper $mapper,
        AccountStore $accounts,
        MigrationNotices $notices,
        LegacyOverride $override,
        ?callable $beforeStep = null
    ) {
        $this->reader = $reader;
        $this->mapper = $mapper;
        $this->accounts = $accounts;
        $this->notices = $notices;
        $this->override = $override;
        $this->beforeStep = $beforeStep;
    }

    /**
     * Runs the migration unless the schema is current already. Never throws. While another run holds
     * the migration lock, it returns at once without work.
     *
     * @return bool true when the schema is current afterwards
     */
    public function runPending(): bool
    {
        if (Schema::isCurrent()) {
            return true;
        }

        $lock = new Lock(self::LOCK);
        if (!$lock->acquire()) {
            return false;
        }
        try {
            // Another run may have completed the migration since this request loaded the configuration.
            self::reloadConfiguration();

            return Schema::isCurrent() || ($this->nothingToMigrate() ? $this->restore() : $this->run());
        } catch (\Throwable $error) {
            // What the store holds could not be read. Nothing is decided on it: the next run asks again.
            Log::failure('the 3.0.0 migration', $error);

            return false;
        } finally {
            $lock->release();
        }
    }

    /**
     * Whether the store holds nothing of 2.x or 1.6.x to migrate: its migration completed, or none
     * ever started, PrestaShop records a 3.x version of the module and no legacy key is stored but
     * the two cron tokens (3.0.0 installed that store). A plan made now would hold the settings of a
     * new store, and applying it would reset what the merchant has set up.
     */
    private function nothingToMigrate(): bool
    {
        $status = $this->state()['status'] ?? null;
        if ($status !== null) {
            return $status === 'done';
        }
        $version = $this->reader->moduleVersion();
        if (version_compare($version, '3.0.0', '<')) {
            return false;
        }
        foreach ($this->reader->read($version)->rows() as $row) {
            if (!in_array($row['name'], [TickSettings::CUSTOMER_CRON_TOKEN, TickSettings::CART_CRON_TOKEN], true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Gives a store that has nothing to migrate its tables, its hooks and its schema version back.
     * It writes nothing else: no setting, no token, no notice and no progress record.
     */
    private function restore(): bool
    {
        try {
            $this->stepTables();
            $this->stepHooks();
            self::check(Schema::markCurrent());
        } catch (\Throwable $error) {
            \PrestaShopLogger::addLog(
                sprintf('Smaily Connect: the schema version was not restored (%s)', get_class($error)),
                3
            );

            return false;
        }

        return true;
    }

    private function run(): bool
    {
        $state = $this->state();
        $step = 'start';
        try {
            // PrestaShop records the new version once the upgrade script has run, so the previous
            // version is kept from the first attempt.
            $state['from'] = $state['from'] ?? $this->reader->moduleVersion();
            $state['status'] = 'pending';
            $this->saveState($state);
            $this->from = $state['from'];
            $this->plan = null;

            foreach (self::STEPS as $step) {
                if (in_array($step, $state['done'], true) && !in_array($step, self::ALWAYS, true)) {
                    continue;
                }
                if ($this->beforeStep !== null) {
                    ($this->beforeStep)($step);
                }
                $this->{'step' . str_replace('_', '', ucwords($step, '_'))}();
                if (!in_array($step, $state['done'], true)) {
                    $state['done'][] = $step;
                }
                $this->saveState($state);
            }
        } catch (\Throwable $error) {
            $state['status'] = 'failed';
            $state['failure'] = ['step' => $step, 'error' => get_class($error)];
            $this->saveState($state);
            \PrestaShopLogger::addLog(
                sprintf('Smaily Connect: the 3.0.0 migration stopped at step %s with %s', $step, get_class($error)),
                3
            );

            return false;
        }

        $state['status'] = 'done';
        $state['failure'] = null;
        $this->saveState($state);

        return true;
    }

    /**
     * The migration's progress as recorded: the previous module version (`from`), `status` (absent
     * before the first attempt, then `pending`, `failed` or `done`), the steps `done`, and the
     * `failure` of the last failed attempt as its step and error class.
     *
     * @return array{from?: string, status?: string, done: list<string>, failure?: array{step: string, error: string}|null}
     */
    public function state(): array
    {
        $state = json_decode((string) \Configuration::getGlobalValue(self::STATE_KEY), true);
        $state = is_array($state) ? $state : [];
        $state['done'] = $state['done'] ?? [];

        return $state;
    }

    /**
     * @param array<string, mixed> $state
     */
    private function saveState(array $state): void
    {
        \Configuration::updateGlobalValue(self::STATE_KEY, (string) json_encode($state));
    }

    private function plan(): MigrationPlan
    {
        if ($this->plan === null) {
            $this->plan = $this->mapper->map($this->reader->read($this->from));
        }

        return $this->plan;
    }

    private function stepTables(): void
    {
        self::check(Schema::create());
    }

    /**
     * Registers every hook of HookList and unregisters the ones it does not name, such as the
     * display hooks of 1.6.x.
     */
    private function stepHooks(): void
    {
        $module = \Module::getInstanceByName(self::MODULE);
        self::check($module instanceof \Module && $module->id);
        self::check((bool) $module->registerHook(HookList::HOOKS));

        $wanted = array_map('strtolower', HookList::HOOKS);
        $registered = Sql::rows(
            'SELECT DISTINCT h.`name` FROM `' . _DB_PREFIX_ . 'hook_module` hm'
            . ' JOIN `' . _DB_PREFIX_ . 'hook` h ON h.`id_hook` = hm.`id_hook`'
            . ' WHERE hm.`id_module` = ' . (int) $module->id
        );
        foreach (array_column($registered, 'name') as $hook) {
            if (!in_array(strtolower($hook), $wanted, true)) {
                self::check((bool) $module->unregisterHook($hook));
            }
        }
    }

    /**
     * Adds the admin menu of the main class's $tabs: PrestaShop installs a module's tabs on install
     * only, not on an upgrade.
     */
    private function stepMenu(): void
    {
        $module = \Module::getInstanceByName(self::MODULE);
        self::check($module instanceof \Module && Menu::install($module->getTabs()));
    }

    private function stepNotices(): void
    {
        foreach ($this->plan()->notices() as $notice) {
            $this->notices->add($notice['code'], $notice['params']);
        }
    }

    /**
     * Writes each setting at the scope the plan names: shop 0 is the global row, any other shop its
     * own row in its shop group. A scope that holds a row for the key keeps it: a merchant saved it
     * while the upgrade was not finished. A shop's row is written only while multistore is on: with
     * multistore off PrestaShop would write the global row in its place, and the plan names no shop
     * then.
     */
    private function stepSettings(): void
    {
        foreach ($this->plan()->settings() as $idShop => $settings) {
            foreach ($settings as $key => $value) {
                if (self::settingRows($key, $idShop) > 0) {
                    continue;
                }
                if ($idShop === 0) {
                    self::check(\Configuration::updateGlobalValue($key, $value));
                } else {
                    $idShopGroup = (int) \Shop::getGroupFromShop($idShop);
                    self::check($idShopGroup > 0 && \Shop::isFeatureActive());
                    self::check(\Configuration::updateValue($key, $value, false, $idShopGroup, $idShop));
                }
            }
        }
    }

    /**
     * Saves each account of the plan at a shop and language that hold none. An account that the
     * store holds there stays as it is: a merchant saved it while the upgrade was not finished, and
     * the 2.x account of that scope is then not migrated. An account that this step writes must
     * read back as the legacy one, password included, or it is removed again and the step fails.
     */
    private function stepAccounts(): void
    {
        $plan = $this->plan();
        foreach ($plan->accounts() as $account) {
            if ($this->accounts->find($account['id_shop'], $account['language']) !== null) {
                continue;
            }
            $password = $plan->passwordToEncrypt($account['id_shop']);
            self::check($this->accounts->save(new Account(
                $account['id_shop'],
                $account['language'],
                $account['subdomain'],
                $account['username'],
                $password
            )));
            $stored = $this->accounts->find($account['id_shop'], $account['language']);
            if ($stored === null
                || $stored->subdomain() !== $account['subdomain']
                || $stored->username() !== $account['username']
                || !hash_equals($password, $stored->password())) {
                $this->accounts->delete($account['id_shop'], $account['language']);
                self::check(false);
            }
        }
    }

    /**
     * Adds each mapping row of the plan; a row that the store holds for the same shop, trigger,
     * language and account stays as it is.
     */
    private function stepMappings(): void
    {
        foreach ($this->plan()->mappings() as $row) {
            Sql::write(
                'INSERT IGNORE INTO `' . _DB_PREFIX_ . 'smaily_connect_automation_mapping`'
                . ' (`id_shop`, `trigger`, `language`, `account_key`, `workflow_id`, `optin_trigger`, `is_default_fallback`, `date_upd`)'
                . ' VALUES (' . $row['id_shop']
                . ', \'' . pSQL($row['trigger']) . '\''
                . ', \'' . pSQL($row['language']) . '\''
                . ', \'' . pSQL($row['account_key']) . '\''
                . ', ' . ($row['workflow_id'] === null ? 'NULL' : $row['workflow_id'])
                . ', ' . $row['optin_trigger']
                . ', ' . $row['is_default_fallback']
                . ', NOW())'
            );
        }
    }

    /**
     * Gives each legacy cron token row that the plan names a new random token, at the scope it is
     * stored at. A token that is not empty is never changed: merchants' crontabs carry it.
     */
    private function stepTokens(): void
    {
        foreach ($this->plan()->tokensToGenerate() as $token) {
            $scope = ' WHERE `name` = \'' . pSQL($token['key']) . '\''
                . ' AND `id_shop_group` ' . ($token['id_shop_group'] === null ? 'IS NULL' : '= ' . $token['id_shop_group'])
                . ' AND `id_shop` ' . ($token['id_shop'] === null ? 'IS NULL' : '= ' . $token['id_shop']);
            $value = bin2hex(random_bytes(16));
            if (Sql::count('SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'configuration`' . $scope) > 0) {
                Sql::write(
                    'UPDATE `' . _DB_PREFIX_ . 'configuration` SET `value` = \'' . $value . '\', `date_upd` = NOW()'
                    . $scope . ' AND (`value` IS NULL OR `value` = \'\')'
                );
            } else {
                Sql::write(
                    'INSERT INTO `' . _DB_PREFIX_ . 'configuration` (`id_shop_group`, `id_shop`, `name`, `value`, `date_add`, `date_upd`)'
                    . ' VALUES (' . ($token['id_shop_group'] ?? 'NULL') . ', ' . ($token['id_shop'] ?? 'NULL')
                    . ', \'' . pSQL($token['key']) . '\', \'' . $value . '\', NOW(), NOW())'
                );
            }
        }
        self::reloadConfiguration();
    }

    /**
     * Creates the tick URL's token unless one is stored, so that the Dashboard has the cron line
     * of an upgraded store without a first request to the tick URL.
     */
    private function stepTickToken(): void
    {
        (new TickSettings())->token(TickSettings::TICK_TOKEN, 0);
    }

    /**
     * Carries every reminder that `smaily_cart` records into `smaily_connect_cart` as mailed, so no
     * shopper gets a second reminder for the same cart. A row without a cart id names no cart and
     * is left out; a cart that no longer exists keeps the default shop and language. Two cron runs
     * of the previous code that overlapped could each remind one cart and each record it: such a
     * cart keeps the latest of its send times, which the rule of one reminder per 24 hours counts
     * from.
     */
    private function stepCarts(): void
    {
        if (!$this->plan()->copySentCarts()) {
            return;
        }
        Sql::write(
            'INSERT IGNORE INTO `' . _DB_PREFIX_ . 'smaily_connect_cart`'
            . ' (`id_cart`, `id_shop`, `id_customer`, `id_lang`, `status`, `last_activity_at`, `mailed_at`)'
            . ' SELECT sc.`id_cart`, COALESCE(c.`id_shop`, ' . (int) \Configuration::get('PS_SHOP_DEFAULT') . '), sc.`id_customer`,'
            . ' COALESCE(c.`id_lang`, ' . (int) \Configuration::get('PS_LANG_DEFAULT') . '), \'mailed\', sc.`date_sent`, sc.`date_sent`'
            . ' FROM `' . _DB_PREFIX_ . 'smaily_cart` sc'
            . ' LEFT JOIN `' . _DB_PREFIX_ . 'cart` c ON c.`id_cart` = sc.`id_cart`'
            . ' WHERE sc.`id_cart` IS NOT NULL ORDER BY sc.`date_sent` DESC, sc.`id_smaily_cart` DESC'
        );
    }

    private function stepOverride(): void
    {
        if ($this->override->removeIfShipped() === LegacyOverride::FOREIGN) {
            $this->notices->add(MigrationNotices::OVERRIDE_LEFT_IN_PLACE, ['path' => 'override/' . LegacyOverride::RELATIVE_PATH]);
        }
    }

    /**
     * Deletes the hidden admin tab that 1.6.x installed, with its authorization roles.
     */
    private function stepTab(): void
    {
        $id = (int) Sql::value(
            'SELECT `id_tab` FROM `' . _DB_PREFIX_ . 'tab` WHERE `class_name` = \'' . self::LEGACY_TAB . '\''
        );
        if ($id > 0) {
            self::check((bool) (new \Tab($id))->delete());
        }
    }

    /**
     * Every shop and language of a legacy account holds an account whose password decrypts, and
     * every setting holds one row in the table at its scope, before the legacy keys go. The account
     * is the legacy one, which stepAccounts() compared with the plan when it wrote it, or the one
     * the store holds in its place; the setting's value is the plan's, or the one the store held
     * before (stepSettings()).
     */
    private function stepVerify(): void
    {
        $plan = $this->plan();
        foreach ($plan->accounts() as $account) {
            $stored = $this->accounts->find($account['id_shop'], $account['language']);
            self::check($stored !== null && $stored->password() !== '');
        }
        foreach ($plan->settings() as $idShop => $settings) {
            foreach (array_keys($settings) as $key) {
                self::check(self::settingRows($key, $idShop) === 1);
            }
        }
    }

    /**
     * The number of rows that the table holds for a setting at exactly one scope: the global one
     * (shop 0) or a shop's own.
     */
    private static function settingRows(string $key, int $idShop): int
    {
        $scope = $idShop === 0
            ? '`id_shop_group` IS NULL AND `id_shop` IS NULL'
            : '`id_shop_group` = ' . (int) \Shop::getGroupFromShop($idShop) . ' AND `id_shop` = ' . $idShop;

        return Sql::count(
            'SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'configuration` WHERE `name` = \'' . pSQL($key) . '\' AND ' . $scope
        );
    }

    /**
     * Deletes every legacy key but the two cron tokens and then marks the schema current, in one
     * database transaction: a failure leaves every legacy key and an inert runtime.
     */
    private function stepFinish(): void
    {
        $names = '\'' . implode('\', \'', array_map('pSQL', $this->plan()->legacyKeysToDelete())) . '\'';
        Sql::write('START TRANSACTION');
        try {
            Sql::write(
                'DELETE c, cl FROM `' . _DB_PREFIX_ . 'configuration` c'
                . ' LEFT JOIN `' . _DB_PREFIX_ . 'configuration_lang` cl ON cl.`id_configuration` = c.`id_configuration`'
                . ' WHERE c.`name` IN (' . $names . ')'
            );
            self::check(Schema::markCurrent());
            Sql::write('COMMIT');
        } catch (\Throwable $error) {
            Sql::write('ROLLBACK');
            throw $error;
        } finally {
            self::reloadConfiguration();
        }
    }

    private static function check(bool $done): void
    {
        if (!$done) {
            throw new \RuntimeException('A migration step did not complete.');
        }
    }

    private static function reloadConfiguration(): void
    {
        Settings::reloadConfiguration();
    }
}
