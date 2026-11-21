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
 * Records the subscribes and unsubscribes of the newsletter block (`ps_emailsubscription`).
 *
 * The block keeps an address without an account as an `emailsubscription` row, and the consent of
 * a customer of the shop in `customer.newsletter`, which it writes with plain SQL, so no customer
 * hook fires (facts file 1.10).
 *
 * - A subscribe: actionNewsletterRegistrationAfter fires only after the block stored it, at the
 *   submit, or with the verification email on, at the verification link. The listener reads what
 *   the block stored: an active row, or a customer with `newsletter = 1`.
 * - An unsubscribe: only actionNewsletterRegistrationBefore fires (`action` '1'), before the block
 *   validates the submit. The listener notes the consent stored then, and confirms at the end of
 *   the request that the block removed it: a deleted active row, or a customer whose `newsletter`
 *   went from 1 to 0. A submit that the block refuses changes nothing and records nothing.
 *
 * The block works in the context's shop, so the event is that shop's; a row's language is the
 * language stored with it, a customer's the customer's.
 */
final class NewsletterBlockCapture
{
    /** ps_emailsubscription's NEWSLETTER_SUBSCRIPTION and NEWSLETTER_UNSUBSCRIPTION. */
    private const SUBSCRIBE = '0';

    private const UNSUBSCRIBE = '1';

    private ContactEvents $events;

    public function __construct(ContactEvents $events)
    {
        $this->events = $events;
    }

    /**
     * actionNewsletterRegistrationAfter: a subscribe that the block stored.
     *
     * @param array<string, mixed> $params
     */
    public function subscribed(array $params): void
    {
        $email = self::email($params, self::SUBSCRIBE);
        if ($email === null) {
            return;
        }
        $idShop = (int) \Context::getContext()->shop->id;

        $row = self::newsletterRow($email, $idShop);
        if ($row !== null) {
            if ($row['active']) {
                $this->events->add($idShop, $row['id_lang'], self::payload($email, $row['id_lang'], true));
            }

            return;
        }
        $customer = self::subscribedCustomer($email, $idShop);
        if ($customer !== null) {
            $this->events->add((int) $customer->id_shop, (int) $customer->id_lang, CustomerCapture::payload($customer, (int) $customer->id_lang, true, true, false));
        }
    }

    /**
     * actionNewsletterRegistrationBefore: an unsubscribe, confirmed at the end of the request.
     *
     * @param array<string, mixed> $params
     */
    public function unsubscribing(array $params): void
    {
        $email = self::email($params, self::UNSUBSCRIBE);
        if ($email === null) {
            return;
        }
        $idShop = (int) \Context::getContext()->shop->id;

        // The block removes the address's row when it has one, else the consent of its customers.
        $row = self::newsletterRow($email, $idShop);
        if ($row !== null) {
            if ($row['active']) {
                $this->events->atEnd(function () use ($email, $idShop, $row): void {
                    if (self::newsletterRow($email, $idShop) === null) {
                        $this->events->add($idShop, $row['id_lang'], self::payload($email, $row['id_lang'], false));
                    }
                });
            }

            return;
        }
        $customer = self::subscribedCustomer($email, $idShop);
        if ($customer !== null) {
            $this->events->atEnd(function () use ($customer): void {
                // Read past PrestaShop's object cache: the block writes with plain SQL.
                $newsletter = Sql::value(
                    'SELECT `newsletter` FROM `' . _DB_PREFIX_ . 'customer` WHERE `id_customer` = ' . (int) $customer->id
                );
                if ($newsletter !== null && !(int) $newsletter) {
                    $this->events->add((int) $customer->id_shop, (int) $customer->id_lang, CustomerCapture::payload($customer, (int) $customer->id_lang, false, true, false));
                }
            });
        }
    }

    /**
     * The address of a block submit of the given action, or null.
     *
     * @param array<string, mixed> $params
     */
    private static function email(array $params, string $action): ?string
    {
        $email = $params['email'] ?? null;
        $given = $params['action'] ?? null;
        if (!is_string($email) || trim($email) === '' || !(is_string($given) || is_int($given)) || (string) $given !== $action) {
            return null;
        }

        return $email;
    }

    /**
     * @return array<string, mixed>
     */
    private static function payload(string $email, int $idLang, bool $subscribed): array
    {
        return [
            'email' => $email,
            'source' => ContactAudience::SOURCE_NEWSLETTER_BLOCK,
            'subscribed' => $subscribed,
            'consent_changed' => true,
            'id_lang' => $idLang,
        ];
    }

    /**
     * The block's row of the address in the shop, as the block looks it up.
     *
     * @return array{active: bool, id_lang: int}|null
     */
    private static function newsletterRow(string $email, int $idShop): ?array
    {
        $row = Sql::row(sprintf(
            'SELECT `active`, `id_lang` FROM `%semailsubscription` WHERE `email` = \'%s\' AND `id_shop` = %d',
            _DB_PREFIX_,
            pSQL($email),
            $idShop
        ));

        return $row !== null ? ['active' => (bool) (int) $row['active'], 'id_lang' => (int) $row['id_lang']] : null;
    }

    /**
     * The subscribed customer of the address in the shop: an account before a guest-checkout
     * customer, the newest first.
     */
    private static function subscribedCustomer(string $email, int $idShop): ?\Customer
    {
        $id = (int) Sql::value(sprintf(
            'SELECT `id_customer` FROM `%scustomer` WHERE `email` = \'%s\' AND `id_shop` = %d AND `newsletter` = 1 AND `deleted` = 0'
            . ' ORDER BY `is_guest` ASC, `id_customer` DESC',
            _DB_PREFIX_,
            pSQL($email),
            $idShop
        ));
        if ($id <= 0) {
            return null;
        }
        $customer = new \Customer($id);

        return \Validate::isLoadedObject($customer) ? $customer : null;
    }
}
