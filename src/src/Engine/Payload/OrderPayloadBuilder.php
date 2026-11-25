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

use PrestaShop\Module\SmailyForPrestaShop\Database\Sql;
use PrestaShop\Module\SmailyForPrestaShop\Engine\Connection;
use PrestaShop\Module\SmailyForPrestaShop\Engine\Ingest\ConnectionShops;
use PrestaShop\Module\SmailyForPrestaShop\Storefront\OrderAttribution;
use PrestaShop\Module\SmailyForPrestaShop\Storefront\Shape;

/**
 * An order as the orders row of a connection (contract §5), built from the order as the store
 * holds it now, so that every send is the whole order (the engine replaces an order's lines on
 * each send, and a line's return would be lost by a send that left it out).
 *
 * - Every amount is gross (with tax): `total_amount` is `total_paid_tax_incl`; a line's
 *   `unit_price` is `unit_price_tax_incl`, and its `line_total` is `total_price_tax_incl` less
 *   the line's share of the order's vouchers. PrestaShop keeps a voucher on the order, not on its
 *   lines; the share is by the lines' totals, so that Σ line_total + shipping (and gift
 *   wrapping) ≈ total_amount, the contract's invariant.
 * - Every amount is in the default currency of the order's shop, and `currency` names it
 *   (contract §5 "One currency per store"; ARCHITECTURE 8.3). An order in another currency is
 *   converted with its own rate (`orders.conversion_rate`, the rate of its currency to the
 *   default currency at the time of the order, as PrestaShop's `Tools::convertPrice()` reads
 *   it: amount ÷ rate), each amount rounded to cents. An order in the default currency keeps its
 *   amounts as they are.
 * - `status`: `refunded` for the Refunded state; `cancelled` for Canceled and Payment error;
 *   `completed` for a state that is marked shipped, and for Delivered; `processing` for every
 *   other state (ARCHITECTURE 8.3).
 * - A line carries `returned_at` when the order's credit slips took back its whole quantity, at
 *   the time of the last of them. A line refunded in part stays kept (contract "Return
 *   signals"). No return reason is sent: PrestaShop has none that maps.
 * - A line names its product by the key of its catalog row (Keys), also when the product was
 *   deleted since: the line keeps the ids.
 * - `smaily_rec_id`, `smaily_visitor_token` and `smaily_rec_ctx` come from the order's
 *   attribution row, each only in the contract's form (Storefront\Shape); a field without a stored value is left
 *   out, never sent empty.
 * - `website` names the order's shop for a connection that serves two or more shops.
 *
 * The order's address is its customer's. No name, postal address, phone or note of the order is
 * sent.
 */
class OrderPayloadBuilder
{
    public const STATUS_COMPLETED = 'completed';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUS_REFUNDED = 'refunded';

    /**
     * @return array<string, mixed>|null null when the store holds no such order, or the order's
     *                                   customer is gone or deleted: nothing names its address
     */
    public function row(Connection $connection, int $idOrder): ?array
    {
        $order = Sql::row(
            'SELECT o.`id_order`, o.`id_shop`, o.`id_currency`, o.`conversion_rate`, o.`current_state`, o.`total_paid_tax_incl`,'
            . ' o.`total_discounts_tax_incl`, o.`date_add`, c.`email`, c.`deleted`, os.`shipped`'
            . ' FROM `' . _DB_PREFIX_ . 'orders` o'
            . ' LEFT JOIN `' . _DB_PREFIX_ . 'customer` c ON c.`id_customer` = o.`id_customer`'
            . ' LEFT JOIN `' . _DB_PREFIX_ . 'order_state` os ON os.`id_order_state` = o.`current_state`'
            . ' WHERE o.`id_order` = ' . $idOrder
        );
        $email = strtolower(trim((string) ($order['email'] ?? '')));
        if ($order === null || $email === '' || (int) $order['deleted'] === 1) {
            return null;
        }

        $idShop = (int) $order['id_shop'];
        $rate = (int) $order['id_currency'] === StoreCurrency::id($idShop) ? null : (float) $order['conversion_rate'];
        $row = [
            'external_order_id' => Keys::orderId($connection->prefix(), $idOrder),
            'customer_email' => $email,
            'ordered_at' => self::utc((string) $order['date_add']),
            'total_amount' => self::amount($order['total_paid_tax_incl'], $rate),
        ];
        $discount = self::money($order['total_discounts_tax_incl']);
        if ($discount > 0) {
            $row['discount_amount'] = self::amount($order['total_discounts_tax_incl'], $rate);
        }
        $row['currency'] = StoreCurrency::isoCode($idShop);
        $row['status'] = self::status((int) $order['current_state'], (int) $order['shipped'] === 1);
        $row += $this->attribution($idOrder);
        $website = ConnectionShops::website($connection, $idShop);
        if ($website !== null) {
            $row['website'] = $website;
        }
        $row['items'] = $this->items($connection, $idOrder, $discount, $rate);

        return $row;
    }

    /**
     * The engine's status of a PrestaShop order state.
     *
     * @param bool $shipped whether the state is marked "shipped" (`order_state.shipped`)
     */
    public static function status(int $idState, bool $shipped): string
    {
        if ($idState === (int) \Configuration::get('PS_OS_REFUND')) {
            return self::STATUS_REFUNDED;
        }
        if (in_array($idState, [(int) \Configuration::get('PS_OS_CANCELED'), (int) \Configuration::get('PS_OS_ERROR')], true)) {
            return self::STATUS_CANCELLED;
        }

        return $shipped || $idState === (int) \Configuration::get('PS_OS_DELIVERED') ? self::STATUS_COMPLETED : self::STATUS_PROCESSING;
    }

