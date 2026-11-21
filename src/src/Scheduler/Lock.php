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

use PrestaShop\Module\SmailyForPrestaShop\Database\Sql;
use PrestaShop\Module\SmailyForPrestaShop\Database\SqlException;
use PrestaShop\Module\SmailyForPrestaShop\Runtime\Log;

/**
 * A named database lock (GET_LOCK), held by the database connection of the request that took it.
 * The database frees it when that connection ends, so a request that dies leaves no lock behind.
 * The tick takes the lock `tick`, so that one tick runs at a time; locks of other names do not
 * exclude it.
 */
final class Lock
{
    private string $purpose;

    /**
     * @param string $purpose what the lock guards, part of its name
     */
    public function __construct(string $purpose = 'tick')
    {
        $this->purpose = $purpose;
    }

    /**
     * Takes the lock without waiting.
     *
     * @return bool false when another connection holds it, and when the database did not answer:
     *              a lock that could not be asked for is not held
     */
    public function acquire(): bool
    {
        try {
            return (int) Sql::value('SELECT GET_LOCK(\'' . self::name($this->purpose) . '\', 0)') === 1;
        } catch (SqlException $error) {
            Log::failure('taking the lock ' . $this->purpose, $error);

            return false;
        }
    }

    /**
     * Frees the lock. A release that the database did not answer is left to the end of the
     * connection, which frees the lock too.
     */
    public function release(): void
    {
        try {
            Sql::value('SELECT RELEASE_LOCK(\'' . self::name($this->purpose) . '\')');
        } catch (SqlException $error) {
            Log::failure('releasing the lock ' . $this->purpose, $error);
        }
    }

    /**
     * The lock's name: the shop's database name, so that shops on one database server do not
     * share it, and the purpose, within the 64 characters a lock name may have.
     */
    public static function name(string $purpose = 'tick'): string
    {
        $suffix = '_smaily_' . $purpose;

        return pSQL(substr(_DB_NAME_, 0, 64 - strlen($suffix))) . $suffix;
    }
}
