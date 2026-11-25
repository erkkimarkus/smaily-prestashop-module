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
 * The default currency of a shop: the currency of every amount that a connection sends, the
 * catalog's prices and the orders' amounts (contract §5 "One currency per store"). It is read once
 * per shop in a request, as a flush builds row after row for the same shops.
 */
final class StoreCurrency
{
    /** @var array<int, array{id: int, iso_code: string}> */
    private static array $currencies = [];

    /**
     * The id of the shop's default currency.
     */
    public static function id(int $idShop): int
    {
        return self::of($idShop)['id'];
    }

    /**
     * The ISO code of the shop's default currency, in upper case.
     */
    public static function isoCode(int $idShop): string
    {
        return self::of($idShop)['iso_code'];
    }

    /**
     * Forgets the currencies read so far. The integration tests' base calls it at each test's
     * start, as a test is a request of its own.
     */
    public static function reset(): void
    {
        self::$currencies = [];
    }

    /**
     * @return array{id: int, iso_code: string}
     */
    private static function of(int $idShop): array
    {
        if (!isset(self::$currencies[$idShop])) {
            $currency = new \Currency((int) \Configuration::get('PS_CURRENCY_DEFAULT', null, null, $idShop));
            if (!\Validate::isLoadedObject($currency)) {
                throw new \RuntimeException('PrestaShop did not load the shop\'s default currency.');
            }
            self::$currencies[$idShop] = ['id' => (int) $currency->id, 'iso_code' => strtoupper((string) $currency->iso_code)];
        }

        return self::$currencies[$idShop];
    }
}
