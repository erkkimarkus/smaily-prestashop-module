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

namespace PrestaShop\Module\SmailyForPrestaShop\Config;

if (!defined('_PS_VERSION_')) {
    exit;
}

use PrestaShop\Module\SmailyForPrestaShop\Contacts\ContactSyncMode;
use PrestaShop\Module\SmailyForPrestaShop\Contacts\SubscriberPayloadBuilder;

/**
 * Settings of the Contacts section, per shop (a shop without its own value reads the global one),
 * in the keys and formats that the 3.0.0 upgrade writes (Migration\LegacyConfigMapper).
 *
 * Not final: the unit tests stand in for the stored settings.
 */
class ContactsSettings extends Settings
{
    /** `1` contact sync is switched on, else off. */
    public const ENABLED = self::PREFIX . 'CONTACTS_ENABLED';

    /** `1` once the Contacts settings were saved, or the upgrade carried an active sync over. */
    public const CONFIRMED = self::PREFIX . 'CONTACTS_CONFIRMED';

    /** A ContactSyncMode mode. */
    public const MODE = self::PREFIX . 'CONTACTS_MODE';

    /** `1` the newsletter-block subscribers without an account are contacts. */
    public const GUESTS = self::PREFIX . 'CONTACTS_GUESTS';

    /** The selected fields, comma-separated Smaily names; `store` among them is always sent anyway. */
    public const FIELDS = self::PREFIX . 'CONTACTS_FIELDS';

    /** `1` the module shows its own newsletter checkbox at checkout (where ps_emailsubscription does not). */
    public const CHECKOUT_CHECKBOX = self::PREFIX . 'CHECKOUT_CHECKBOX';

    /**
     * Whether a shop's contacts go to Smaily: sync is on and its settings were confirmed. Nothing
     * stored is off.
     */
    public function syncActive(int $idShop): bool
    {
        return $this->get(self::ENABLED, $idShop) === '1' && $this->get(self::CONFIRMED, $idShop) === '1';
    }

    /** Whether a shop's contact sync is switched on. */
    public function enabled(int $idShop): bool
    {
        return $this->get(self::ENABLED, $idShop) === '1';
    }

    /** Whether a shop's Contacts settings were confirmed. */
    public function confirmed(int $idShop): bool
    {
        return $this->get(self::CONFIRMED, $idShop) === '1';
    }

    public function mode(int $idShop): ContactSyncMode
    {
        return new ContactSyncMode((string) $this->get(self::MODE, $idShop), $this->get(self::GUESTS, $idShop) === '1');
    }

    /**
     * The selected fields that a contact row carries, by Smaily name.
     *
     * @return list<string>
     */
    public function fields(int $idShop): array
    {
        return array_values(array_intersect(
            explode(',', (string) $this->get(self::FIELDS, $idShop)),
            SubscriberPayloadBuilder::FIELDS
        ));
    }

    /**
     * The stored checkout checkbox setting of a shop, or null when none is stored.
     */
    public function checkoutCheckbox(int $idShop): ?bool
    {
        $value = $this->get(self::CHECKOUT_CHECKBOX, $idShop);

        return $value === null ? null : $value === '1';
    }

    /**
     * Whether any Contacts setting is stored for the shop or globally.
     */
    public function isStored(int $idShop): bool
    {
        return $this->get(self::MODE, $idShop) !== null;
    }

    /**
     * Stores a shop's mode and "include guests" (0 = the global scope).
     */
    public function setMode(ContactSyncMode $mode, int $idShop): bool
    {
        return $this->set(self::MODE, $mode->mode(), $idShop)
            && $this->set(self::GUESTS, $mode->includesNewsletterGuests() ? '1' : '0', $idShop);
    }

    /**
     * Stores a shop's selected fields, known names only, `store` first as the upgrade writes them.
     *
     * @param list<string> $fields
     */
    public function setFields(array $fields, int $idShop): bool
    {
        $known = array_values(array_intersect(SubscriberPayloadBuilder::FIELDS, $fields));

        return $this->set(self::FIELDS, implode(',', array_merge(['store'], $known)), $idShop);
    }

    public function setCheckoutCheckbox(bool $show, int $idShop): bool
    {
        return $this->set(self::CHECKOUT_CHECKBOX, $show ? '1' : '0', $idShop);
    }

    public function setEnabled(bool $enabled, int $idShop): bool
    {
        return $this->set(self::ENABLED, $enabled ? '1' : '0', $idShop);
    }

    /**
     * Marks a shop's Contacts settings as confirmed by the merchant: with sync on, its contacts go to
     * Smaily from now on.
     */
    public function confirm(int $idShop): bool
    {
        return $this->set(self::CONFIRMED, '1', $idShop);
    }
}
