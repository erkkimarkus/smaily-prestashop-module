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

namespace PrestaShop\Module\SmailyForPrestaShop\Discount;

if (!defined('_PS_VERSION_')) {
    exit;
}

use PrestaShop\Module\SmailyForPrestaShop\Automation\AbandonedCart\AbandonedCartHandler;
use PrestaShop\Module\SmailyForPrestaShop\Automation\FirstOrder\FirstOrderTrigger;
use PrestaShop\Module\SmailyForPrestaShop\Automation\Trigger;
use PrestaShop\Module\SmailyForPrestaShop\Automation\Welcome\WelcomeTrigger;
use PrestaShop\Module\SmailyForPrestaShop\Queue\ContactEntity;
use PrestaShop\Module\SmailyForPrestaShop\Queue\EventQueue;

/**
 * The cart rules that this module issued as discount codes (CodeIssuer::issued(): the rule's
 * description names the rule's own code): the clean-up of the ones nobody used, and a shopper's
 * codes for a privacy request. No other cart rule is read or removed here: not a rule that the
 * merchant made, and not the remainder voucher that PrestaShop makes when a code is spent in part,
 * which copies the code's description under a code of its own and holds the shopper's credit.
 *
 * A code is used once an order carries it (`order_cart_rule`, also a row that an employee removed
 * from the order). A used code stays in every case: its orders name it. A code that only sits in
 * a cart is not used: it is removed with the others, and PrestaShop takes it out of the cart
 * (CartRule::delete()). The janitor's codes are 30 days past their end, so no cart can still
 * order with one, and a privacy erasure removes what the shopper holds.
 *
 * - The janitor removes an unused code once its end date is more than KEEP_SECONDS past, as the
 *   WooCommerce connector does.
 * - A shopper's codes are the rules tied to the customer account (`id_customer`), and for an
 *   address the codes that the address's automation rows of the event queue still hold in their
 *   fields: an untied code holds nothing of the shopper, so only its queue row names its address.
 *   Once the queue's own retention removed that row, nothing ties the code to the address.
 */
final class IssuedCodes
{
    /** How long past its end date an unused code is kept. */
    public const KEEP_SECONDS = 30 * 86400;

    /** The most codes one clean-up call removes. */
    public const BATCH_SIZE = 200;

    private const EVENT_TYPES = [WelcomeTrigger::EVENT_TYPE, FirstOrderTrigger::EVENT_TYPE, AbandonedCartHandler::EVENT_TYPE];

    /** @var callable(): int */
    private $clock;

    /**
     * @param (callable(): int)|null $clock the current Unix time; time() when null
     */
    public function __construct(?callable $clock = null)
    {
        $this->clock = $clock ?? 'time';
    }

    /**
     * Removes up to $limit unused codes whose end date, in the shop's time, is more than
     * KEEP_SECONDS past, oldest first.
     *
     * @return int the number of codes removed
     */
    public function deleteExpiredUnused(int $limit = self::BATCH_SIZE): int
    {
        return $this->delete($this->ids(sprintf(
            'cr.`date_to` > \'0000-00-00 00:00:00\' AND cr.`date_to` < \'%s\' AND %s ORDER BY cr.`id_cart_rule` LIMIT %d',
            pSQL(date('Y-m-d H:i:s', ($this->clock)() - self::KEEP_SECONDS)),
            self::unused(),
            $limit
        )));
    }

    /**
     * The codes tied to a customer account, oldest first.
     *
     * @return list<array{id: int, code: string, created_at: string, expires_at: string, used: bool}>
     */
    public function ofCustomer(int $idCustomer): array
    {
        return $idCustomer > 0 ? $this->rows('cr.`id_customer` = ' . $idCustomer) : [];
    }

