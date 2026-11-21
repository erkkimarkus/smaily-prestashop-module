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
 * The consent mode of a shop (ARCHITECTURE 6.2), the presets of the sibling connectors:
 *  - subscribers only (the default, and the mode of every upgraded store): customers with
 *    `newsletter = 1`; the store and Smaily reconcile both ways;
 *  - all customers: every customer, and Smaily keeps the subscription status;
 *  - checkout opt-in only: a checkout with the newsletter box ticked, and nothing else.
 *
 * "Include guests" adds the guest-checkout customers (`is_guest = 1`); in subscribers only a
 * guest-checkout customer goes only with the newsletter box ticked. It has no effect in checkout
 * opt-in only. A newsletter-block subscriber without an account (`emailsubscription`) does not
 * depend on it (owner, 2026-11-21, as Magento): ContactAudience sends one by the mode alone. An
 * unknown stored mode reads as subscribers only, so a bad value never widens the audience.
 */
final class ContactSyncMode
{
    public const SUBSCRIBERS = 'subscribers';

    public const ALL_CUSTOMERS = 'all_customers';

    public const CHECKOUT_OPTIN = 'checkout_optin';

    private const MODES = [self::SUBSCRIBERS, self::ALL_CUSTOMERS, self::CHECKOUT_OPTIN];

    private string $mode;

    private bool $includeGuests;

    public function __construct(string $mode, bool $includeGuests)
    {
        $this->mode = in_array($mode, self::MODES, true) ? $mode : self::SUBSCRIBERS;
        $this->includeGuests = $includeGuests;
    }

    public function mode(): string
    {
        return $this->mode;
    }

    /**
     * Whether "include guests" is on: guest-checkout customers are contacts in subscribers only and
     * in all customers. Checkout opt-in only never asks, and reads as off.
     */
    public function includesGuests(): bool
    {
        return $this->includeGuests && $this->mode !== self::CHECKOUT_OPTIN;
    }

    /**
     * Whether the store takes Smaily's unsubscribes and re-subscribes back.
     */
    public function reconciles(): bool
    {
        return $this->mode === self::SUBSCRIBERS;
    }
}