    /**
     * An amount of an order in the default currency of its shop: the amount as it is for an order
     * in that currency (no rate), else the amount divided by the order's own rate
     * (`orders.conversion_rate`: units of the order's currency per unit of the default
     * currency), rounded to cents.
     *
     * @param float|null $rate null for an order in the default currency
     */
    public static function inStoreCurrency(float $amount, ?float $rate): float
    {
        if ($rate === null) {
            return $amount;
        }
        if ($rate <= 0) {
            throw new \RuntimeException('The order carries no conversion rate.');
        }

        return round($amount / $rate, 2);
    }

    /**
     * The lines' totals after each line's share of the order's vouchers: by the lines' own
     * totals, never below zero, the last line with a total taking what rounding left.
     *
     * @param list<float> $totals each line's total with tax
     *
     * @return list<float> each line's share of $discount
     */
    public static function shares(array $totals, float $discount): array
    {
        $sum = array_sum($totals);
        $shares = array_fill(0, count($totals), 0.0);
        if ($discount <= 0 || $sum <= 0) {
            return $shares;
        }
        $left = min($discount, $sum);
        $last = null;
        foreach ($totals as $index => $total) {
            if ($total <= 0) {
                continue;
            }
            $shares[$index] = min($total, round($discount * $total / $sum, 4), $left);
            $left = round($left - $shares[$index], 4);
            $last = $index;
        }
        if ($last !== null && $left > 0) {
            $shares[$last] = min($totals[$last], round($shares[$last] + $left, 4));
        }

        return $shares;
    }

    /**
     * @param float|null $rate the order's conversion rate, null for an order in the default
     *                         currency (inStoreCurrency())
     *
     * @return list<array<string, mixed>>
     */
    private function items(Connection $connection, int $idOrder, float $discount, ?float $rate): array
    {
        $lines = Sql::rows(
            'SELECT `id_order_detail`, `product_id`, `product_attribute_id`, `product_quantity`, `unit_price_tax_incl`, `total_price_tax_incl`'
            . ' FROM `' . _DB_PREFIX_ . 'order_detail` WHERE `id_order` = ' . $idOrder . ' ORDER BY `id_order_detail`'
        );
        $returns = array_column(Sql::rows(
            'SELECT d.`id_order_detail`, SUM(d.`product_quantity`) AS `quantity`, MAX(s.`date_add`) AS `returned_at`'
            . ' FROM `' . _DB_PREFIX_ . 'order_slip_detail` d'
            . ' INNER JOIN `' . _DB_PREFIX_ . 'order_slip` s ON s.`id_order_slip` = d.`id_order_slip`'
            . ' WHERE s.`id_order` = ' . $idOrder . ' GROUP BY d.`id_order_detail`'
        ), null, 'id_order_detail');
        $shares = self::shares(array_map(static fn (array $line): float => self::money($line['total_price_tax_incl']), $lines), $discount);

        $items = [];
        foreach ($lines as $index => $line) {
            $quantity = (int) $line['product_quantity'];
            $item = [
                'sku' => Keys::sku($connection->prefix(), (int) $line['product_id'], (int) $line['product_attribute_id']),
                'qty' => $quantity,
                'unit_price' => self::amount($line['unit_price_tax_incl'], $rate),
                'line_total' => self::inStoreCurrency(round(self::money($line['total_price_tax_incl']) - $shares[$index], 4), $rate),
            ];
            if ($shares[$index] > 0) {
                $item['discount_amount'] = self::inStoreCurrency($shares[$index], $rate);
            }
            $return = $returns[$line['id_order_detail']] ?? null;
            if ($return !== null && $quantity > 0 && (int) $return['quantity'] >= $quantity) {
                $item['returned_at'] = self::utc((string) $return['returned_at']);
            }
            $items[] = $item;
        }

        return $items;
    }

    /**
     * The attribution fields of the order, each only when the stored value has the contract's
     * form: a malformed `smaily_rec_id` would cost the whole order (contract §5).
     *
     * @return array<string, string>
     */
    private function attribution(int $idOrder): array
    {
        $stored = Sql::row(
            'SELECT `rec_id`, `context`, `visitor_token` FROM `' . _DB_PREFIX_ . OrderAttribution::TABLE . '` WHERE `id_order` = ' . $idOrder
        );
        $fields = [];
        if (Shape::isRecId((string) ($stored['rec_id'] ?? ''))) {
            $fields['smaily_rec_id'] = (string) $stored['rec_id'];
        }
        if (Shape::isVisitorToken((string) ($stored['visitor_token'] ?? ''))) {
            $fields['smaily_visitor_token'] = (string) $stored['visitor_token'];
        }
        if (Shape::isContext((string) ($stored['context'] ?? ''))) {
            $fields['smaily_rec_ctx'] = (string) $stored['context'];
        }

        return $fields;
    }

    /**
     * A time that PrestaShop stored in the shop's time zone (PHP's), as the contract's UTC time.
     */
    private static function utc(string $stored): string
    {
        return gmdate('Y-m-d\TH:i:s\Z', (int) strtotime($stored));
    }

    /**
     * A stored amount of the order in the default currency of its shop (inStoreCurrency()).
     *
     * @param mixed $stored
     */
    private static function amount($stored, ?float $rate): float
    {
        return self::inStoreCurrency(self::money($stored), $rate);
    }

    /**
     * @param mixed $amount
     */
    private static function money($amount): float
    {
        return round((float) $amount, 4);
    }
}
