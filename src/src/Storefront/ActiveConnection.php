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

namespace PrestaShop\Module\SmailyForPrestaShop\Storefront;

if (!defined('_PS_VERSION_')) {
    exit;
}

use PrestaShop\Module\SmailyForPrestaShop\Engine\Connection;

/**
 * The Campaign Intelligence connection that serves a shop's storefront.
 *
 * - stored(): the shop has a connection whose key can be read. The click attribution works on it:
 *   it calls no engine.
 * - sending(): the engine also takes the connection (it is not refused, Engine\HealthCheck). The
 *   browse relay, the recommendations and their scripts work on it.
 */
final class ActiveConnection
{
    /** @var callable(int): ?Connection */
    private $forShop;

    /**
     * @param callable(int): ?Connection $forShop the stored connection that serves a shop
     *                                            (Engine\Connections::forShop())
     */
    public function __construct(callable $forShop)
    {
        $this->forShop = $forShop;
    }

    public function stored(int $idShop): ?Connection
    {
        $connection = ($this->forShop)($idShop);

        return $connection !== null && $connection->apiKey() !== '' ? $connection : null;
    }

    public function sending(int $idShop): ?Connection
    {
        $connection = $this->stored($idShop);

        return $connection !== null && self::isTaken($connection) ? $connection : null;
    }

    /**
     * Whether the engine takes the connection: no health check found it refused.
     */
    public static function isTaken(Connection $connection): bool
    {
        return $connection->refusedAt() === '';
    }
}
