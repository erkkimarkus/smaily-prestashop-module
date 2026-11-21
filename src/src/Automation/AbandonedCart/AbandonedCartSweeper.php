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

use PrestaShop\Module\SmailyForPrestaShop\Automation\Trigger;
use PrestaShop\Module\SmailyForPrestaShop\Config\AutomationSettings;
use PrestaShop\Module\SmailyForPrestaShop\Queue\ContactEntity;
use PrestaShop\Module\SmailyForPrestaShop\Queue\EventQueue;
use PrestaShop\Module\SmailyForPrestaShop\Runtime\Log;
use PrestaShop\Module\SmailyForPrestaShop\Scheduler\Deadline;
use PrestaShop\Module\SmailyForPrestaShop\Scope\ScopeResolver;

/**
 * Finds the abandoned carts that are due a reminder and queues one `automation.abandoned_cart` event
 * for each (Magento's Cron\AbandonedCart, run every 5 minutes).
 *
 * In each shop whose abandoned-cart automation is on, a cart is due when it has products and an
 * address (CartAddress), no order, its last change (`cart.date_upd`) lies between the shop's delay
 * and CartTracker::WINDOW_SECONDS (24 hours) ago, and it has no reminder state yet: no tracker row,
 * or an open one that only holds a recorded address. A cart of 2.x's reminders (`mailed`) is
 * never due.
 *
 * One address gets at most one reminder per 24 hours, whatever number of carts carry it: a cart
 * whose address had a reminder sent in that time, or has one in the queue that can still go
 * (CartTracker::blocking()), is closed as `recently_reminded`. Its event is still queued, so the
 * Log shows the skip; the handler closes it.
 *
 * A cart whose customer is gone (CartAddress::customerGone()) gets no reminder at the address its
 * checkout recorded: it is closed as `customer_deleted`, its address is removed, and its event is
 * queued without the address, so the Log shows the skip.
 *
 * The cart's tracker row is written before its event, so a sweep that dies in between leaves an
 * open row without an event that the next sweep takes again. The payload holds the address and the
 * template fields, and the marker field `abandoned_cart_automation_at`, stamped when it is queued.
 */
class AbandonedCartSweeper
{
    public const SKIPPED_RECENTLY_REMINDED = 'recently_reminded';

    public const SKIPPED_PAYLOAD_FAILED = 'payload_failed';

    private const PAGE_SIZE = 100;

    private AutomationSettings $settings;

    private CartTracker $tracker;

    private CartPayloadBuilder $builder;

    private EventQueue $queue;

    private ScopeResolver $scopes;

    /** @var callable(): list<int> */
    private $shops;

    /**
     * @param callable(): list<int> $shops the ids of the shops to sweep
     */
    public function __construct(AutomationSettings $settings, CartTracker $tracker, CartPayloadBuilder $builder, EventQueue $queue, ScopeResolver $scopes, callable $shops)
    {
        $this->settings = $settings;
        $this->tracker = $tracker;
        $this->builder = $builder;
        $this->queue = $queue;
        $this->scopes = $scopes;
        $this->shops = $shops;
    }

    /**
     * @return int the number of events queued
     */
    public function sweep(Deadline $deadline): int
    {
        $this->tracker->purge();
        $blocking = $this->tracker->blocking();
        $queued = 0;
        foreach (($this->shops)() as $idShop) {
            if (!$this->settings->enabled(Trigger::ABANDONED_CART, $idShop)) {
                continue;
            }
            $after = null;
            while (!$deadline->passed() && ($carts = $this->due($idShop, $after)) !== []) {
                foreach ($carts as $cart) {
                    $after = [(string) $cart['date_upd'], (int) $cart['id_cart']];
                    try {
                        $queued += $this->queue($cart, $idShop, $blocking);
                    } catch (\Throwable $error) {
                        Log::failure('the abandoned-cart reminder of a cart', $error);
                        $this->tracker->skip((int) $cart['id_cart'], self::SKIPPED_PAYLOAD_FAILED);
                    }
                }
            }
        }

        return $queued;
    }

