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

namespace PrestaShop\Module\SmailyForPrestaShop\Automation\AbandonedCart\Capture;

if (!defined('_PS_VERSION_')) {
    exit;
}

use PrestaShop\Module\SmailyForPrestaShop\Automation\AbandonedCart\CartTracker;

/**
 * Reads the address that the checkout capture recorded for a cart: the email of the cart's tracker
 * row while the row names no customer. It is gone once the tracker's purge deletes the row or clears
 * its address (CartTracker::purge()).
 */
final class CapturedAddress
{
    private CartTracker $tracker;

    public function __construct(CartTracker $tracker)
    {
        $this->tracker = $tracker;
    }

    public function forCart(int $idCart): ?string
    {
        $row = $this->tracker->row($idCart);
        $email = $row === null || $row['id_customer'] !== null ? '' : trim((string) $row['email']);

        return $email !== '' ? $email : null;
    }
}
