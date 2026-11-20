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

use PrestaShop\Module\SmailyForPrestaShop\Automation\Trigger;

/**
 * Settings of the Automations section, per shop (0 = global; a shop without its own value reads
 * the global one), in the keys and formats that the 3.0.0 upgrade writes
 * (Migration\LegacyConfigMapper). The workflows live in the mapping table (MappingRepository).
 *
 * Not final: the unit tests stand in for the stored settings.
 */
class AutomationSettings extends Settings
{
    /** `1` the welcome automation is on, else off. */
    public const WELCOME_ENABLED = self::PREFIX . 'WELCOME_ENABLED';

    /** `1` the first-order automation is on, else off. */
    public const FIRST_ORDER_ENABLED = self::PREFIX . 'FIRST_ORDER_ENABLED';

    /** `1` the abandoned-cart automation is on, else off. */
    public const CART_ENABLED = self::PREFIX . 'CART_ENABLED';

    /** Minutes after the cart's last change before its reminder goes. */
    public const CART_DELAY_MINUTES = self::PREFIX . 'CART_DELAY_MINUTES';

    /** The abandoned-cart template fields to send, comma-separated, of CART_FIELD_NAMES. */
    public const CART_FIELDS = self::PREFIX . 'CART_FIELDS';

    /** The abandoned-cart template fields of 2.x, in their order. */
    public const CART_FIELD_NAMES = ['first_name', 'last_name', 'name', 'description', 'sku', 'price', 'base_price', 'quantity'];

    /** The shortest cart delay, and the delay when none is stored (2.x installed 15). */
    public const MIN_CART_DELAY_MINUTES = 15;

    private const SWITCHES = [
        Trigger::WELCOME => self::WELCOME_ENABLED,
        Trigger::FIRST_ORDER => self::FIRST_ORDER_ENABLED,
        Trigger::ABANDONED_CART => self::CART_ENABLED,
    ];

    /**
     * Whether the automation of $trigger is on in the shop. Nothing stored, and an unknown trigger,
     * is off.
     */
    public function enabled(string $trigger, int $idShop): bool
    {
        return isset(self::SWITCHES[$trigger]) && $this->get(self::SWITCHES[$trigger], $idShop) === '1';
    }

    public function setEnabled(string $trigger, bool $enabled, int $idShop): bool
    {
        if (!isset(self::SWITCHES[$trigger])) {
            throw new \InvalidArgumentException('Unknown automation trigger.');
        }

        return $this->set(self::SWITCHES[$trigger], $enabled ? '1' : '0', $idShop);
    }

    public function cartDelayMinutes(int $idShop): int
    {
        return max(self::MIN_CART_DELAY_MINUTES, (int) $this->get(self::CART_DELAY_MINUTES, $idShop));
    }

    public function setCartDelayMinutes(int $minutes, int $idShop): bool
    {
        if ($minutes < self::MIN_CART_DELAY_MINUTES) {
            throw new \InvalidArgumentException('The cart delay is at least 15 minutes.');
        }

        return $this->set(self::CART_DELAY_MINUTES, (string) $minutes, $idShop);
    }

    /**
     * The abandoned-cart template fields to send, of CART_FIELD_NAMES, in the stored order.
     *
     * @return list<string>
     */
    public function cartFields(int $idShop): array
    {
        return array_values(array_intersect(
            explode(',', (string) $this->get(self::CART_FIELDS, $idShop)),
            self::CART_FIELD_NAMES
        ));
    }

    /**
     * Stores the known names of $fields in the order of CART_FIELD_NAMES, as the upgrade does.
     *
     * @param list<string> $fields
     */
    public function setCartFields(array $fields, int $idShop): bool
    {
        return $this->set(self::CART_FIELDS, implode(',', array_intersect(self::CART_FIELD_NAMES, $fields)), $idShop);
    }
}
