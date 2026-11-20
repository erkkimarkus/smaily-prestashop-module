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

namespace PrestaShop\Module\SmailyForPrestaShop\Runtime;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Lines the module writes to the shop's log.
 */
final class Log
{
    /**
     * Logs that $what failed with $error. The line names the error's class and place only: an
     * exception message can carry a secret or an address.
     *
     * @param string $what what failed, e.g. "the background job queue_flush"
     * @param int $severity the PrestaShopLogger severity, 3 for an error
     */
    public static function failure(string $what, \Throwable $error, int $severity = 3): void
    {
        \PrestaShopLogger::addLog(
            sprintf(
                'Smaily Connect: %s failed with %s at %s:%d',
                $what,
                get_class($error),
                basename($error->getFile()),
                $error->getLine()
            ),
            $severity
        );
    }
}
