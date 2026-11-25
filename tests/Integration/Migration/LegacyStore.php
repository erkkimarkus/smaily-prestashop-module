<?php
declare(strict_types=1);

namespace Tests\Integration\Migration;

use PrestaShop\Module\SmailyForPrestaShop\Config\TickSettings;
use PrestaShop\Module\SmailyForPrestaShop\Install\Schema;
use PrestaShop\Module\SmailyForPrestaShop\Migration\LegacyState;

/**
 * Puts the docker shop into the state that a legacy release left, and back. The shop has 3.0.0
 * installed; clear() removes every legacy and 3.0.0 setting and row, so a test starts from a store
 * that holds only what it loads.
 */
final class LegacyStore
{
    /**
     * The door tokens that the shop held before the run's first change, as rows of insertRows():
     * restore() puts them back, as a merchant's crontab and a walk's cron line name them.
     *
     * @var list<array{0: int|null, 1: int|null, 2: string, 3: string|null}>|null
     */
    private static ?array $foundTokens = null;

    public static function clear(): void
    {
        self::rememberTokens();
        $db = \Db::getInstance();
        $db->execute(
            'DELETE FROM `' . _DB_PREFIX_ . 'configuration` WHERE `name` IN (\''
            . implode('\', \'', LegacyState::KEYS) . '\') OR `name` LIKE \'SMAILY\\_CONNECT\\_%\''
        );
        Schema::create();
        foreach (Schema::tables() as $table) {
            $db->execute('DELETE FROM `' . _DB_PREFIX_ . $table . '`');
        }
        $db->execute('DROP TABLE IF EXISTS `' . _DB_PREFIX_ . 'smaily_cart`');
        self::reloadConfiguration();
    }

    /**
     * The fresh 3.0.0 install the other tests expect, with the door tokens that the shop held.
     */
    public static function restore(): void
    {
        self::clear();
        Schema::markCurrent();
        self::insertRows(self::$foundTokens ?? []);
    }

    /**
     * Runs part 1 of a fixture of tests/fixtures/legacy/: its configuration rows and smaily_cart.
     */
    public static function loadFixture(string $file): void
    {
        self::rememberTokens();
        $sql = (string) file_get_contents(__DIR__ . '/../../fixtures/legacy/' . $file);
        $sql = explode('-- Part 2', $sql)[0];
        $sql = (string) preg_replace('/^--.*$/m', '', $sql);
        foreach (preg_split('/;\s*$/m', $sql) ?: [] as $statement) {
            if (trim($statement) !== '') {
                if (!\Db::getInstance()->execute(str_replace('`ps_', '`' . _DB_PREFIX_, $statement))) {
                    throw new \RuntimeException('A fixture statement failed in ' . $file);
                }
            }
        }
        self::reloadConfiguration();
    }

    /**
     * @param list<array{0: int|null, 1: int|null, 2: string, 3: string|null}> $rows id_shop_group, id_shop, name, value
     */
    public static function insertRows(array $rows): void
    {
        foreach ($rows as [$idShopGroup, $idShop, $name, $value]) {
            \Db::getInstance()->execute(
                'INSERT INTO `' . _DB_PREFIX_ . 'configuration` (`id_shop_group`, `id_shop`, `name`, `value`, `date_add`, `date_upd`)'
                . ' VALUES (' . ($idShopGroup === null ? 'NULL' : (int) $idShopGroup)
                . ', ' . ($idShop === null ? 'NULL' : (int) $idShop)
                . ', \'' . pSQL($name) . '\', ' . ($value === null ? 'NULL' : '\'' . pSQL($value) . '\'') . ', NOW(), NOW())'
            );
        }
        self::reloadConfiguration();
    }

