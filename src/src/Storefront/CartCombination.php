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

namespace PrestaShop\Module\SmailyForPrestaShop\Storefront;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * The combination that PrestaShop's cart takes for a product that is added without one
 * (CartController::processChangeProductInCart(), the same on 8.2.8 and 9.2.0): the default
 * combination, or, where the product cannot be ordered out of stock and the default has no stock,
 * a combination that has some. A browse event that names a product without its combination is
 * keyed by it, so its key is a catalog row's (ARCHITECTURE 8.2).
 */
final class CartCombination
{
    /**
     * @return int the combination; 0 for a product without combinations
     */
    public static function of(int $idProduct): int
    {
        $outOfStock = (int) \StockAvailable::outOfStock($idProduct);
        $minimumQuantity = $outOfStock === 2 ? !\Configuration::get('PS_ORDER_OUT_OF_STOCK') : !$outOfStock;

        return (int) \Product::getDefaultAttribute($idProduct, (int) $minimumQuantity);
    }
}
