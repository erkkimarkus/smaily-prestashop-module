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
 *
 * A shop's connection is read once in a request: the page's scripts and the cart's attribution ask
 * for the same one. Every write of a connection forgets what was read (Engine\Connections calls
 * reset()), so a process that changes a connection reads it again.
 */
final class ActiveConnection
{
    /** The number of reset() calls: what was read before the last one is read again. */
    private static int $resets = 0;

    /** @var callable(int): ?Connection */
    private $forShop;

    /** @var array<int, ?Connection> the stored connection of each shop asked, by shop */
    private array $stored = [];

    /** The value of $resets when $stored was started. */
    private int $readAt = 0;

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
        if ($this->readAt !== self::$resets) {
            $this->stored = [];
            $this->readAt = self::$resets;
        }
        if (!array_key_exists($idShop, $this->stored)) {
            $connection = ($this->forShop)($idShop);
            $this->stored[$idShop] = $connection !== null && $connection->apiKey() !== '' ? $connection : null;
        }

        return $this->stored[$idShop];
    }

    /**
     * Forgets the connections read so far, in every instance: a connection was written.
     */
    public static function reset(): void
    {
        ++self::$resets;
    }

    public function sending(int $idShop): ?Connection
    {
        $connection = $this->stored($idShop);

        return $connection !== null && $connection->isTaken() ? $connection : null;
    }
}