    public static function createSentCarts(int $count): void
    {
        \Db::getInstance()->execute(
            'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'smaily_cart` (
              `id_smaily_cart` int(10) unsigned NOT NULL AUTO_INCREMENT,
              `id_customer` int(10) unsigned DEFAULT NULL,
              `id_cart` int(10) unsigned DEFAULT NULL,
              `date_sent` datetime NOT NULL,
              PRIMARY KEY (`id_smaily_cart`)) ENGINE=InnoDB'
        );
        for ($i = 1; $i <= $count; ++$i) {
            \Db::getInstance()->execute(
                'INSERT INTO `' . _DB_PREFIX_ . 'smaily_cart` (`id_customer`, `id_cart`, `date_sent`)'
                . ' VALUES (2, ' . $i . ', \'2026-11-0' . $i . ' 10:00:00\')'
            );
        }
    }

    /**
     * The value of one stored row; false when the row is absent.
     *
     * @return string|false|null
     */
    public static function row(string $name, ?int $idShopGroup = null, ?int $idShop = null)
    {
        $rows = \Db::getInstance()->executeS(
            'SELECT `value` FROM `' . _DB_PREFIX_ . 'configuration` WHERE `name` = \'' . pSQL($name) . '\''
            . ' AND `id_shop_group` ' . ($idShopGroup === null ? 'IS NULL' : '= ' . $idShopGroup)
            . ' AND `id_shop` ' . ($idShop === null ? 'IS NULL' : '= ' . $idShop)
        );

        return $rows ? $rows[0]['value'] : false;
    }

    public static function countRows(string $name): int
    {
        return (int) \Db::getInstance()->getValue(
            'SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'configuration` WHERE `name` = \'' . pSQL($name) . '\''
        );
    }

    /**
     * Undoes the shop 2 and the multistore switch that a multistore fixture adds, and the rows it
     * holds at shop 2.
     */
    public static function removeSecondShop(): void
    {
        $db = \Db::getInstance();
        $db->execute('DELETE FROM `' . _DB_PREFIX_ . 'configuration` WHERE `id_shop` = 2 AND `name` LIKE \'SMAILY\\_%\'');
        $db->execute('DELETE FROM `' . _DB_PREFIX_ . 'configuration` WHERE `name` = \'PS_MULTISHOP_FEATURE_ACTIVE\'');
        $db->execute(
            'INSERT INTO `' . _DB_PREFIX_ . 'configuration` (`name`, `value`, `date_add`, `date_upd`)'
            . ' VALUES (\'PS_MULTISHOP_FEATURE_ACTIVE\', \'0\', NOW(), NOW())'
        );
        $db->execute('DELETE FROM `' . _DB_PREFIX_ . 'shop_url` WHERE `id_shop` = 2');
        $db->execute('DELETE FROM `' . _DB_PREFIX_ . 'shop` WHERE `id_shop` = 2');
        $db->execute('DELETE FROM `' . _DB_PREFIX_ . 'module_shop` WHERE `id_shop` = 2');
        \Shop::resetStaticCache();
        self::reloadConfiguration();
    }

    public static function reloadConfiguration(): void
    {
        \Shop::resetStaticCache();
        \Configuration::resetStaticCache();
        \Configuration::loadConfiguration();
    }

    /**
     * Notes the door tokens of the shop at the run's first change, for restore(). A test that
     * removes them otherwise (an uninstall) calls it first.
     */
    public static function rememberTokens(): void
    {
        if (self::$foundTokens === null) {
            $rows = \Db::getInstance()->executeS(
                'SELECT `id_shop_group`, `id_shop`, `name`, `value` FROM `' . _DB_PREFIX_ . 'configuration` WHERE `name` IN (\''
                . implode('\', \'', [TickSettings::TICK_TOKEN, TickSettings::CUSTOMER_CRON_TOKEN, TickSettings::CART_CRON_TOKEN]) . '\')'
                . ' ORDER BY `id_configuration`',
                true,
                false
            ) ?: [];
            self::$foundTokens = array_map(static fn (array $row): array => [
                $row['id_shop_group'] === null ? null : (int) $row['id_shop_group'],
                $row['id_shop'] === null ? null : (int) $row['id_shop'],
                (string) $row['name'],
                $row['value'],
            ], $rows);
        }
    }
}
