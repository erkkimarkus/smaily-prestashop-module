<?php
declare(strict_types=1);

namespace Tests\Unit\Migration;

use PrestaShop\Module\SmailyForPrestaShop\Migration\LegacyState;

/**
 * Turns a fixture of tests/fixtures/legacy/ into the LegacyState that the legacy reader would build
 * from the same database: the configuration rows in file order, and the number of smaily_cart rows.
 * The state has multistore off and shop 1 in shop group 1 as the default shop; $shopGroups names
 * the shops of a fixture that holds more than one.
 */
final class LegacyFixture
{
    /**
     * @param array<int, int> $shopGroups id_shop => id_shop_group
     * @param array<string, string|null> $values a value of a configuration row of the file, by key, in place of the file's
     */
    public static function state(string $file, string $previousVersion, array $shopGroups = [1 => 1], array $values = []): LegacyState
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
                'value' => array_key_exists($name, $values) ? $values[$name] : ($value === 'NULL' ? null : substr($value, 1, -1)),
            ];
        }

        $sentCarts = preg_match_all("/^\\(\\d+, \\d+, \\d+, '[^']+'\\)/m", $sql);

        return new LegacyState($previousVersion, false, 1, $shopGroups, [], $rows, (int) $sentCarts);
    }

    /**
     * A mapping row as the plan seeds it for one language mode "single".
     *
     * @return array<string, int|string|null>
     */
    public static function mapping(int $idShop, string $trigger, ?int $workflowId): array
    {
        return [
            'id_shop' => $idShop,
            'trigger' => $trigger,
            'language' => '',
            'account_key' => 'default',
            'workflow_id' => $workflowId,
            'optin_trigger' => $workflowId === null ? 1 : 0,
            'is_default_fallback' => 1,
        ];
    }
}
