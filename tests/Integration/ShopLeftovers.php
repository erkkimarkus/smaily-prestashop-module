<?php
declare(strict_types=1);

namespace Tests\Integration;

use PHPUnit\Runner\AfterLastTestHook;
use PHPUnit\Runner\BeforeFirstTestHook;
use PrestaShop\Module\SmailyForPrestaShop\Config\ReportSettings;
use PrestaShop\Module\SmailyForPrestaShop\Config\TickSettings;

/**
 * Fails a run in the docker shop that leaves the module's state other than it found it: the rows
 * of the module's tables (counted per table, and per status where a table has one), the
 * `SMAILY_*` configuration rows that exist, and the values of the door tokens, which merchants
 * copy into their crontabs. It prints what differs and makes the run exit with 1 after PHPUnit's
 * own result. Without a shop (the host's unit run) it does nothing.
 *
 * Three keys are left out: the health of the module's calls, the installation report's state and
 * the tick's state, which every call, report and tick rewrites. A test of one class that calls a
 * mock leaves the health behind; the full suite leaves the first two as it found them. The tick
 * tests remove the tick's state, and a shop whose storefront was opened after the install holds
 * one (the shop of `tests/ci/validate.sh` does).
 */
final class ShopLeftovers implements BeforeFirstTestHook, AfterLastTestHook
{
    private const TOKENS = [TickSettings::TICK_TOKEN, TickSettings::CUSTOMER_CRON_TOKEN, TickSettings::CART_CRON_TOKEN];

    private const RECORDS = [ReportSettings::HEALTH, ReportSettings::STATE, TickSettings::TICK_STATE];

    /** @var array<string, string>|null */
    private ?array $before = null;

    public function executeBeforeFirstTest(): void
    {
        if (defined('_PS_ROOT_DIR_')) {
            $this->before = self::snapshot();
        }
    }

    public function executeAfterLastTest(): void
    {
        if ($this->before !== null) {
            // The module writes a request's ingest markers and contact changes when the request
            // ends, in shutdown functions that the tests registered before this one: the check
            // runs after them, and after PHPUnit's result. PHPUnit takes no failure then, so the
            // check ends the run with exit code 1.
            register_shutdown_function([$this, 'check']);
        }
    }

    /**
     * Prints what differs from the state before the first test, and exits with 1 if anything does.
     */
    public function check(): void
    {
        $after = self::snapshot();
        $differences = [];
        foreach (array_unique(array_merge(array_keys($this->before ?? []), array_keys($after))) as $name) {
            $was = $this->before[$name] ?? 'none';
            $is = $after[$name] ?? 'none';
            if ($was !== $is) {
                $differences[] = '  ' . $name . ': ' . $was . ' before, ' . $is . ' after';
            }
        }
        if ($differences !== []) {
            fwrite(STDERR, "\nThe run left the shop's module state changed (tests/Integration/ShopLeftovers.php):\n"
                . implode("\n", $differences) . "\n");
            exit(1);
        }
    }

    /**
     * What the run must leave as it found it, by name; a token by a hash of its value, never the
     * value.
     *
     * @return array<string, string>
     */
    private static function snapshot(): array
    {
        $db = \Db::getInstance();
        $snapshot = [];
        $tables = $db->executeS('SHOW TABLES LIKE \'' . str_replace('_', '\\_', _DB_PREFIX_) . 'smaily\\_%\'', true, false) ?: [];
        foreach ($tables as $row) {
            $table = (string) reset($row);
            $snapshot['table ' . $table] = (string) $db->getValue('SELECT COUNT(*) FROM `' . $table . '`', false);
            if ($db->executeS('SHOW COLUMNS FROM `' . $table . '` LIKE \'status\'', true, false)) {
                foreach ($db->executeS('SELECT `status`, COUNT(*) AS `rows` FROM `' . $table . '` GROUP BY `status`', true, false) ?: [] as $status) {
                    $snapshot['table ' . $table . ' status ' . $status['status']] = (string) $status['rows'];
                }
            }
        }
        $keys = $db->executeS(
            'SELECT `name`, `id_shop_group`, `id_shop`, `value` FROM `' . _DB_PREFIX_ . 'configuration` WHERE `name` LIKE \'SMAILY\\_%\'',
            true,
            false
        ) ?: [];
        foreach ($keys as $key) {
            if (in_array($key['name'], self::RECORDS, true)) {
                continue;
            }
            $name = 'key ' . $key['name'] . ' (group ' . ($key['id_shop_group'] ?? 'none') . ', shop ' . ($key['id_shop'] ?? 'none') . ')';
            $value = in_array($key['name'], self::TOKENS, true)
                ? 'value ' . substr(hash('sha256', (string) $key['value']), 0, 8)
                : ($key['value'] === null ? 'NULL' : 'a value');
            $snapshot[$name] = isset($snapshot[$name]) ? $snapshot[$name] . ' and ' . $value : $value;
        }

        return $snapshot;
    }
}
