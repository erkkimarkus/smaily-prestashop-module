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

use PrestaShop\Module\SmailyForPrestaShop\Automation\Trigger;
use PrestaShop\Module\SmailyForPrestaShop\Config\AutomationSettings;
use PrestaShop\Module\SmailyForPrestaShop\Queue\EventQueue;

/**
 * The discount code that an automation's email carries, as the Magento connector's
 * Discount\AutomationDiscount: made when the queue sends the row, after the router found the
 * workflow (and, where the automation has one, after its consent check), so a row that is skipped
 * makes no code. The router has already skipped a row whose automation is off. The code comes
 * from the cart rule of the automation's settings in the row's shop (CodeIssuer), tied to the
 * customer account of the row's address where the shop has one.
 *
 * Once per queue row: the code and its last valid day go into the row's stored fields before the
 * row is posted (EventQueue::updatePayload()), so an automatic retry and the Log's "Send again"
 * and "Retry" post the same code and make no second one. The write is the claim's: when a slow
 * flush lost the row to another one (EventQueue::requeueStale()), it stores nothing, removes the
 * code it made and throws, so it posts nothing; the flush that holds the row makes the row's one
 * code. A code whose row could not be written at all is removed the same way. Only a process that
 * dies between making the code and storing it leaves a code that no row names and nobody was
 * sent: the daily clean-up removes it 30 days after its end date (IssuedCodes).
 *
 * A cart rule that gives no code for the shop now (TemplateRules::usable()) does not hold the
 * email back: it goes without a code, and the row's payload keeps the reason under NOTICE_KEY,
 * which the Log's Details show as a sentence. A row stored that way makes no code on a retry
 * either. An error while the code is made is thrown: nothing was posted, and the row is retried.
 */
final class AutomationDiscount
{
    /** The payload key of the reason an email went without its discount code. */
    public const NOTICE_KEY = 'discount_notice';

    public const NOTICE_RULE_UNAVAILABLE = 'rule_unavailable';

    private AutomationSettings $settings;

    private CodeIssuer $issuer;

    private EventQueue $queue;

    public function __construct(AutomationSettings $settings, CodeIssuer $issuer, EventQueue $queue)
    {
        $this->settings = $settings;
        $this->issuer = $issuer;
        $this->queue = $queue;
    }

    /**
     * The fields that the row sends: $fields with the automation's discount code fields, or as
     * they are when "Send a discount code" is off, the row already carries its code, or the row
     * went without one before.
     *
     * @param array<string, mixed> $event the claimed row
     * @param array<string, mixed> $fields the `fields` of the row's payload
     *
     * @return array<string, mixed>
     *
     * @throws \Throwable when the code cannot be made or stored, or the row is another flush's now
     */
    public function fields(array $event, string $trigger, array $fields): array
    {
        $names = Trigger::COUPON_FIELDS[$trigger] ?? null;
        $idShop = (int) $event['id_shop'];
        $payload = json_decode((string) $event['payload'], true);
        if ($names === null
            || !is_array($payload)
            || isset($fields[$names[0]])
            || isset($payload[self::NOTICE_KEY])
            || !$this->settings->discountEnabled($trigger, $idShop)
        ) {
            return $fields;
        }

        $idRule = $this->settings->discountRule($trigger, $idShop);
        $code = $idRule > 0
            ? $this->issuer->issue($idRule, $this->settings->discountDays($trigger, $idShop), $idShop, self::customerAccount((string) ($payload['email'] ?? ''), $idShop), $trigger)
            : null;
        if ($code === null) {
            $payload[self::NOTICE_KEY] = ['reason' => self::NOTICE_RULE_UNAVAILABLE, 'rule' => $idRule];
            $this->store($event, $payload);

            return $fields;
        }

        $fields[$names[0]] = $code['code'];
        $fields[$names[1]] = $code['expires_at'];
        $payload['fields'] = $fields;
        try {
            $this->store($event, $payload);
        } catch (\Throwable $error) {
            // No row names the code: it goes, and the flush that holds the row makes the row's own.
            $this->issuer->discard($code['id']);
            throw $error;
        }

        return $fields;
    }

    /**
     * Stores the payload on the claimed row.
     *
     * @param array<string, mixed> $event
     * @param array<mixed> $payload
     *
     * @throws \RuntimeException when another flush holds the row now
     */
    private function store(array $event, array $payload): void
    {
        if (!$this->queue->updatePayload($event, $payload)) {
            throw new \RuntimeException('Another flush took the row over.');
        }
    }

    /**
     * The customer account of the address that can sign in to the shop: its own, or one of a shop
     * of its group where the group shares customers. 0 where the address has none; a
     * guest-checkout customer row is no account.
     */
    private static function customerAccount(string $email, int $idShop): int
    {
        if (trim($email) === '') {
            return 0;
        }

        return (int) \Db::getInstance()->getValue(sprintf(
            'SELECT cu.`id_customer` FROM `%1$scustomer` cu'
            . ' INNER JOIN `%1$sshop` s ON s.`id_shop` = %2$d'
            . ' INNER JOIN `%1$sshop_group` sg ON sg.`id_shop_group` = s.`id_shop_group`'
            . ' WHERE cu.`email` = \'%3$s\' AND cu.`is_guest` = 0 AND cu.`deleted` = 0'
            . ' AND (cu.`id_shop` = s.`id_shop` OR (sg.`share_customer` = 1 AND cu.`id_shop_group` = s.`id_shop_group`))'
            . ' ORDER BY cu.`id_customer`',
            _DB_PREFIX_,
            $idShop,
            pSQL(trim($email))
        ), false);
    }
}
