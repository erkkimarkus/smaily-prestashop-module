<?php
declare(strict_types=1);

namespace Tests\Unit\Migration;

use PrestaShop\Module\SmailyForPrestaShop\Migration\LegacyState;

/**
 * Turns a fixture of tests/fixtures/legacy/ into the LegacyState that the legacy reader would build
 * from the same database: the configuration rows in file order, and the number of smaily_cart rows.
 * Every fixture is a single shop with multistore off: shop 1 in shop group 1, the default shop.
 */
final class LegacyFixture
{
    public static function state(string $file, string $previousVersion): LegacyState
    {
        $sql = (string) file_get_contents(__DIR__ . '/../../fixtures/legacy/' . $file);

        preg_match_all(
            "/^\\((NULL|\\d+), (NULL|\\d+), '(\\w+)', (NULL|'[^']*'), NOW\\(\\), NOW\\(\\)\\)/m",
            $sql,
            $matches,
            PREG_SET_ORDER
        );
        $rows = [];
        foreach ($matches as [, $idShopGroup, $idShop, $name, $value]) {
            $rows[] = [
                'id_shop_group' => $idShopGroup === 'NULL' ? null : $idShopGroup,
                'id_shop' => $idShop === 'NULL' ? null : $idShop,
                'name' => $name,
                'value' => $value === 'NULL' ? null : substr($value, 1, -1),
            ];
        }

        $sentCarts = preg_match_all("/^\\(\\d+, \\d+, \\d+, '[^']+'\\)/m", $sql);

        return new LegacyState($previousVersion, false, 1, [1 => 1], [], $rows, (int) $sentCarts);
    }
}
