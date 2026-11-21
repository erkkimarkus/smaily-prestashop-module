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

    /**
     * The shortest and the longest cart delay that can be saved, as in the Magento connector. A
     * cart older than 24 hours is never reminded, so a longer delay would remind nobody.
     */
    public const MIN_CART_DELAY_MINUTES = 10;

    public const MAX_CART_DELAY_MINUTES = 1435;

    /** The cart delay of a store with none stored. */
    public const DEFAULT_CART_DELAY_MINUTES = 30;

    /**
     * Each automation's discount code (as the Magento and WooCommerce connectors): `_ENABLED` is
     * `1` while "Send a discount code" is on, `_RULE` the id of the cart rule that each code is a
     * copy of, `_DAYS` the days a code is valid.
     */
    public const WELCOME_DISCOUNT_ENABLED = self::PREFIX . 'WELCOME_DISCOUNT_ENABLED';

    public const WELCOME_DISCOUNT_RULE = self::PREFIX . 'WELCOME_DISCOUNT_RULE';

    public const WELCOME_DISCOUNT_DAYS = self::PREFIX . 'WELCOME_DISCOUNT_DAYS';

    public const FIRST_ORDER_DISCOUNT_ENABLED = self::PREFIX . 'FIRST_ORDER_DISCOUNT_ENABLED';

    public const FIRST_ORDER_DISCOUNT_RULE = self::PREFIX . 'FIRST_ORDER_DISCOUNT_RULE';

    public const FIRST_ORDER_DISCOUNT_DAYS = self::PREFIX . 'FIRST_ORDER_DISCOUNT_DAYS';

    public const CART_DISCOUNT_ENABLED = self::PREFIX . 'CART_DISCOUNT_ENABLED';

    public const CART_DISCOUNT_RULE = self::PREFIX . 'CART_DISCOUNT_RULE';

    public const CART_DISCOUNT_DAYS = self::PREFIX . 'CART_DISCOUNT_DAYS';

    /** The keys of each automation's discount code. */
    public const DISCOUNT_KEYS = [
        Trigger::WELCOME => ['enabled' => self::WELCOME_DISCOUNT_ENABLED, 'rule' => self::WELCOME_DISCOUNT_RULE, 'days' => self::WELCOME_DISCOUNT_DAYS],
        Trigger::FIRST_ORDER => ['enabled' => self::FIRST_ORDER_DISCOUNT_ENABLED, 'rule' => self::FIRST_ORDER_DISCOUNT_RULE, 'days' => self::FIRST_ORDER_DISCOUNT_DAYS],
        Trigger::ABANDONED_CART => ['enabled' => self::CART_DISCOUNT_ENABLED, 'rule' => self::CART_DISCOUNT_RULE, 'days' => self::CART_DISCOUNT_DAYS],
    ];

    /** The fewest and the most days a discount code can be valid, and the days of a store with none stored. */
    public const MIN_DISCOUNT_DAYS = 1;

    public const MAX_DISCOUNT_DAYS = 365;

    public const DEFAULT_DISCOUNT_DAYS = 7;

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

    /**
     * The shop's cart delay: the stored minutes, at least the minimum; the default where none is
     * stored. No code stores a delay above the maximum: the upgrade writes the maximum for a 2.x
     * delay of a day or more.
     */
    public function cartDelayMinutes(int $idShop): int
    {
        $stored = (int) $this->get(self::CART_DELAY_MINUTES, $idShop);

        return $stored > 0 ? max(self::MIN_CART_DELAY_MINUTES, $stored) : self::DEFAULT_CART_DELAY_MINUTES;
    }

    public function setCartDelayMinutes(int $minutes, int $idShop): bool
    {
        if ($minutes < self::MIN_CART_DELAY_MINUTES || $minutes > self::MAX_CART_DELAY_MINUTES) {
            throw new \InvalidArgumentException('The cart delay is from 10 to 1435 minutes.');
        }

        return $this->set(self::CART_DELAY_MINUTES, (string) $minutes, $idShop);
    }

    /**
     * Whether the automation's "Send a discount code" is on in the shop. Nothing stored, and an
     * unknown trigger, is off.
     */
    public function discountEnabled(string $trigger, int $idShop): bool
    {
        return isset(self::DISCOUNT_KEYS[$trigger]) && $this->get(self::DISCOUNT_KEYS[$trigger]['enabled'], $idShop) === '1';
    }

    /**
     * The cart rule that the automation's discount codes are copies of in the shop; 0 for none.
     */
    public function discountRule(string $trigger, int $idShop): int
    {
        return isset(self::DISCOUNT_KEYS[$trigger]) ? max(0, (int) $this->get(self::DISCOUNT_KEYS[$trigger]['rule'], $idShop)) : 0;
    }

    /**
     * The days the automation's discount code is valid in the shop: the stored days within the
     * bounds; the default where none is stored.
     */
    public function discountDays(string $trigger, int $idShop): int
    {
        $stored = isset(self::DISCOUNT_KEYS[$trigger]) ? (int) $this->get(self::DISCOUNT_KEYS[$trigger]['days'], $idShop) : 0;

        return $stored > 0 ? min(self::MAX_DISCOUNT_DAYS, $stored) : self::DEFAULT_DISCOUNT_DAYS;
    }

    public function setDiscount(string $trigger, bool $enabled, int $idRule, int $days, int $idShop): void
    {
        if (!isset(self::DISCOUNT_KEYS[$trigger])) {
            throw new \InvalidArgumentException('Unknown automation trigger.');
        }
        if ($days < self::MIN_DISCOUNT_DAYS || $days > self::MAX_DISCOUNT_DAYS) {
            throw new \InvalidArgumentException('A discount code is valid for 1 to 365 days.');
        }
        $keys = self::DISCOUNT_KEYS[$trigger];
        $this->set($keys['enabled'], $enabled ? '1' : '0', $idShop);
        $this->set($keys['rule'], (string) max(0, $idRule), $idShop);
        $this->set($keys['days'], (string) $days, $idShop);
    }
}