    /**
     * @param array<string, mixed> $cart
     * @param array{emails: array<string, true>, customers: array<int, true>} $blocking
     */
    private function queue(array $cart, int $idShop, array &$blocking): int
    {
        $address = CartAddress::of($cart);
        if ($address === null) {
            return 0;
        }
        $idCart = (int) $cart['id_cart'];
        if ((int) $cart['customer_gone'] === 1) {
            // Only a recorded address is left of this shopper: it goes, and the Log shows the skip
            // without it.
            $this->tracker->forget($idCart, AbandonedCartHandler::SKIPPED_CUSTOMER_DELETED);
            $language = $this->scopes->resolve($idShop, (int) $cart['id_lang'])->language();
            $this->tracker->queued($idCart, $this->queue->enqueue(AbandonedCartHandler::EVENT_TYPE, $idShop, ['id_cart' => $idCart], null, $language));

            return 1;
        }
        $key = mb_strtolower($address['email']);
        $blocked = isset($blocking['emails'][$key])
            || ($address['id_customer'] !== null && isset($blocking['customers'][$address['id_customer']]));
        $this->tracker->open($idCart, $idShop, $address['id_customer'], $address['email'], (int) $cart['id_lang'], (string) $cart['date_upd']);
        $language = $this->scopes->resolve($idShop, (int) $cart['id_lang'])->language();
        $entity = ContactEntity::of($address['email']);

        if ($blocked) {
            $this->tracker->skip($idCart, self::SKIPPED_RECENTLY_REMINDED);
            $this->tracker->queued($idCart, $this->queue->enqueue(AbandonedCartHandler::EVENT_TYPE, $idShop, ['id_cart' => $idCart], $entity, $language));

            return 1;
        }

        $fields = $this->builder->build(new \Cart($idCart), $address);
        unset($fields['email']);
        $payload = ['id_cart' => $idCart, 'email' => $address['email'], 'fields' => $fields + Trigger::marker(Trigger::ABANDONED_CART)];
        $this->tracker->queued($idCart, $this->queue->enqueue(AbandonedCartHandler::EVENT_TYPE, $idShop, $payload, $entity, $language));
        $blocking['emails'][$key] = true;

        return 1;
    }

    /**
     * The next page of the shop's due carts after $after (the last cart's `date_upd` and id), in
     * the order of their last change: the shop and `date_upd` select through the cart table's
     * index on (`id_shop`, `date_upd`).
     *
     * @param array{0: string, 1: int}|null $after
     *
     * @return list<array<string, mixed>>
     */
    private function due(int $idShop, ?array $after): array
    {
        $delay = $this->settings->cartDelayMinutes($idShop) * 60;
        $sql = 'SELECT c.`id_cart`, c.`id_lang`, c.`id_customer`, c.`date_add`, c.`date_upd`, ' . CartAddress::columns()
            . ', ' . CartAddress::customerGone() . ' AS `customer_gone`'
            . ' FROM `' . _DB_PREFIX_ . 'cart` c' . CartAddress::joins()
            . ' WHERE c.`id_shop` = ' . $idShop
            . ' AND c.`date_upd` >= \'' . pSQL($this->tracker->format(-CartTracker::WINDOW_SECONDS)) . '\''
            . ' AND c.`date_upd` <= \'' . pSQL($this->tracker->format(-$delay)) . '\''
            . ' AND (t.`id_cart` IS NULL OR (t.`status` = \'' . CartTracker::OPEN . '\' AND t.`event_id` IS NULL))'
            . ' AND ' . CartAddress::present()
            . ' AND NOT EXISTS (SELECT 1 FROM `' . _DB_PREFIX_ . 'orders` o WHERE o.`id_cart` = c.`id_cart`)'
            . ' AND EXISTS (SELECT 1 FROM `' . _DB_PREFIX_ . 'cart_product` cp WHERE cp.`id_cart` = c.`id_cart`)';
        if ($after !== null) {
            $sql .= ' AND (c.`date_upd` > \'' . pSQL($after[0]) . '\' OR (c.`date_upd` = \'' . pSQL($after[0]) . '\' AND c.`id_cart` > ' . $after[1] . '))';
        }
        $rows = \Db::getInstance()->executeS($sql . ' ORDER BY c.`date_upd`, c.`id_cart` LIMIT ' . self::PAGE_SIZE, true, false);

        return is_array($rows) ? $rows : [];
    }
}
