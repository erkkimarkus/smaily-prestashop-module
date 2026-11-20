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

namespace PrestaShop\Module\SmailyForPrestaShop\Automation\AbandonedCart;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Where a cart's reminder address comes from: the one place that knows it, so a new source plugs
 * in here and the sweep, the per-address rule, the order withdrawal and the send follow.
 *
 * A cart's address is, in this order:
 *  1. the email of the cart's customer row, a registered or a guest-checkout customer (`is_guest`),
 *     unless the customer is deleted;
 *  2. else the address recorded on the cart's tracker row (`smaily_connect_cart.email`), for
 *     example an address typed at checkout before the customer row exists (CartTracker::capture()).
 *
 * The store's consent that comes with the address:
 *  - `newsletter`: the customer row has `newsletter = 1` (false without a customer);
 *  - `box_ticked`: the newsletter box was ticked in this cart's checkout: the tracker row records it
 *    (`checkout_optin = 1`), or the customer's `newsletter = 1` was given while this cart existed
 *    (`newsletter_date_add` at or after the cart's `date_add`; PrestaShop writes it when the box of
 *    the registration or the guest checkout form is ticked).
 *
 * The SQL parts read the cart as `c`, its customer as `cu` and its tracker row as `t`.
 */
final class CartAddress
{
    /**
     * The columns that of() reads besides the cart's own `id_customer` and `date_add`.
     */
    public static function columns(): string
    {
        return 'cu.`email` AS `customer_email`, cu.`firstname`, cu.`lastname`, cu.`newsletter`, cu.`newsletter_date_add`,'
            . ' t.`email` AS `tracked_email`, t.`checkout_optin`';
    }

    public static function joins(): string
    {
        return ' LEFT JOIN `' . _DB_PREFIX_ . 'customer` cu ON cu.`id_customer` = c.`id_customer` AND cu.`deleted` = 0'
            . ' LEFT JOIN `' . _DB_PREFIX_ . CartTracker::TABLE . '` t ON t.`id_cart` = c.`id_cart`';
    }

    /**
     * The SQL condition that the cart has an address.
     */
    public static function present(): string
    {
        return '((cu.`email` IS NOT NULL AND cu.`email` <> \'\') OR (t.`email` IS NOT NULL AND t.`email` <> \'\'))';
    }

    /**
     * The address of a row read with columns(), the cart's `id_customer` and its `date_add`; null
     * when the cart has none.
     *
     * @param array<string, mixed> $row
     *
     * @return array{email: string, id_customer: int|null, first_name: string, last_name: string, newsletter: bool, box_ticked: bool}|null
     */
    public static function of(array $row): ?array
    {
        $customerEmail = trim((string) ($row['customer_email'] ?? ''));
        $fromCustomer = $customerEmail !== '';
        $email = $fromCustomer ? $customerEmail : trim((string) ($row['tracked_email'] ?? ''));
        if ($email === '') {
            return null;
        }
        $newsletter = $fromCustomer && (int) $row['newsletter'] === 1;
        $since = (string) ($row['newsletter_date_add'] ?? '');

        return [
            'email' => $email,
            'id_customer' => $fromCustomer ? (int) $row['id_customer'] : null,
            'first_name' => $fromCustomer ? (string) $row['firstname'] : '',
            'last_name' => $fromCustomer ? (string) $row['lastname'] : '',
            'newsletter' => $newsletter,
            'box_ticked' => (int) ($row['checkout_optin'] ?? 0) === 1
                || ($newsletter && $since !== '' && $since >= (string) $row['date_add']),
        ];
    }

    /**
     * The cart's address as of() reads it now; null when the cart is gone or has none.
     *
     * @return array{email: string, id_customer: int|null, first_name: string, last_name: string, newsletter: bool, box_ticked: bool}|null
     */
    public function read(int $idCart): ?array
    {
        $rows = \Db::getInstance()->executeS(
            'SELECT c.`id_customer`, c.`date_add`, ' . self::columns()
            . ' FROM `' . _DB_PREFIX_ . 'cart` c' . self::joins() . ' WHERE c.`id_cart` = ' . $idCart,
            true,
            false
        );

        return is_array($rows) && $rows !== [] ? self::of($rows[0]) : null;
    }
}
