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

namespace PrestaShop\Module\SmailyForPrestaShop\Admin\Dashboard;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * The Entity that the Dashboard's Recent activity shows for a queue row. The entity of a contact or
 * automation row is the shopper's address, or a keyed hash that stands in for a long one; the
 * Dashboard shows neither. Both show as the first 12 characters of a keyed hash and "…", the form
 * Magento shows for a hashed entity: one address reads the same on every row, and the label gives
 * nothing of the address away. Any other entity shows as stored; a row without one shows a dash.
 */
final class EntityLabel
{
    private const SHOWN_HASH_LENGTH = 12;

    private const HASH_PATTERN = '/^[0-9a-f]{64}$/';

    /**
     * @param string $key the secret that keys the hash of an address
     */
    public static function forDisplay(?string $entity, string $key): string
    {
        if ($entity === null || $entity === '') {
            return '—';
        }
        if (preg_match(self::HASH_PATTERN, $entity) === 1) {
            return substr($entity, 0, self::SHOWN_HASH_LENGTH) . '…';
        }
        if (strpos($entity, '@') !== false) {
            return substr(hash_hmac('sha256', mb_strtolower(trim($entity)), $key), 0, self::SHOWN_HASH_LENGTH) . '…';
        }

        return $entity;
    }
}
