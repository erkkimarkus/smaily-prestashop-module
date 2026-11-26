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

namespace PrestaShop\Module\SmailyForPrestaShop\Contacts\Capture;

if (!defined('_PS_VERSION_')) {
    exit;
}

use PrestaShop\Module\SmailyForPrestaShop\Contacts\ContactAudience;
use PrestaShop\Module\SmailyForPrestaShop\Database\Sql;

/**
 * Records the contact events of a customer row (a guest-checkout customer included): a new
 * account, a save of the customer (a front-office account edit, the admin form, the admin grid's
 * newsletter switch), and an order.
 *
 * The consent is `customer.newsletter`. A save changes it when the value stored before the save
 * (read in actionObjectCustomerUpdateBefore) differs from the value saved; a new account with
 * `newsletter = 1` is a change. The event belongs to the record's shop and language, never the
 * context's. A soft-deleted customer is not a contact.
 *
 * When the read of the value before the save fails (Database\Sql throws; facts file 6.11), the
 * save may have changed the consent or not. A save that ends not subscribed is then recorded as
 * an unsubscribe, so an unsubscribe is never lost; a save that ends subscribed is recorded
 * without a consent change, so no subscription is ever sent on a failed read (owner, 2026-11-21).
 */
final class CustomerCapture
{
    private ContactEvents $events;

    /** @var array<int, bool|null> the stored consent of each customer being saved, by id; null while its read has not answered */
    private array $before = [];

    public function __construct(ContactEvents $events)
    {
        $this->events = $events;
    }

    /**
     * actionCustomerAccountAdd: a registration, or a guest at the order step.
     *
     * @param array<string, mixed> $params
     */
    public function accountAdded(array $params): void
    {
        $customer = $params['newCustomer'] ?? null;
        if (!$customer instanceof \Customer) {
            return;
        }
        $subscribed = (bool) (int) $customer->newsletter;
        $this->record($customer, (int) $customer->id_shop, (int) $customer->id_lang, $subscribed, $subscribed, self::atOrderStep());
    }

    /**
     * actionObjectCustomerUpdateBefore: keeps the consent stored before the save.
     *
     * @param array<string, mixed> $params
     */
    public function beforeUpdate(array $params): void
    {
        $customer = $params['object'] ?? null;
        if (!$customer instanceof \Customer || (int) $customer->id <= 0) {
            return;
        }
        // Stays null when the read throws: afterUpdate() then knows that the read failed.
        $this->before[(int) $customer->id] = null;
        $this->before[(int) $customer->id] = (bool) (int) Sql::value(
            'SELECT `newsletter` FROM `' . _DB_PREFIX_ . 'customer` WHERE `id_customer` = ' . (int) $customer->id
        );
    }

    /**
     * actionObjectCustomerUpdateAfter: the save, with a consent change where the stored value moved.
     *
     * @param array<string, mixed> $params
     */
    public function afterUpdate(array $params): void
    {
        $customer = $params['object'] ?? null;
        if (!$customer instanceof \Customer) {
            return;
        }
        $subscribed = (bool) (int) $customer->newsletter;
        $id = (int) $customer->id;
        $readFailed = array_key_exists($id, $this->before) && $this->before[$id] === null;
        $changed = $readFailed ? !$subscribed : isset($this->before[$id]) && $this->before[$id] !== $subscribed;
        unset($this->before[$id]);
        $this->record($customer, (int) $customer->id_shop, (int) $customer->id_lang, $subscribed, $changed, false);
    }

    /**
     * actionCustomerAccountUpdate: a front-office account edit, which the customer update hooks
     * also saw; the request keeps one row for both.
     *
     * @param array<string, mixed> $params
     */
    public function accountUpdated(array $params): void
    {
        $customer = $params['customer'] ?? null;
        if (!$customer instanceof \Customer) {
            return;
        }
        $this->record($customer, (int) $customer->id_shop, (int) $customer->id_lang, (bool) (int) $customer->newsletter, false, false);
    }

    /**
     * actionValidateOrder: the profile and the consent at order time, in the order's shop and
     * language.
     *
     * @param array<string, mixed> $params
     */
    public function orderValidated(array $params): void
    {
        $order = $params['order'] ?? null;
        $customer = $params['customer'] ?? null;
        if (!$order instanceof \Order || !$customer instanceof \Customer) {
            return;
        }
        $this->record($customer, (int) $order->id_shop, (int) $order->id_lang, (bool) (int) $customer->newsletter, false, true);
    }

    /**
     * A `contact.sync` payload of a customer row.
     *
     * @return array<string, mixed>
     */
    public static function payload(\Customer $customer, int $idLang, bool $subscribed, bool $consentChanged, bool $checkout): array
    {
        return [
            'email' => (string) $customer->email,
            'source' => ContactAudience::SOURCE_CUSTOMER,
            'subscribed' => $subscribed,
            'consent_changed' => $consentChanged,
            'checkout' => $checkout,
            'guest' => (bool) (int) $customer->is_guest,
            'id_lang' => $idLang,
            'customer' => [
                'firstname' => (string) $customer->firstname,
                'lastname' => (string) $customer->lastname,
                'birthday' => (string) $customer->birthday,
                'website' => (string) $customer->website,
                'id_customer' => (int) $customer->id,
                'id_gender' => (int) $customer->id_gender,
                'id_default_group' => (int) $customer->id_default_group,
            ],
        ];
    }

    private function record(\Customer $customer, int $idShop, int $idLang, bool $subscribed, bool $consentChanged, bool $checkout): void
    {
        if ((int) $customer->deleted === 1 || trim((string) $customer->email) === '') {
            return;
        }
        $this->events->add($idShop, $idLang, self::payload($customer, $idLang, $subscribed, $consentChanged, $checkout));
    }

    /**
     * Whether the request is the checkout's order step, where a guest or a new account fills the
     * customer form.
     */
    private static function atOrderStep(): bool
    {
        $controller = \Context::getContext()->controller;

        return $controller instanceof \FrontController && $controller->php_self === 'order';
    }
}
