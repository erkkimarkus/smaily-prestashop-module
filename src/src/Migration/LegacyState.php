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

/**
 * What a 2.x or 1.6.x installation left in the database, as the legacy reader found it. Plain data:
 * no PrestaShop class, no database.
 *
 * The reader fills it with:
 * - the previous code version: `module.version` of `smailyforprestashop`;
 * - whether multistore is on as PrestaShop decides it (`Shop::isFeatureActive()`:
 *   `PS_MULTISHOP_FEATURE_ACTIVE` set and more than one row in `shop`);
 * - `PS_SHOP_DEFAULT`;
 * - every shop with its shop group, and the shop groups that share customers;
 * - every `configuration` row whose name is in KEYS, in `id_configuration` order, with `NULL` kept
 *   as `null` (a later row for the same scope wins, as in PrestaShop's own cache);
 * - the number of rows in `smaily_cart`.
 */
final class LegacyState
{
    /**
     * The configuration keys that the legacy code wrote.
     */
    public const KEYS = [
        'SMAILY_SUBDOMAIN',
        'SMAILY_USERNAME',
        'SMAILY_PASSWORD',
        'SMAILY_ENABLE_CUSTOMER_SYNC',
        'SMAILY_ENABLE_CRON',
        'SMAILY_CUSTOMER_CRON_TOKEN',
        'SMAILY_SYNCRONIZE_ADDITIONAL',
        'SMAILY_OPTIN_ENABLED',
        'SMAILY_OPTIN_AUTORESPONDER',
        'SMAILY_ENABLE_ABANDONED_CART',
        'SMAILY_CART_CRON_TOKEN',
        'SMAILY_CART_AUTORESPONDER',
        'SMAILY_ABANDONED_CART_TIME',
        'SMAILY_CART_SYNCRONIZE_ADDITIONAL',
        'SMAILY_RSS_CATEGORY_ID',
        'SMAILY_RSS_LIMIT',
        'SMAILY_RSS_SORT_BY',
        'SMAILY_RSS_SORT_ORDER',
    ];

    private string $previousVersion;

    private bool $multishopActive;

    private int $defaultShopId;

    /** @var array<int, int> id_shop => id_shop_group */
    private array $shopGroups = [];

    /** @var list<int> */
    private array $groupsSharingCustomers;

    /** @var list<array{id_shop_group: int|null, id_shop: int|null, name: string, value: string|null}> */
    private array $rows = [];

    private int $sentCartCount;

    /**
     * @param array<int|string, int|string> $shopGroups id_shop => id_shop_group
     * @param array<int, int|string> $groupsSharingCustomers id_shop_group of every group with share_customer = 1
     * @param array<int, array{id_shop_group: int|string|null, id_shop: int|string|null, name: string, value: string|null}> $rows
     */
    public function __construct(
        string $previousVersion,
        bool $multishopActive,
        int $defaultShopId,
        array $shopGroups,
        array $groupsSharingCustomers,
        array $rows,
        int $sentCartCount
    ) {
        $this->previousVersion = $previousVersion;
        $this->multishopActive = $multishopActive;
        $this->defaultShopId = $defaultShopId;
        foreach ($shopGroups as $idShop => $idShopGroup) {
            $this->shopGroups[(int) $idShop] = (int) $idShopGroup;
        }
        ksort($this->shopGroups);
        $this->groupsSharingCustomers = array_map('intval', array_values($groupsSharingCustomers));
        foreach ($rows as $row) {
            $this->rows[] = [
                // PrestaShop reads an id of 0 as no id.
                'id_shop_group' => empty($row['id_shop_group']) ? null : (int) $row['id_shop_group'],
                'id_shop' => empty($row['id_shop']) ? null : (int) $row['id_shop'],
                'name' => (string) $row['name'],
                'value' => $row['value'] === null ? null : (string) $row['value'],
            ];
        }
        $this->sentCartCount = $sentCartCount;
    }

    public function previousVersion(): string
    {
        return $this->previousVersion;
    }

    public function multishopActive(): bool
    {
        return $this->multishopActive;
    }

    public function defaultShopId(): int
    {
        return $this->defaultShopId;
    }

    /**
     * @return array<int, int> id_shop => id_shop_group, by id_shop
     */
    public function shopGroups(): array
    {
        return $this->shopGroups;
    }

    public function groupSharesCustomers(int $idShopGroup): bool
    {
        return in_array($idShopGroup, $this->groupsSharingCustomers, true);
    }

    /**
     * @return array<int, array{id_shop_group: int|null, id_shop: int|null, name: string, value: string|null}>
     */
    public function rows(): array
    {
        return $this->rows;
    }

    public function sentCartCount(): int
    {
        return $this->sentCartCount;
    }
}
