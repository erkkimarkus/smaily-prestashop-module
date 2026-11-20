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

use PrestaShop\Module\SmailyForPrestaShop\Scheduler\TickState;

/**
 * Settings of the background tasks: the token of each door to the tick, and the tick state.
 *
 * The two legacy tokens keep their 2.x names: they are in merchants' crontabs. Each is read as 2.x
 * read it, at the shop the request is for, then its group, then the global scope.
 */
final class TickSettings extends Settings
{
    public const TICK_TOKEN = self::PREFIX . 'TICK_TOKEN';

    public const CUSTOMER_CRON_TOKEN = 'SMAILY_CUSTOMER_CRON_TOKEN';

    public const CART_CRON_TOKEN = 'SMAILY_CART_CRON_TOKEN';

    public const TICK_STATE = self::PREFIX . 'TICK_STATE';

    /**
     * The token stored under $key for shop $idShop. A token that no scope holds is created on this
     * first read, at the global scope, so that a fresh install has one for each door. A token stored
     * empty stays empty: that door is closed until a token is set.
     */
    public function token(string $key, int $idShop): string
    {
        $token = self::read($key, $idShop);
        if ($token === null) {
            // The configuration this request loaded may predate a token that another request stored.
            $this->reload();
            $token = self::read($key, $idShop);
        }
        if ($token !== null) {
            return $token;
        }

        $token = bin2hex(random_bytes(16));
        $this->set($key, $token);

        return $token;
    }

    /**
     * The tick state as this request loaded it with the rest of the configuration: reading it costs
     * no query.
     */
    public function state(): TickState
    {
        return TickState::fromJson($this->get(self::TICK_STATE));
    }

    /**
     * The tick state as it is stored now, also when another request wrote it after this one
     * started.
     */
    public function storedState(): TickState
    {
        return TickState::fromJson($this->storedValue(self::TICK_STATE));
    }

    public function saveState(TickState $state): void
    {
        $this->set(self::TICK_STATE, $state->toJson());
    }

    /**
     * The token under $key as 2.x read it, at shop $idShop, then its group, then the global scope;
     * null when none of them holds one.
     */
    private static function read(string $key, int $idShop): ?string
    {
        $token = \Configuration::get($key, null, (int) \Shop::getGroupFromShop($idShop), $idShop);

        return $token === false || $token === null ? null : (string) $token;
    }
}
