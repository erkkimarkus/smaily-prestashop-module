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

namespace PrestaShop\Module\SmailyForPrestaShop\Scheduler;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * The lock that lets one tick run at a time: a named database lock (GET_LOCK), held by the
 * database connection of the request that took it. The database frees it when that connection
 * ends, so a request that dies leaves no lock behind.
 */
final class Lock
{
    /**
     * Takes the lock without waiting.
     *
     * @return bool false when another connection holds it
     */
    public function acquire(): bool
    {
        return (int) \Db::getInstance()->getValue('SELECT GET_LOCK(\'' . self::name() . '\', 0)', false) === 1;
    }

    public function release(): void
    {
        \Db::getInstance()->getValue('SELECT RELEASE_LOCK(\'' . self::name() . '\')', false);
    }

    /**
     * The lock's name: the shop's database name, so that shops on one database server do not
     * share it, within the 64 characters a lock name may have.
     */
    public static function name(): string
    {
        return pSQL(substr(_DB_NAME_, 0, 52)) . '_smaily_tick';
    }
}
