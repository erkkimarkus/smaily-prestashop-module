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

namespace PrestaShop\Module\SmailyForPrestaShop\Automation\FirstOrder;

if (!defined('_PS_VERSION_')) {
    exit;
}

use PrestaShop\Module\SmailyForPrestaShop\Automation\Trigger;
use PrestaShop\Module\SmailyForPrestaShop\Config\AutomationSettings;
use PrestaShop\Module\SmailyForPrestaShop\Config\ContactsSettings;
use PrestaShop\Module\SmailyForPrestaShop\Contacts\SubscriberPayloadBuilder;
use PrestaShop\Module\SmailyForPrestaShop\Queue\EventQueue;
use PrestaShop\Module\SmailyForPrestaShop\Scope\ScopeResolver;

/**
 * Queues the first-order automation for a registered customer's first order (actionValidateOrder),
 * the rule of the WooCommerce connector (`is_first_order()`) and the Magento connector
 * (`OrderPlaced::triggerFirstOrder()`):
 *  - the customer has an account: a guest-checkout customer (`is_guest = 1`) never gets it, as a
 *    guest checkout in the siblings has no customer to count orders of;
 *  - the customer has no earlier order, in any state: an order with a lower id. A cart split into
 *    several orders in one request is one first order, the first of them;
 *  - the automation is on in the order's shop.
 * It asks neither the consent mode nor the contact sync here: the consent is checked at send time
 * (FirstOrderHandler). The trigger's `force_opt_in` is false, so a contact who unsubscribed in
 * Smaily stays unsubscribed.
 *
 * The row carries the address, the order's cart (`id_cart`, for the consent check) and the fields
 * that the contact sync sends (`store` and the selected fields), the siblings' order fields
 * (`order_id`, the order's reference; `order_total`, the total paid; `order_currency`) and the
 * `first_order_automation_at` marker stamped now, in the order's shop and language.
 */
final class FirstOrderTrigger
{
    public const EVENT_TYPE = 'automation.first_order';

    private EventQueue $queue;

    private AutomationSettings $automations;

    private ContactsSettings $contacts;

    private SubscriberPayloadBuilder $builder;

    private ScopeResolver $scopes;

    /** @var array<string, true> the addresses queued in this request */
    private array $queued = [];

    public function __construct(EventQueue $queue, AutomationSettings $automations, ContactsSettings $contacts, SubscriberPayloadBuilder $builder, ScopeResolver $scopes)
    {
        $this->queue = $queue;
        $this->automations = $automations;
        $this->contacts = $contacts;
        $this->builder = $builder;
        $this->scopes = $scopes;
    }

    /**
     * actionValidateOrder.
     *
     * @param array<string, mixed> $params
     */
    public function orderValidated(array $params): void
    {
        $order = $params['order'] ?? null;
        $customer = $params['customer'] ?? null;
        if (!$order instanceof \Order || !$customer instanceof \Customer || (int) $customer->id <= 0 || (int) $customer->is_guest === 1) {
            return;
        }
        $idShop = (int) $order->id_shop;
        $email = strtolower(trim((string) $customer->email));
        if ($email === '' || isset($this->queued[$email]) || !$this->automations->enabled(Trigger::FIRST_ORDER, $idShop)) {
            return;
        }
        if ($this->hasEarlierOrder((int) $customer->id, (int) $order->id)) {
            return;
        }
        $this->queued[$email] = true;

        $fields = $this->builder->build($email, $idShop, '', $this->contacts->fields($idShop), [
            'firstname' => $customer->firstname,
            'lastname' => $customer->lastname,
            'birthday' => $customer->birthday,
            'website' => $customer->website,
        ], null);
        unset($fields['email']);
        $currency = new \Currency((int) $order->id_currency);
        $fields += [
            'order_id' => (string) $order->reference,
            'order_total' => number_format((float) $order->total_paid, (int) $currency->precision, '.', ''),
            'order_currency' => (string) $currency->iso_code,
        ];
        $language = $this->scopes->forRecord($order)->language();
        $this->queue->enqueue(
            self::EVENT_TYPE,
            $idShop,
            ['email' => $email, 'id_cart' => (int) $order->id_cart, 'fields' => $fields + Trigger::marker(Trigger::FIRST_ORDER)],
            strlen($email) <= 64 ? $email : null,
            $language !== '' ? $language : null
        );
    }

    private function hasEarlierOrder(int $idCustomer, int $idOrder): bool
    {
        return (bool) \Db::getInstance()->getValue(sprintf(
            'SELECT 1 FROM `%sorders` WHERE `id_customer` = %d AND `id_order` < %d',
            _DB_PREFIX_,
            $idCustomer,
            $idOrder
        ), false);
    }
}
