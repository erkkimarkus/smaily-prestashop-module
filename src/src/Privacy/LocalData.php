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

namespace PrestaShop\Module\SmailyForPrestaShop\Privacy;

if (!defined('_PS_VERSION_')) {
    exit;
}

use PrestaShop\Module\SmailyForPrestaShop\Automation\AbandonedCart\CartTracker;
use PrestaShop\Module\SmailyForPrestaShop\Database\Sql;
use PrestaShop\Module\SmailyForPrestaShop\Discount\IssuedCodes;
use PrestaShop\Module\SmailyForPrestaShop\Log\ResendGuard;
use PrestaShop\Module\SmailyForPrestaShop\Queue\ContactEntity;
use PrestaShop\Module\SmailyForPrestaShop\Queue\EventQueue;

/**
 * The store's half of a privacy request: what the module's own tables hold of one shopper, for
 * the export, and its erasure. As the Magento connector's Model\Privacy\LocalEraser.
 *
 * A shopper is an address, in every shop of the installation: the address itself (compared
 * without regard to letter case; an address that differs by an accent is another shopper's), its
 * keyed hash (Queue\ContactEntity::key()), and the customer accounts that carry the address,
 * with the account that the request names.
 *
 * | The module's data            | Export                    | Erasure                              |
 * |------------------------------|---------------------------|--------------------------------------|
 * | Event queue rows of the      | type, status, time; never | a row that can still be sent is      |
 * | address or its keyed hash    | the message               | deleted; a closed row loses its      |
 * |                              |                           | payload, request, answer and entity  |
 * | Cart tracker rows            | cart, status, times       | marked `erased`, without address,    |
 * |                              |                           | customer and queue row               |
 * | Order attribution            | the row                   | loses the visitor token; the row and |
 * |                              |                           | the order stay                       |
 * | Discount codes of the        | code, times, used or not  | an unused code is removed; a used    |
 * | account and of the queue rows|                           | one stays with its order             |
 * | The personalization record   | the choice, time, source  | stays: it holds the keyed hash only, |
 * |                              |                           | and keeps an opt-out in force        |
 *
 * The erasure never touches PrestaShop's own rows (the customer, the orders, the carts), the 2.x
 * table `smaily_cart`, a cart rule that is not one of the module's unused codes, or a Smaily
 * contact. The discount codes are read before the queue rows are cleared: an untied code is tied
 * to its address by its queue row alone.
 */
class LocalData
{
    private const ATTRIBUTION_TABLE = 'smaily_connect_order_attribution';

    private EventQueue $queue;

    private CartTracker $tracker;

    private IssuedCodes $codes;

    private ProfilingRecord $record;

    public function __construct(EventQueue $queue, CartTracker $tracker, IssuedCodes $codes, ProfilingRecord $record)
    {
        $this->queue = $queue;
        $this->tracker = $tracker;
        $this->codes = $codes;
        $this->record = $record;
    }

    /**
     * What the module's tables hold of the shopper.
     *
     * @param int $idCustomer the customer account of the request, 0 when it names an address only
     *
     * @return array{
     *     queue: list<array{type: string, status: string, created_at: string}>,
     *     carts: list<array{cart: int, status: string, last_activity_at: string, reminded_at: string}>,
     *     attribution: list<array{order: int, recommendation: string, context: string, visitor_token: string, created_at: string}>,
     *     codes: list<array{code: string, created_at: string, expires_at: string, used: bool}>,
     *     preference: array{opted_out: bool, changed_at: string, source: string}|null
     * } queue times in UTC, the others in the shop's time
     */
    public function export(string $email, int $idCustomer = 0): array
    {
        $address = self::address($email);
        if ($address === '') {
            return ['queue' => [], 'carts' => [], 'attribution' => [], 'codes' => [], 'preference' => null];
        }
        $customers = $this->customers($address, $idCustomer);

        return [
            'queue' => array_map(static fn (array $row): array => [
                'type' => (string) $row['event_type'],
                'status' => (string) $row['status'],
                'created_at' => (string) $row['created_at'],
            ], $this->queueRows($address)),
            'carts' => array_map(static fn (array $row): array => [
                'cart' => (int) $row['id_cart'],
                'status' => (string) $row['status'],
                'last_activity_at' => (string) $row['last_activity_at'],
                'reminded_at' => (string) $row['mailed_at'],
            ], $this->tracker->ofShopper($address, $customers)),
            'attribution' => array_map(static fn (array $row): array => [
                'order' => (int) $row['id_order'],
                'recommendation' => (string) $row['rec_id'],
                'context' => (string) $row['context'],
                'visitor_token' => (string) $row['visitor_token'],
                'created_at' => (string) $row['created_at'],
            ], $this->attribution($customers)),
            'codes' => array_map(static fn (array $code): array => [
                'code' => $code['code'],
                'created_at' => $code['created_at'],
                'expires_at' => $code['expires_at'],
                'used' => $code['used'],
            ], $this->codes($address, $customers)),
            'preference' => $this->record->find($address),
        ];
    }

