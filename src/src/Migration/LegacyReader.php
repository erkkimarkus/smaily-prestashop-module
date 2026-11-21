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

use PrestaShop\Module\SmailyForPrestaShop\Database\Sql;
use PrestaShop\Module\SmailyForPrestaShop\Database\SqlException;

/**
 * Reads the state that 2.x or 1.6.x left from the shop's database, as LegacyState describes it. It
 * reads the tables, not PrestaShop's configuration cache, and writes nothing.
 */
final class LegacyReader
{
    /**
     * The module version that PrestaShop records for this module. While PrestaShop runs an upgrade
     * script it is still the previous version; PrestaShop stores the new one afterwards.
     */
    public function moduleVersion(): string
    {
        return (string) Sql::value(
            'SELECT `version` FROM `' . _DB_PREFIX_ . 'module` WHERE `name` = \'smailyforprestashop\''
        );
    }

    /**
     * @throws SqlException when a read failed: what 2.x left is then unknown, not empty
     */
    public function read(string $previousVersion): LegacyState
    {
        $prefix = _DB_PREFIX_;

        $shops = [];
        foreach (Sql::rows('SELECT `id_shop`, `id_shop_group` FROM `' . $prefix . 'shop` WHERE `deleted` = 0') as $shop) {
            $shops[(int) $shop['id_shop']] = (int) $shop['id_shop_group'];
        }
        $sharing = array_map('intval', array_column(
            Sql::rows('SELECT `id_shop_group` FROM `' . $prefix . 'shop_group` WHERE `share_customer` = 1'),
            'id_shop_group'
        ));
        // As Shop::isFeatureActive() decides it, without its per-request cache.
        $multishop = (bool) Sql::value(
            'SELECT `value` FROM `' . $prefix . 'configuration` WHERE `name` = \'PS_MULTISHOP_FEATURE_ACTIVE\''
        ) && Sql::count('SELECT COUNT(*) FROM `' . $prefix . 'shop`') > 1;
        $defaultShop = (int) Sql::value(
            'SELECT `value` FROM `' . $prefix . 'configuration` WHERE `name` = \'PS_SHOP_DEFAULT\''
            . ' AND `id_shop` IS NULL AND `id_shop_group` IS NULL'
        );

        $rows = Sql::rows(
            'SELECT `id_shop_group`, `id_shop`, `name`, `value` FROM `' . $prefix . 'configuration`'
            . ' WHERE `name` IN (\'' . implode('\', \'', LegacyState::KEYS) . '\') ORDER BY `id_configuration`'
        );

        $sentCarts = 0;
        if (Sql::rows('SHOW TABLES LIKE \'' . $prefix . 'smaily\\_cart\'') !== []) {
            $sentCarts = Sql::count('SELECT COUNT(*) FROM `' . $prefix . 'smaily_cart`');
        }

        return new LegacyState($previousVersion, $multishop, $defaultShop, $shops, $sharing, $rows, $sentCarts);
    }
}
