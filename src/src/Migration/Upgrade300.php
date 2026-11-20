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

use PrestaShop\Module\SmailyForPrestaShop\Config\MigrationNotices;
use PrestaShop\Module\SmailyForPrestaShop\Install\HookList;
use PrestaShop\Module\SmailyForPrestaShop\Install\Schema;
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
 */
final class Upgrade300
{
    public const STATE_KEY = 'SMAILY_CONNECT_MIGRATION';

    public const STEPS = [
        'tables',
        'hooks',
        'notices',
        'settings',
        'accounts',
        'mappings',
        'tokens',
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

            return Schema::isCurrent() || $this->run();
        } finally {
            $lock->release();
        }
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
        $registered = \Db::getInstance()->executeS(
            'SELECT DISTINCT h.`name` FROM `' . _DB_PREFIX_ . 'hook_module` hm'
            . ' JOIN `' . _DB_PREFIX_ . 'hook` h ON h.`id_hook` = hm.`id_hook`'
            . ' WHERE hm.`id_module` = ' . (int) $module->id,
            true,
            false
        ) ?: [];
        foreach (array_column($registered, 'name') as $hook) {
            if (!in_array(strtolower($hook), $wanted, true)) {
                self::check((bool) $module->unregisterHook($hook));
            }
        }
    }

    private function stepNotices(): void
    {
        foreach ($this->plan()->notices() as $notice) {
            $this->notices->add($notice['code'], $notice['params']);
        }
    }

    /**
     * Writes each setting at the scope the plan names: shop 0 is the global row, any other shop its
     * own row in its shop group.
     */
    private function stepSettings(): void
    {
        foreach ($this->plan()->settings() as $idShop => $settings) {
            foreach ($settings as $key => $value) {
                if ($idShop === 0) {
                    self::check(\Configuration::updateGlobalValue($key, $value));
                } else {
                    $idShopGroup = (int) \Shop::getGroupFromShop($idShop);
                    self::check($idShopGroup > 0);
                    self::check(\Configuration::updateValue($key, $value, false, $idShopGroup, $idShop));
                }
            }
        }
    }

    private function stepAccounts(): void
    {
        $plan = $this->plan();
        foreach ($plan->accounts() as $account) {
            self::check($this->accounts->save(new Account(
                $account['id_shop'],
                $account['language'],
                $account['subdomain'],
                $account['username'],
                $plan->passwordToEncrypt($account['id_shop'])
            )));
        }
    }

    private function stepMappings(): void
    {
        foreach ($this->plan()->mappings() as $row) {
            self::check(\Db::getInstance()->execute(
                'INSERT INTO `' . _DB_PREFIX_ . 'smaily_connect_automation_mapping`'
                . ' (`id_shop`, `trigger`, `language`, `account_key`, `workflow_id`, `optin_trigger`, `is_default_fallback`, `date_upd`)'
                . ' VALUES (' . $row['id_shop']
                . ', \'' . pSQL($row['trigger']) . '\''
                . ', \'' . pSQL($row['language']) . '\''
                . ', \'' . pSQL($row['account_key']) . '\''
                . ', ' . ($row['workflow_id'] === null ? 'NULL' : $row['workflow_id'])
                . ', ' . $row['optin_trigger']
                . ', ' . $row['is_default_fallback']
                . ', NOW())'
                . ' ON DUPLICATE KEY UPDATE `workflow_id` = VALUES(`workflow_id`), `optin_trigger` = VALUES(`optin_trigger`),'
                . ' `is_default_fallback` = VALUES(`is_default_fallback`), `date_upd` = VALUES(`date_upd`)'
            ));
        }
    }

    /**
     * Gives each legacy cron token row that the plan names a new random token, at the scope it is
     * stored at. A token that is not empty is never changed: merchants' crontabs carry it.
     */
    private function stepTokens(): void
    {
        $db = \Db::getInstance();
        foreach ($this->plan()->tokensToGenerate() as $token) {
            $scope = ' WHERE `name` = \'' . pSQL($token['key']) . '\''
                . ' AND `id_shop_group` ' . ($token['id_shop_group'] === null ? 'IS NULL' : '= ' . $token['id_shop_group'])
                . ' AND `id_shop` ' . ($token['id_shop'] === null ? 'IS NULL' : '= ' . $token['id_shop']);
            $value = bin2hex(random_bytes(16));
            if ((int) $db->getValue('SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'configuration`' . $scope, false) > 0) {
                self::check($db->execute(
                    'UPDATE `' . _DB_PREFIX_ . 'configuration` SET `value` = \'' . $value . '\', `date_upd` = NOW()'
                    . $scope . ' AND (`value` IS NULL OR `value` = \'\')'
                ));
            } else {
                self::check($db->execute(
                    'INSERT INTO `' . _DB_PREFIX_ . 'configuration` (`id_shop_group`, `id_shop`, `name`, `value`, `date_add`, `date_upd`)'
                    . ' VALUES (' . ($token['id_shop_group'] ?? 'NULL') . ', ' . ($token['id_shop'] ?? 'NULL')
                    . ', \'' . pSQL($token['key']) . '\', \'' . $value . '\', NOW(), NOW())'
                ));
            }
        }
        self::reloadConfiguration();
    }

    /**
     * Carries every reminder that `smaily_cart` records into `smaily_connect_cart` as mailed, so no
     * shopper gets a second reminder for the same cart. A row without a cart id names no cart and
     * is left out; a cart that no longer exists keeps the default shop and language.
     */
    private function stepCarts(): void
    {
        if (!$this->plan()->copySentCarts()) {
            return;
        }
        self::check(\Db::getInstance()->execute(
            'INSERT IGNORE INTO `' . _DB_PREFIX_ . 'smaily_connect_cart`'
            . ' (`id_cart`, `id_shop`, `id_customer`, `id_lang`, `status`, `last_activity_at`, `mailed_at`)'
            . ' SELECT sc.`id_cart`, COALESCE(c.`id_shop`, ' . (int) \Configuration::get('PS_SHOP_DEFAULT') . '), sc.`id_customer`,'
            . ' COALESCE(c.`id_lang`, ' . (int) \Configuration::get('PS_LANG_DEFAULT') . '), \'mailed\', sc.`date_sent`, sc.`date_sent`'
            . ' FROM `' . _DB_PREFIX_ . 'smaily_cart` sc'
            . ' LEFT JOIN `' . _DB_PREFIX_ . 'cart` c ON c.`id_cart` = sc.`id_cart`'
            . ' WHERE sc.`id_cart` IS NOT NULL ORDER BY sc.`date_sent`, sc.`id_smaily_cart`'
        ));
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
        $id = (int) \Db::getInstance()->getValue(
            'SELECT `id_tab` FROM `' . _DB_PREFIX_ . 'tab` WHERE `class_name` = \'' . self::LEGACY_TAB . '\'',
            false
        );
        if ($id > 0) {
            self::check((bool) (new \Tab($id))->delete());
        }
    }

    /**
     * Every account row decrypts to its legacy password and every setting reads back from the
     * table at its scope, before the legacy keys go.
     */
    private function stepVerify(): void
    {
        $plan = $this->plan();
        foreach ($plan->accounts() as $account) {
            $stored = $this->accounts->find($account['id_shop'], $account['language']);
            self::check($stored !== null
                && $stored->subdomain() === $account['subdomain']
                && $stored->username() === $account['username']
                && hash_equals($plan->passwordToEncrypt($account['id_shop']), $stored->password()));
        }
        foreach ($plan->settings() as $idShop => $settings) {
            $scope = $idShop === 0
                ? '`id_shop_group` IS NULL AND `id_shop` IS NULL'
                : '`id_shop_group` = ' . (int) \Shop::getGroupFromShop($idShop) . ' AND `id_shop` = ' . $idShop;
            foreach ($settings as $key => $value) {
                $rows = \Db::getInstance()->executeS(
                    'SELECT `value` FROM `' . _DB_PREFIX_ . 'configuration` WHERE `name` = \'' . pSQL($key) . '\' AND ' . $scope,
                    true,
                    false
                );
                self::check(is_array($rows) && count($rows) === 1 && (string) $rows[0]['value'] === $value);
            }
        }
    }

    /**
     * Deletes every legacy key but the two cron tokens and then marks the schema current, in one
     * database transaction: a failure leaves every legacy key and an inert runtime.
     */
    private function stepFinish(): void
    {
        $names = '\'' . implode('\', \'', array_map('pSQL', $this->plan()->legacyKeysToDelete())) . '\'';
        $db = \Db::getInstance();
        self::check($db->execute('START TRANSACTION'));
        try {
            self::check($db->execute(
                'DELETE c, cl FROM `' . _DB_PREFIX_ . 'configuration` c'
                . ' LEFT JOIN `' . _DB_PREFIX_ . 'configuration_lang` cl ON cl.`id_configuration` = c.`id_configuration`'
                . ' WHERE c.`name` IN (' . $names . ')'
            ));
            self::check(Schema::markCurrent());
            self::check($db->execute('COMMIT'));
        } catch (\Throwable $error) {
            $db->execute('ROLLBACK');
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
        \Configuration::resetStaticCache();
        \Configuration::loadConfiguration();
    }
}
