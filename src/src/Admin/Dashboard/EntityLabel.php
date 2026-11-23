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
 * The Entity that the Dashboard's Recent activity shows for a queue row, as Magento shows it
 * (connect-magento: Model/Log/EntityLabel). An entity shows as stored, a contact's address
 * included. Only the keyed hash that stands in for an address longer than the entity column
 * (ARCHITECTURE 2.2) is cut: 64 hex characters read as noise, so their first 12 and "…" stand in.
 * A row without an entity shows a dash.
 *
 * An import job has no entity: it shows how many it holds, contacts for an import to Smaily, and
 * products, customers or orders for an import to Campaign Intelligence.
 */
final class EntityLabel
{
    private const SHOWN_HASH_LENGTH = 12;

    private const HASH_PATTERN = '/^[0-9a-f]{64}$/';

    /** What an import job to Campaign Intelligence counts, by its type: one, and several. */
    private const JOB_COUNTS = [
        'engine_catalog' => ['%count% product', '%count% products'],
        'engine_customers' => ['%count% customer', '%count% customers'],
        'engine_orders' => ['%count% order', '%count% orders'],
    ];

    public static function forDisplay(?string $entity): string
    {
        if ($entity === null || $entity === '') {
            return '—';
        }
        if (preg_match(self::HASH_PATTERN, $entity) === 1) {
            return substr($entity, 0, self::SHOWN_HASH_LENGTH) . '…';
        }

        return $entity;
    }

    /**
     * @param callable(string, array<string, string|int>=): string $translate the admin's translation
     *                                                                        of an EN string
     */
    public static function ofJob(string $type, int $total, callable $translate): string
    {
        [$one, $several] = self::JOB_COUNTS[$type] ?? ['%count% contact', '%count% contacts'];

        return $translate($total === 1 ? $one : $several, ['%count%' => $total]);
    }
}
