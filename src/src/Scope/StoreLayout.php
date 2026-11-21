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

namespace PrestaShop\Module\SmailyForPrestaShop\Scope;

if (!defined('_PS_VERSION_')) {
    exit;
}

use PrestaShop\Module\SmailyForPrestaShop\Config\Settings;
use PrestaShop\Module\SmailyForPrestaShop\Database\Sql;

/**
 * How the store is set up across its shops and languages (ARCHITECTURE section 5).
 *
 * The shops of a multishop store share one setup ("one store in several versions"), or each shop
 * has its own ("separate stores"). Each shop has a language mode: `single`; `a` one Smaily account
 * per language with a fallback account; `b` one account and one workflow per language; `c` one
 * workflow that branches on the contact's `language` field.
 *
 * Not final: the unit tests stand in for the stored settings.
 */
class StoreLayout extends Settings
{
    public const SEPARATE_STORES = self::PREFIX . 'SEPARATE_STORES';

    public const LANGUAGE_MODE = self::PREFIX . 'LANGUAGE_MODE';

    public const FALLBACK_LANGUAGE = self::PREFIX . 'FALLBACK_LANGUAGE';

    public const MODE_SINGLE = 'single';

    public const MODE_ACCOUNT_PER_LANGUAGE = 'a';

    public const MODE_WORKFLOW_PER_LANGUAGE = 'b';

    public const MODE_BRANCH_ON_LANGUAGE = 'c';

    public const CUSTOMER_ACCOUNTS_GLOBAL = 'global';

    public const CUSTOMER_ACCOUNTS_PER_SHOP = 'website';

    private const MODES = [
        self::MODE_SINGLE,
        self::MODE_ACCOUNT_PER_LANGUAGE,
        self::MODE_WORKFLOW_PER_LANGUAGE,
        self::MODE_BRANCH_ON_LANGUAGE,
    ];

    public function separateStores(): bool
    {
        return $this->get(self::SEPARATE_STORES) === '1';
    }

    /**
     * Whether the shop layout was chosen: the upgrade or the Initial setup stored one. A fresh
     * install stores none until the merchant answers.
     */
    public function layoutChosen(): bool
    {
        return $this->get(self::SEPARATE_STORES) !== null;
    }

    public function setSeparateStores(bool $separate): bool
    {
        return $this->set(self::SEPARATE_STORES, $separate ? '1' : '0');
    }

    /**
     * Stores the language mode of a shop (0 = the global scope).
     */
    public function setLanguageMode(string $mode, int $idShop): bool
    {
        if (!in_array($mode, self::MODES, true)) {
            throw new \InvalidArgumentException('Unknown language mode.');
        }

        return $this->set(self::LANGUAGE_MODE, $mode, $idShop);
    }

    /**
     * Stores the ISO code of the fallback language of a shop (0 = the global scope).
     */
    public function setFallbackLanguage(string $isoCode, int $idShop): bool
    {
        if (preg_match('/^[a-z]{2}$/', $isoCode) !== 1) {
            throw new \InvalidArgumentException('A fallback language is a two-letter ISO code.');
        }

        return $this->set(self::FALLBACK_LANGUAGE, $isoCode, $idShop);
    }

    /**
     * The shop's language mode, else the global one; `single` when none is stored.
     */
    public function languageMode(int $idShop): string
    {
        $mode = $this->get(self::LANGUAGE_MODE, $idShop);

        return in_array($mode, self::MODES, true) ? $mode : self::MODE_SINGLE;
    }

    /**
     * The ISO code of the language whose account takes the contacts of a language without an
     * account in mode `a`, or '' when none is set.
     */
    public function fallbackLanguage(int $idShop): string
    {
        return (string) $this->get(self::FALLBACK_LANGUAGE, $idShop);
    }

    /**
     * The contract's `store_config.customer_account_scope`, from the shop groups of the store's
     * shops.
     */
    public function customerAccountScope(): string
    {
        $rows = Sql::rows(
            'SELECT DISTINCT g.`id_shop_group`, g.`share_customer`'
            . ' FROM `' . _DB_PREFIX_ . 'shop` s'
            . ' INNER JOIN `' . _DB_PREFIX_ . 'shop_group` g ON g.`id_shop_group` = s.`id_shop_group`'
            . ' WHERE s.`deleted` = 0'
        );
        $shareCustomerByGroup = [];
        foreach ($rows as $row) {
            $shareCustomerByGroup[(int) $row['id_shop_group']] = (bool) $row['share_customer'];
        }

        return self::customerAccountScopeOf($shareCustomerByGroup);
    }

    /**
     * `global` when every shop is in one shop group and that group shares customers (one customer
     * account per person), else `website`.
     *
     * @param array<int, bool> $shareCustomerByGroup id_shop_group => share_customer, for each
     *                                               group that holds a shop
     */
    public static function customerAccountScopeOf(array $shareCustomerByGroup): string
    {
        return count($shareCustomerByGroup) === 1 && reset($shareCustomerByGroup) === true
            ? self::CUSTOMER_ACCOUNTS_GLOBAL
            : self::CUSTOMER_ACCOUNTS_PER_SHOP;
    }
}
