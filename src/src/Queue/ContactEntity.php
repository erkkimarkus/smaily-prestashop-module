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

namespace PrestaShop\Module\SmailyForPrestaShop\Queue;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * The entity that a contact's event-queue row is stored and matched under, as the Magento
 * connector's Model\Queue\ContactEntity: an address that fits the queue's entity column is the
 * entity itself, so the Log shows it and its Entity filter finds it. A longer address would not
 * fit, so its entity is a keyed hash of 64 hex characters, the same for every row of the contact:
 * HMAC-SHA256 over the address, trimmed and in lower case, with the shop's `_NEW_COOKIE_KEY_`
 * (ARCHITECTURE 2.2). Every place that writes the entity of a contact's row goes through here.
 */
final class ContactEntity
{
    /** The width of the column `entity_id` (Install\Schema). */
    public const MAX_LENGTH = 64;

    public static function of(string $email): string
    {
        if (mb_strlen($email) <= self::MAX_LENGTH) {
            return $email;
        }

        return hash_hmac('sha256', 'smaily-connect-address|' . strtolower(trim($email)), _NEW_COOKIE_KEY_);
    }
}
