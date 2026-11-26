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

namespace PrestaShop\Module\SmailyForPrestaShop\Contacts;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Who is a Smaily contact under a consent mode, and which subscription status a contact row
 * carries: the rows of the table in ARCHITECTURE 6.2. The live sync, the imports, the
 * abandoned-cart reminder and the first-order email ask this one object, so they never disagree on
 * the audience.
 *
 * Consent is where PrestaShop keeps it: `customer.newsletter` for a customer or a guest-checkout
 * customer, and an active `emailsubscription` row for a newsletter-block subscriber without an
 * account. `customer.optin` (partner offers) is never consent.
 */
final class ContactAudience
{
    /** A row of `customer`, a guest-checkout customer included. */
    public const SOURCE_CUSTOMER = 'customer';

    /** A newsletter-block subscriber without an account: a row of `emailsubscription`. */
    public const SOURCE_NEWSLETTER_BLOCK = 'newsletter_block';

    public const SOURCES = [self::SOURCE_CUSTOMER, self::SOURCE_NEWSLETTER_BLOCK];

    /** What Smaily holds for an address: a subscribed contact, an unsubscribed one, or none. */
    public const SMAILY_SUBSCRIBED = 'subscribed';

    public const SMAILY_UNSUBSCRIBED = 'unsubscribed';

    public const SMAILY_UNKNOWN = 'unknown';

    private ContactSyncMode $mode;

    public function __construct(ContactSyncMode $mode)
    {
        $this->mode = $mode;
    }

    /**
     * Whether a store event about a contact goes to Smaily.
     *
     * Two events go in every mode, whatever "include guests" says (owner, 2026-11-26, the same rule
     * in every connector; Linear CON-452): an unsubscribe, of any source, and a checkout in which the
     * newsletter box was ticked, a guest-checkout customer's included (WooCommerce CON-439; Magento's
     * guest setting gates only guests who did not tick). Otherwise a newsletter-block subscriber
     * without an account goes by the mode alone, whatever "include guests" says (owner, 2026-11-21):
     * the Magento connector syncs a newsletter-form signup in its consent and legitimate-interest
     * modes whatever its guest setting, and not in its checkout-only mode. The welcome email follows
     * this answer.
     *
     * @param string $source SOURCE_CUSTOMER or SOURCE_NEWSLETTER_BLOCK
     * @param bool $subscribed the contact's consent in the store after the event
     * @param bool $consentChanged whether the store saw the event change that consent
     * @param bool $checkout whether the event is a checkout (a registration or a guest at the order step, or an order)
     * @param bool $guest whether the customer is a guest-checkout customer (`is_guest = 1`)
     */
    public function syncs(string $source, bool $subscribed, bool $consentChanged, bool $checkout, bool $guest = false): bool
    {
        if ($consentChanged && (!$subscribed || $checkout)) {
            return true;
        }
        if ($source === self::SOURCE_NEWSLETTER_BLOCK) {
            return $subscribed && $this->mode->mode() !== ContactSyncMode::CHECKOUT_OPTIN;
        }

        switch ($this->mode->mode()) {
            case ContactSyncMode::ALL_CUSTOMERS:
                return $this->sendsGuest($guest);
            case ContactSyncMode::CHECKOUT_OPTIN:
                return false;
            default:
                return $this->sendsGuest($guest) && $subscribed;
        }
    }

    /**
     * The `is_unsubscribed` a synced contact row carries: true (1) or false (0) only where the store
     * saw the consent change; in all customers only an unsubscribe carries it (owner, 2026-11-26).
     * Null leaves the field out, so Smaily keeps the contact's status (and creates a new contact as
     * subscribed). An import or an automation passes no change, so it never re-subscribes anyone.
     */
    public function unsubscribedStatus(bool $subscribed, bool $consentChanged): ?bool
    {
        if (!$consentChanged || ($subscribed && $this->mode->mode() === ContactSyncMode::ALL_CUSTOMERS)) {
            return null;
        }

        return !$subscribed;
    }

    /**
     * Whether the first import and the daily refresh send a customer.
     *
     * @param bool $guest whether the customer is a guest-checkout customer (`is_guest = 1`)
     */
    public function importsCustomer(bool $subscribed, bool $guest = false): bool
    {
        switch ($this->mode->mode()) {
            case ContactSyncMode::ALL_CUSTOMERS:
                return $this->sendsGuest($guest);
            case ContactSyncMode::CHECKOUT_OPTIN:
                return false;
            default:
                return $this->sendsGuest($guest) && $subscribed;
        }
    }

    /**
     * All customers and subscribers only send a guest-checkout customer's other events only when
     * "include guests" is on (owner, 2026-11-20; the legitimate-interest and consent presets of the
     * WooCommerce connector): in all customers every event, in subscribers only one with the consent.
     * A registered customer is not gated. A checkout with the box ticked and an unsubscribe do not
     * ask (syncs(); owner, 2026-11-26), nor does checkout opt-in only.
     */
    private function sendsGuest(bool $guest): bool
    {
        return !$guest || $this->mode->includesGuests();
    }

    /**
     * Whether the first import and the daily refresh send the active `emailsubscription` rows: in
     * every mode but checkout opt-in only, whatever "include guests" says, as the live sync
     * (syncs()) and as the Magento connector's import, which sends every newsletter subscriber.
     */
    public function importsNewsletterGuests(): bool
    {
        return $this->mode->mode() !== ContactSyncMode::CHECKOUT_OPTIN;
    }

    /**
     * Whether an automated marketing email that no consent event triggered goes out: the
     * abandoned-cart reminder and the first-order email (owner decisions of 2026-11-20, the
     * siblings' rule). The same for a registered customer and a guest-checkout customer, whatever
     * "include guests" says, which gates the contact sync only. A contact that Smaily holds as
     * unsubscribed never gets one. Otherwise:
     *  - all customers: always;
     *  - subscribers only: with the store's consent (`newsletter = 1`, an active newsletter-block
     *    row of the address, or the box ticked in this checkout), or a subscribed Smaily contact;
     *  - checkout opt-in only: with the box ticked in this checkout, or a subscribed Smaily contact
     *    (WooCommerce's rule: an earlier store subscription alone does not count).
     *
     * @param bool $newsletter the customer has `newsletter = 1`
     * @param bool $newsletterBlock the address has an active `emailsubscription` row
     * @param bool $boxTicked the newsletter box was ticked in this checkout (the cart's, or the order's cart's)
     * @param string $smailyStatus SMAILY_SUBSCRIBED, SMAILY_UNSUBSCRIBED or SMAILY_UNKNOWN, read before the send
     */
    public function sendsAutomatedMarketing(bool $newsletter, bool $newsletterBlock, bool $boxTicked, string $smailyStatus): bool
    {
        if ($smailyStatus === self::SMAILY_UNSUBSCRIBED) {
            return false;
        }
        $smailySubscribed = $smailyStatus === self::SMAILY_SUBSCRIBED;
        switch ($this->mode->mode()) {
            case ContactSyncMode::ALL_CUSTOMERS:
                return true;
            case ContactSyncMode::CHECKOUT_OPTIN:
                return $boxTicked || $smailySubscribed;
            default:
                return $newsletter || $newsletterBlock || $boxTicked || $smailySubscribed;
        }
    }
}
