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
use PrestaShop\Module\SmailyForPrestaShop\Engine\Ingest\IngestQueue;
use PrestaShop\Module\SmailyForPrestaShop\Log\ResendGuard;
use PrestaShop\Module\SmailyForPrestaShop\Queue\ContactEntity;
use PrestaShop\Module\SmailyForPrestaShop\Queue\EventQueue;
use PrestaShop\Module\SmailyForPrestaShop\Storefront\CartAttribution;
use PrestaShop\Module\SmailyForPrestaShop\Storefront\OrderAttribution;

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
 * | Campaign Intelligence queue  | type, status, time; never | as the event queue's rows: a waiting |
 * | rows of the accounts and of  | what was sent             | row is deleted; a closed row loses   |
 * | their orders                 |                           | what was sent, the answer and entity |
 * | Cart tracker rows            | cart, status, times       | marked `erased`, without address,    |
 * |                              |                           | customer and queue row               |
 * | Order attribution            | the row                   | loses the visitor token; the row and |
 * |                              |                           | the order stay                       |
 * | Attribution kept with a cart | the row                   | deleted                              |
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
    private EventQueue $queue;

    private CartTracker $tracker;

    private IssuedCodes $codes;

    private ProfilingRecord $record;

    private IngestQueue $ingest;

    public function __construct(EventQueue $queue, CartTracker $tracker, IssuedCodes $codes, ProfilingRecord $record, IngestQueue $ingest)
    {
        $this->queue = $queue;
        $this->tracker = $tracker;
        $this->codes = $codes;
        $this->record = $record;
        $this->ingest = $ingest;
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
     *     cart_attribution: list<array{cart: int, recommendation: string, context: string, visitor_token: string, updated_at: string}>,
     *     codes: list<array{code: string, created_at: string, expires_at: string, used: bool}>,
     *     preference: array{opted_out: bool, changed_at: string, source: string}|null
     * } queue times in UTC, the others in the shop's time; a Campaign Intelligence queue row is in
     *   `queue` with the type `engine.<domain>`
     */
    public function export(string $email, int $idCustomer = 0): array
    {
        $address = self::address($email);
        if ($address === '') {
            return ['queue' => [], 'carts' => [], 'attribution' => [], 'cart_attribution' => [], 'codes' => [], 'preference' => null];
        }
        $customers = $this->customers($address, $idCustomer);

        return [
            'queue' => array_merge(
                array_map(static fn (array $row): array => [
                    'type' => (string) $row['event_type'],
                    'status' => (string) $row['status'],
                    'created_at' => (string) $row['created_at'],
                ], $this->queueRows($email)),
                array_map(static fn (array $row): array => [
                    'type' => 'engine.' . $row['domain'],
                    'status' => (string) $row['status'],
                    'created_at' => (string) $row['created_at'],
                ], $this->ingest->ofShopper($customers, $this->orders($customers)))
            ),
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
            'cart_attribution' => array_map(static fn (array $row): array => [
                'cart' => (int) $row['id_cart'],
                'recommendation' => (string) $row['rec_id'],
                'context' => (string) $row['context'],
                'visitor_token' => (string) $row['visitor_token'],
                'updated_at' => (string) $row['updated_at'],
            ], $this->cartAttribution($address, $customers)),
            'codes' => array_map(static fn (array $code): array => [
                'code' => $code['code'],
                'created_at' => $code['created_at'],
                'expires_at' => $code['expires_at'],
                'used' => $code['used'],
            ], $this->codes($address, $customers)),
            'preference' => $this->record->find($email),
        ];
    }

    /**
     * Erases the shopper from the module's tables (the class comment says what goes and what stays).
     *
     * @param int $idCustomer the customer account of the request, 0 when it names an address only
     *
     * @return array{queue_removed: int, queue_cleared: int, carts: int, attribution: int, codes_removed: int, codes_kept: int} the
     *                                                                                                                          queue counts are of both queues;
     *                                                                                                                          `attribution` counts the orders that
     *                                                                                                                          lost their visitor token and the
     *                                                                                                                          carts that lost their row
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
            array_map(static fn (array $row): int => (int) $row['id'], $this->queueRows($email)),
            ResendGuard::ERASED_ENTITY
        );
        $ingest = $this->ingest->erase(
            array_map(static fn (array $row): int => (int) $row['id'], $this->ingest->ofShopper($customers, $this->orders($customers))),
            ResendGuard::ERASED_ENTITY
        );
        // Before the tracker: its rows tie a cart to an address that has no customer account yet.
        $kept = array_map(static fn (array $row): int => (int) $row['id_cart'], $this->cartAttribution($address, $customers));
        if ($kept !== []) {
            Sql::write(sprintf('DELETE FROM `%s%s` WHERE `id_cart` IN (%s)', _DB_PREFIX_, CartAttribution::TABLE, implode(', ', $kept)));
        }
        $carts = $this->tracker->erase($address, $customers);

        $orders = array_map(static fn (array $row): int => (int) $row['id_order'], array_filter(
            $this->attribution($customers),
            static fn (array $row): bool => $row['visitor_token'] !== null
        ));
        if ($orders !== []) {
            Sql::write(sprintf(
                'UPDATE `%s%s` SET `visitor_token` = NULL WHERE `id_order` IN (%s)',
                _DB_PREFIX_,
                OrderAttribution::TABLE,
                implode(', ', $orders)
            ));
        }

        return [
            'queue_removed' => $queue['removed'] + $ingest['removed'],
            'queue_cleared' => $queue['cleared'] + $ingest['cleared'],
            'carts' => $carts,
            'attribution' => count($orders) + count($kept),
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
     * The hash is taken from the address as given, as every writer of a row takes it.
     *
     * @return list<array<string, string|null>>
     */
    private function queueRows(string $email): array
    {
        $address = self::address($email);
        $key = ContactEntity::key($email);

        return array_values(array_filter(
            $this->queue->ofEntities([$address, $key]),
            static fn (array $row): bool => in_array(self::address((string) $row['entity_id']), [$address, $key], true)
        ));
    }

    /**
     * The orders of the customer accounts, in every shop.
     *
     * @param list<int> $customers
     *
     * @return list<int>
     */
    private function orders(array $customers): array
    {
        if ($customers === []) {
            return [];
        }
        $rows = Sql::rows(sprintf('SELECT `id_order` FROM `%sorders` WHERE `id_customer` IN (%s)', _DB_PREFIX_, implode(', ', $customers)));

        return array_map(static fn (array $row): int => (int) $row['id_order'], $rows);
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
            OrderAttribution::TABLE,
            implode(', ', $customers)
        ));
    }

    /**
     * The attribution kept with the shopper's carts: the carts of the customer accounts, and the
     * carts whose tracker row holds the address or an account.
     *
     * @param list<int> $customers
     *
     * @return list<array<string, string|null>>
     */
    private function cartAttribution(string $address, array $customers): array
    {
        $carts = array_map(static fn (array $row): int => (int) $row['id_cart'], $this->tracker->ofShopper($address, $customers));
        $conditions = array_merge(
            $carts === [] ? [] : ['a.`id_cart` IN (' . implode(', ', $carts) . ')'],
            $customers === [] ? [] : ['c.`id_customer` IN (' . implode(', ', $customers) . ')']
        );
        if ($conditions === []) {
            return [];
        }

        return Sql::rows(sprintf(
            'SELECT a.`id_cart`, a.`rec_id`, a.`context`, a.`visitor_token`, a.`updated_at` FROM `%1$s%2$s` a'
            . ' LEFT JOIN `%1$scart` c ON c.`id_cart` = a.`id_cart` WHERE %3$s ORDER BY a.`id_cart`',
            _DB_PREFIX_,
            CartAttribution::TABLE,
            implode(' OR ', $conditions)
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
