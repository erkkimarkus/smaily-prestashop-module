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
 * carries: the rows of the table in ARCHITECTURE 6.2. The live sync, the imports and the
 * abandoned-cart reminder ask this one object, so they never disagree on the audience.
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
     * @param string $source SOURCE_CUSTOMER or SOURCE_NEWSLETTER_BLOCK
     * @param bool $subscribed the contact's consent in the store after the event
     * @param bool $consentChanged whether the store saw the event change that consent
     * @param bool $checkout whether the event is a checkout (a registration or a guest at the order step, or an order)
     */
    public function syncs(string $source, bool $subscribed, bool $consentChanged, bool $checkout): bool
    {
        if ($source === self::SOURCE_NEWSLETTER_BLOCK) {
            if (!$this->mode->includesNewsletterGuests()) {
                return false;
            }

            // In all customers no status is sent, so an unsubscribe would only upsert the address.
            return $this->mode->mode() === ContactSyncMode::SUBSCRIBERS ? $subscribed || $consentChanged : $subscribed;
        }

        switch ($this->mode->mode()) {
            case ContactSyncMode::ALL_CUSTOMERS:
                return true;
            case ContactSyncMode::CHECKOUT_OPTIN:
                return $checkout && $subscribed && $consentChanged;
            default:
                return $subscribed || $consentChanged;
        }
    }

    /**
     * The `is_unsubscribed` a synced contact row carries: true (1) or false (0) only where the store
     * saw the consent change, and never in all customers; null leaves the field out, so Smaily keeps
     * the contact's status (and creates a new contact as subscribed). An import or an automation
     * passes no change, so it never re-subscribes anyone.
     */
    public function unsubscribedStatus(bool $subscribed, bool $consentChanged): ?bool
    {
        if (!$consentChanged || $this->mode->mode() === ContactSyncMode::ALL_CUSTOMERS) {
            return null;
        }

        return !$subscribed;
    }

    /**
     * Whether the first import and the daily refresh send a customer.
     */
    public function importsCustomer(bool $subscribed): bool
    {
        switch ($this->mode->mode()) {
            case ContactSyncMode::ALL_CUSTOMERS:
                return true;
            case ContactSyncMode::CHECKOUT_OPTIN:
                return false;
            default:
                return $subscribed;
        }
    }

    /**
     * Whether the first import and the daily refresh send the active `emailsubscription` rows.
     */
    public function importsNewsletterGuests(): bool
    {
        return $this->mode->includesNewsletterGuests();
    }

    /**
     * Whether an abandoned-cart reminder goes out (decision 3, the siblings' rule).
     *
     * @param bool $newsletter the cart's customer has `newsletter = 1`
     * @param bool $newsletterBlock the address has an active `emailsubscription` row
     * @param bool $boxTicked the newsletter box was ticked in this checkout
     * @param string $smailyStatus SMAILY_SUBSCRIBED, SMAILY_UNSUBSCRIBED or SMAILY_UNKNOWN, read before the send
     */
    public function remindsCart(bool $newsletter, bool $newsletterBlock, bool $boxTicked, string $smailyStatus): bool
    {
        $smailySubscribed = $smailyStatus === self::SMAILY_SUBSCRIBED;
        switch ($this->mode->mode()) {
            case ContactSyncMode::ALL_CUSTOMERS:
                return $smailyStatus !== self::SMAILY_UNSUBSCRIBED;
            case ContactSyncMode::CHECKOUT_OPTIN:
                return $boxTicked || $smailySubscribed;
            default:
                $consent = $newsletter
                    || ($newsletterBlock && $this->mode->includesNewsletterGuests())
                    || $boxTicked
                    || $smailySubscribed;

                return $consent && $smailyStatus !== self::SMAILY_UNSUBSCRIBED;
        }
    }
}
