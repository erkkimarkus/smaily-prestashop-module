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

namespace PrestaShop\Module\SmailyForPrestaShop\Engine\Payload;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * The one builder of the keys that go to Campaign Intelligence (ARCHITECTURE 8.2; contract §3
 * "Catalog identity", "Same key from every path"): the catalog row, the removal, the nightly list,
 * the order line and, later, the browse event all name a product through it.
 *
 * A key has two parts. The connection prefix is the engine's: it comes from the connection
 * (Engine\Connection::prefix()) and goes in front exactly as it is stored, never read or built
 * here. The platform key is the connector's: PrestaShop's own stable ids, never the merchant's
 * `reference`, which is optional and not unique.
 */
final class Keys
{
    /**
     * The namespace of a PrestaShop product key.
     *
     * PROPOSED, NOT YET AGREED with the engine team (Linear INT-306; ARCHITECTURE 8.2 and section
     * 14, row 1): the contract names `shp-` and `woo-` and no PrestaShop namespace. Nothing that
     * sends a key may ship before that agreement. This constant is the only place of the literal
     * in the module: a unit test fails on a second one (tests/Unit/Engine/Payload/KeysTest).
     */
    public const PLATFORM_NAMESPACE = 'ps-';

    /**
     * The `sku` of a catalog row, a nightly-list item and an order line: one per combination, and
     * one for a product without combinations.
     *
     * @param string $prefix the connection's prefix as stored, '' for none
     * @param int $idAttribute the combination (`id_product_attribute`); 0 for a product without one
     */
    public static function sku(string $prefix, int $idProduct, int $idAttribute = 0): string
    {
        return $prefix . self::PLATFORM_NAMESPACE . self::externalId($idProduct, $idAttribute);
    }

    /**
     * The parent product id: `tags.product_id` of every row of the product, and the id a removal
     * (§3b) names. The bare product id under the prefix, as Shopify and WooCommerce send it.
     */
    public static function productId(string $prefix, int $idProduct): string
    {
        return $prefix . $idProduct;
    }

    /**
     * The `external_order_id` of an order.
     */
    public static function orderId(string $prefix, int $idOrder): string
    {
        return $prefix . $idOrder;
    }

    /**
     * The `external_id` of a catalog row: PrestaShop's own ids, without a prefix and without a
     * namespace (for tracing only).
     */
    public static function externalId(int $idProduct, int $idAttribute = 0): string
    {
        return $idAttribute > 0 ? $idProduct . '-' . $idAttribute : (string) $idProduct;
    }
}
