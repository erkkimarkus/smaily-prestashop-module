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

namespace PrestaShop\Module\SmailyForPrestaShop\Automation;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * The store automations of 3.0.0, by the name the mapping table and the settings use.
 */
final class Trigger
{
    public const WELCOME = 'welcome';

    public const FIRST_ORDER = 'first_order';

    public const ABANDONED_CART = 'abandoned_cart';

    public const ALL = [self::WELCOME, self::FIRST_ORDER, self::ABANDONED_CART];

    /**
     * The Smaily contact field that records when each automation last ran for the contact. The names
     * are the ones the Magento and WooCommerce connectors write; merchants build segments on them,
     * so they never change.
     */
    public const MARKER_FIELDS = [
        self::WELCOME => 'welcome_automation_at',
        self::FIRST_ORDER => 'first_order_automation_at',
        self::ABANDONED_CART => 'abandoned_cart_automation_at',
    ];

    /**
     * The trigger's marker field stamped now, in UTC `Y-m-d H:i:s`, to add to the payload fields
     * when the trigger fires (the siblings stamp it when the event is queued, so a retry sends the
     * moment of the store event); [] for a trigger without a marker.
     *
     * @return array<string, string>
     */
    public static function marker(string $trigger): array
    {
        return isset(self::MARKER_FIELDS[$trigger]) ? [self::MARKER_FIELDS[$trigger] => gmdate('Y-m-d H:i:s')] : [];
    }
}
