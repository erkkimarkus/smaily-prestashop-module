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

use PrestaShop\Module\SmailyForPrestaShop\Database\Sql;
use PrestaShop\Module\SmailyForPrestaShop\Database\SqlException;

/**
 * The base of every settings section.
 *
 * A value lives at the global scope or at one shop's scope, never at a shop group's. Every call
 * names the scope (shop id 0 = global) and never relies on the admin's shop context. A read of a
 * shop returns the shop's own value, else the global one. While multishop is off, PrestaShop keeps
 * one value for the shop and the global scope.
 */
abstract class Settings
{
    public const PREFIX = 'SMAILY_CONNECT_';

    protected function get(string $key, int $idShop = 0): ?string
    {
        // hasKey() reads only the loaded configuration, which a deleteByName() of any key drops.
        if (!\Configuration::configurationIsLoaded()) {
            $this->reload();
        }
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

    /**
     * The JSON object or list stored under $key, read as get() reads it (null: the global scope);
     * [] when none is stored or the value is not one.
     *
     * @return array<mixed>
     */
    protected function getJson(string $key, ?int $idShop = null): array
    {
        $value = json_decode((string) $this->get($key, $idShop ?? 0), true);

        return is_array($value) ? $value : [];
    }

    /**
     * Stores $value as JSON under $key, as set() stores it (null: the global scope).
     *
     * @param array<mixed> $value
     */
    protected function setJson(string $key, array $value, ?int $idShop = null): bool
    {
        return $this->set($key, (string) json_encode($value), $idShop ?? 0);
    }

    /**
     * Makes PrestaShop load its configuration again from the database, dropping what this request
     * loaded before.
     */
    protected function reload(): void
    {
        self::reloadConfiguration();
    }

    /**
     * Makes PrestaShop load its configuration again from the database, dropping what this request
     * loaded before.
     *
     * @throws SqlException when the load failed. PrestaShop 9.2.0 with debug mode off then holds no
     *                      configuration and throws nothing: every key reads as not stored, which
     *                      this module would take for its defaults and a missing token (facts
     *                      file 6.12)
     */
    public static function reloadConfiguration(): void
    {
        \Configuration::resetStaticCache();
        try {
            \Configuration::loadConfiguration();
        } catch (\Exception $error) {
            throw new SqlException($error);
        }
        if (!\Configuration::configurationIsLoaded()) {
            throw new SqlException();
        }
    }

    /**
     * The value stored now under $key at exactly the global scope (null) or shop $idShop, read past
     * the configuration this request loaded, so also a value that another request wrote after this
     * one started. Null when no row is stored there, or the row holds NULL.
     */
    protected function storedValue(string $key, ?int $idShop = null): ?string
    {
        $scope = $idShop !== null && $idShop > 0
            ? sprintf('`id_shop` = %d', $idShop)
            : '`id_shop_group` IS NULL AND `id_shop` IS NULL';

        return Sql::value(sprintf(
            'SELECT `value` FROM `%sconfiguration` WHERE `name` = \'%s\' AND %s',
            _DB_PREFIX_,
            pSQL($key),
            $scope
        ));
    }

    /**
     * Removes a shop's own value, so the shop reads the global one again. It deletes the shop's
     * row whatever the admin's shop context: a shop row that holds NULL would read as '' and not
     * fall back, and deleteFromContext() does nothing in the "All shops" context.
     */
    protected function removeShopValue(string $key, int $idShop): void
    {
        if ($idShop <= 0) {
            throw new \InvalidArgumentException('Only a shop holds an own value.');
        }

        \Configuration::deleteFromGivenContext($key, (int) \Shop::getGroupFromShop($idShop), $idShop);
    }
}
