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

namespace PrestaShop\Module\SmailyForPrestaShop\Report;

if (!defined('_PS_VERSION_')) {
    exit;
}

use PrestaShop\Module\SmailyForPrestaShop\Engine\Setup;

/**
 * What the installation report reads of PrestaShop itself: the shops, their addresses and
 * languages, and the versions.
 *
 * Not final: the unit tests stand in for the shop.
 */
class StoreFacts
{
    /**
     * The shops that the installation serves, lowest id first: every active shop, or the default
     * shop alone while multistore is off, whatever rows the table `shop` holds (facts file 6.9).
     *
     * @return list<int>
     */
    public function shopIds(): array
    {
        if (!\Shop::isFeatureActive()) {
            return [$this->defaultShopId()];
        }
        $shops = array_map('intval', array_values(\Shop::getShops(true, null, true)));
        sort($shops);

        return $shops;
    }

    public function defaultShopId(): int
    {
        return (int) \Configuration::get('PS_SHOP_DEFAULT');
    }

    /**
     * The main storefront address of a shop, or '' when it has none.
     */
    public function url(int $idShop): string
    {
        return Setup::siteUrl($idShop);
    }

    /**
     * The ISO codes of a shop's active languages, in lower case.
     *
     * @return list<string>
     */
    public function languages(int $idShop): array
    {
        $codes = [];
        foreach (\Language::getLanguages(true, $idShop) as $language) {
            $codes[] = strtolower((string) $language['iso_code']);
        }

        return $codes;
    }

    public function platformVersion(): string
    {
        return _PS_VERSION_;
    }
}
