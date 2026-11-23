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

namespace PrestaShop\Module\SmailyForPrestaShop\Engine\Ingest;

if (!defined('_PS_VERSION_')) {
    exit;
}

use PrestaShop\Module\SmailyForPrestaShop\Database\Sql;
use PrestaShop\Module\SmailyForPrestaShop\Engine\Connection;

/**
 * The shops whose products, customers and orders a connection sends (ARCHITECTURE 5.3): the
 * installation-wide connection serves every active shop, and the connection of a separate store
 * serves its own shop. With multistore off the default shop is the only one, whatever rows the
 * table `shop` holds (facts file 6.9).
 */
final class ConnectionShops
{
    /**
     * @return list<int> the ids of the shops, lowest first
     */
    public static function of(Connection $connection): array
    {
        return $connection->idShop() > 0 ? [$connection->idShop()] : self::all();
    }

    /**
     * The shops that the installation serves, lowest id first: every active shop, or the default
     * shop alone while multistore is off.
     *
     * @return list<int>
     */
    public static function all(): array
    {
        if (!\Shop::isFeatureActive()) {
            return [(int) \Configuration::get('PS_SHOP_DEFAULT')];
        }
        $shops = array_map('intval', array_values(\Shop::getShops(true, null, true)));
        sort($shops);

        return $shops;
    }

    /**
     * The start of a query on the products of the connection's shops: the table `product_shop`
     * with the condition that keeps its rows to those shops. A product of two shops has two rows.
     */
    public static function products(Connection $connection): string
    {
        return '`' . _DB_PREFIX_ . 'product_shop` WHERE `id_shop` IN (' . implode(', ', self::of($connection)) . ')';
    }

    /**
     * Up to $limit ids of the products of the connection's shops above $afterId, lowest first.
     *
     * @return list<int>
     */
    public static function productIds(Connection $connection, int $afterId, int $limit): array
    {
        return array_map('intval', array_column(Sql::rows(
            'SELECT DISTINCT `id_product` FROM ' . self::products($connection)
            . ' AND `id_product` > ' . $afterId . ' ORDER BY `id_product` LIMIT ' . $limit
        ), 'id_product'));
    }

    /**
     * The name of a shop in a row: the contract's website code (ARCHITECTURE 8.2), the shop id as
     * text. Only a connection that serves two or more shops names one: null otherwise.
     */
    public static function website(Connection $connection, int $idShop): ?string
    {
        return count(self::of($connection)) > 1 ? (string) $idShop : null;
    }
}