    /**
     * The codes that the automation rows of the event queue hold for the address, oldest first.
     * The address matches in any letter case; an address that differs by an accent or a character
     * is another shopper's.
     *
     * @return list<array{id: int, code: string, created_at: string, expires_at: string, used: bool}>
     */
    public function ofAddress(string $email): array
    {
        $address = mb_strtolower(trim($email));
        if ($address === '') {
            return [];
        }
        $rows = \Db::getInstance()->executeS(sprintf(
            'SELECT `payload` FROM `%s%s` WHERE `entity_id` = \'%s\' AND `event_type` IN (\'%s\')',
            _DB_PREFIX_,
            EventQueue::TABLE,
            pSQL(ContactEntity::of($address)),
            implode('\', \'', self::EVENT_TYPES)
        ), true, false);
        $codes = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            $payload = json_decode((string) $row['payload'], true);
            if (!is_array($payload) || !is_array($payload['fields'] ?? null) || mb_strtolower(trim((string) ($payload['email'] ?? ''))) !== $address) {
                continue;
            }
            foreach (Trigger::COUPON_FIELDS as [$field]) {
                if (is_string($payload['fields'][$field] ?? null) && $payload['fields'][$field] !== '') {
                    $codes[] = $payload['fields'][$field];
                }
            }
        }

        return $codes === [] ? [] : $this->rows(sprintf('cr.`code` IN (\'%s\')', implode('\', \'', array_map('pSQL', array_unique($codes)))));
    }

    /**
     * Removes the unused ones of the codes, by the ids that ofCustomer() and ofAddress() answer.
     * An id that is not a code of this module is left alone.
     *
     * @param list<int> $ids
     *
     * @return array{removed: int, kept: int} `kept`: the used codes, which stay with their orders
     */
    public function removeUnused(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if ($ids === []) {
            return ['removed' => 0, 'kept' => 0];
        }
        $in = 'cr.`id_cart_rule` IN (' . implode(', ', $ids) . ')';
        $issued = count($this->ids($in));

        $removed = $this->delete($this->ids($in . ' AND ' . self::unused()));

        return ['removed' => $removed, 'kept' => $issued - $removed];
    }

    /**
     * @return list<array{id: int, code: string, created_at: string, expires_at: string, used: bool}>
     */
    private function rows(string $condition): array
    {
        $rows = \Db::getInstance()->executeS(sprintf(
            'SELECT cr.`id_cart_rule`, cr.`code`, cr.`date_add`, cr.`date_to`, (NOT %s) AS `used` FROM `%scart_rule` cr WHERE %s AND %s ORDER BY cr.`id_cart_rule`',
            self::unused(),
            _DB_PREFIX_,
            CodeIssuer::issued('cr'),
            $condition
        ), true, false);

        return array_map(static fn (array $row): array => [
            'id' => (int) $row['id_cart_rule'],
            'code' => (string) $row['code'],
            'created_at' => (string) $row['date_add'],
            'expires_at' => (string) $row['date_to'],
            'used' => (bool) $row['used'],
        ], is_array($rows) ? $rows : []);
    }

    /**
     * The ids of the issued rules that $condition selects.
     *
     * @return list<int>
     */
    private function ids(string $condition): array
    {
        $rows = \Db::getInstance()->executeS(sprintf(
            'SELECT cr.`id_cart_rule` FROM `%scart_rule` cr WHERE %s AND %s',
            _DB_PREFIX_,
            CodeIssuer::issued('cr'),
            $condition
        ), true, false);

        return array_map(static fn (array $row): int => (int) $row['id_cart_rule'], is_array($rows) ? $rows : []);
    }

    /**
     * Deletes the rules as PrestaShop does, with their restrictions.
     *
     * @param list<int> $ids
     */
    private function delete(array $ids): int
    {
        $deleted = 0;
        foreach ($ids as $id) {
            $deleted += (int) (new \CartRule($id))->delete();
        }

        return $deleted;
    }

    /**
     * The SQL condition that no order carries the cart rule read as `cr`.
     */
    private static function unused(): string
    {
        return 'NOT EXISTS (SELECT 1 FROM `' . _DB_PREFIX_ . 'order_cart_rule` ocr WHERE ocr.`id_cart_rule` = cr.`id_cart_rule`)';
    }
}