    /**
     * Erases the shopper from the module's tables (the class comment says what goes and what stays).
     *
     * @param int $idCustomer the customer account of the request, 0 when it names an address only
     *
     * @return array{queue_removed: int, queue_cleared: int, carts: int, attribution: int, codes_removed: int, codes_kept: int}
     */
    public function erase(string $email, int $idCustomer = 0): array
    {
        $address = self::address($email);
        if ($address === '') {
            return ['queue_removed' => 0, 'queue_cleared' => 0, 'carts' => 0, 'attribution' => 0, 'codes_removed' => 0, 'codes_kept' => 0];
        }
        $customers = $this->customers($address, $idCustomer);

        // Before the queue: the erasure takes the address off the rows that tie the codes to it.
        $codes = $this->codes->removeUnused(array_map(static fn (array $code): int => $code['id'], $this->codes($address, $customers)));
        $queue = $this->queue->erase(
            array_map(static fn (array $row): int => (int) $row['id'], $this->queueRows($address)),
            ResendGuard::ERASED_ENTITY
        );
        $carts = $this->tracker->erase($address, $customers);

        $orders = array_map(static fn (array $row): int => (int) $row['id_order'], array_filter(
            $this->attribution($customers),
            static fn (array $row): bool => $row['visitor_token'] !== null
        ));
        if ($orders !== []) {
            Sql::write(sprintf(
                'UPDATE `%s%s` SET `visitor_token` = NULL WHERE `id_order` IN (%s)',
                _DB_PREFIX_,
                self::ATTRIBUTION_TABLE,
                implode(', ', $orders)
            ));
        }

        return [
            'queue_removed' => $queue['removed'],
            'queue_cleared' => $queue['cleared'],
            'carts' => $carts,
            'attribution' => count($orders),
            'codes_removed' => $codes['removed'],
            'codes_kept' => $codes['kept'],
        ];
    }

    /**
     * The customer accounts of the address, registered and guest, in every shop, with the account
     * that the request names.
     *
     * @return list<int>
     */
    private function customers(string $address, int $idCustomer): array
    {
        $ids = $idCustomer > 0 ? [$idCustomer] : [];
        $rows = Sql::rows(sprintf('SELECT `id_customer`, `email` FROM `%scustomer` WHERE `email` = \'%s\'', _DB_PREFIX_, pSQL($address)));
        foreach ($rows as $row) {
            if (self::address((string) $row['email']) === $address) {
                $ids[] = (int) $row['id_customer'];
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * The event-queue rows of the address: the rows whose entity is the address or its keyed hash.
     *
     * @return list<array<string, string|null>>
     */
    private function queueRows(string $address): array
    {
        $key = ContactEntity::key($address);

        return array_values(array_filter(
            $this->queue->ofEntities([$address, $key]),
            static fn (array $row): bool => in_array(self::address((string) $row['entity_id']), [$address, $key], true)
        ));
    }

    /**
     * The attribution rows of the customer accounts' orders.
     *
     * @param list<int> $customers
     *
     * @return list<array<string, string|null>>
     */
    private function attribution(array $customers): array
    {
        if ($customers === []) {
            return [];
        }

        return Sql::rows(sprintf(
            'SELECT a.`id_order`, a.`rec_id`, a.`context`, a.`visitor_token`, a.`created_at` FROM `%1$s%2$s` a'
            . ' INNER JOIN `%1$sorders` o ON o.`id_order` = a.`id_order` WHERE o.`id_customer` IN (%3$s) ORDER BY a.`id_order`',
            _DB_PREFIX_,
            self::ATTRIBUTION_TABLE,
            implode(', ', $customers)
        ));
    }

    /**
     * The discount codes of the shopper, each once: the codes tied to the customer accounts and
     * the codes that the address's queue rows still hold.
     *
     * @param list<int> $customers
     *
     * @return list<array{id: int, code: string, created_at: string, expires_at: string, used: bool}>
     */
    private function codes(string $address, array $customers): array
    {
        $codes = [];
        foreach ($customers as $idCustomer) {
            foreach ($this->codes->ofCustomer($idCustomer) as $code) {
                $codes[$code['id']] = $code;
            }
        }
        foreach ($this->codes->ofAddress($address) as $code) {
            $codes[$code['id']] = $code;
        }
        ksort($codes);

        return array_values($codes);
    }

    private static function address(string $email): string
    {
        return mb_strtolower(trim($email));
    }
}
