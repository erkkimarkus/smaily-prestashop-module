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

namespace PrestaShop\Module\SmailyForPrestaShop\Admin\Dashboard;

if (!defined('_PS_VERSION_')) {
    exit;
}

use PrestaShop\Module\SmailyForPrestaShop\Scheduler\Door;
use PrestaShop\Module\SmailyForPrestaShop\Scheduler\TickState;

/**
 * The state of the Dashboard's Background tasks card, from the tick state alone (ARCHITECTURE 4.3):
 * RUNNING while a cron URL ran a tick in the last CRON_SECONDS; STOPPED when no tick ran in the last
 * STOPPED_SECONDS, or none ever ran; VISITORS in between, when ticks run but only from shop visits
 * or from a cron URL that is not called every minute.
 */
final class BackgroundTasks
{
    public const RUNNING = 'running';

    public const VISITORS = 'visitors';

    public const STOPPED = 'stopped';

    /** A cron URL that ran a tick this recently keeps the card green. */
    public const CRON_SECONDS = 300;

    /** No tick for longer than this turns the card red. */
    public const STOPPED_SECONDS = 900;

    public const SOURCE_CRON = 'cron';

    public const SOURCE_VISITS = 'visits';

    private const DOORS = [Door::TICK, Door::CUSTOMER_CRON, Door::CART_CRON];

    public static function state(TickState $state, int $now): string
    {
        $last = $state->lastTickAt();
        if ($last === null || $last < $now - self::STOPPED_SECONDS) {
            return self::STOPPED;
        }
        foreach (self::DOORS as $door) {
            if (($state->lastTickFrom($door) ?? 0) >= $now - self::CRON_SECONDS) {
                return self::RUNNING;
            }
        }

        return self::VISITORS;
    }

    /**
     * Where a tick came from: a cron URL (the tick URL or a 2.x cron URL), or shop visits.
     */
    public static function source(string $source): string
    {
        return in_array($source, self::DOORS, true) ? self::SOURCE_CRON : self::SOURCE_VISITS;
    }

    /**
     * The crontab line that calls $url every minute.
     */
    public static function cronLine(string $url): string
    {
        return sprintf('* * * * * curl -fsS "%s" > /dev/null', $url);
    }
}
