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

/**
 * The base of every settings section.
 *
 * A value lives at the global scope or at one shop's scope, never at a shop group's. Every call
 * names the scope (shop id 0 = global) and never relies on the admin's shop context. A read of a
 * shop returns the shop's own value, else the global one.
 */
abstract class Settings
{
    public const PREFIX = 'SMAILY_CONNECT_';

    protected function get(string $key, int $idShop = 0): ?string
    {
        if ($idShop > 0 && \Configuration::hasKey($key, null, null, $idShop)) {
            return (string) \Configuration::get($key, null, null, $idShop);
        }
        if (!\Configuration::hasKey($key)) {
            return null;
        }

        return (string) \Configuration::getGlobalValue($key);
    }

    protected function set(string $key, string $value, int $idShop = 0): bool
    {
        if ($idShop === 0) {
            return \Configuration::updateGlobalValue($key, $value);
        }

        return \Configuration::updateValue($key, $value, false, (int) \Shop::getGroupFromShop($idShop), $idShop);
    }
}
