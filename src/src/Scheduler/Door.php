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

use PrestaShop\Module\SmailyForPrestaShop\Config\TickSettings;

/**
 * The URLs that run the tick: the tick URL and the two cron URLs of 2.x, each behind its own token.
 * A door that has no stored token or an empty one, or a request whose token is empty or wrong, is
 * refused with the answer of 2.x, "Access denied!". A door only compares: a request never creates a
 * token, and a refused request writes nothing. Every answer is a short plain-text status that names
 * no person and no secret.
 */
final class Door
{
    public const TICK = 'tick';

    public const CUSTOMER_CRON = 'customer_cron';

    public const CART_CRON = 'cart_cron';

    private const TOKEN_KEYS = [
        self::TICK => TickSettings::TICK_TOKEN,
        self::CUSTOMER_CRON => TickSettings::CUSTOMER_CRON_TOKEN,
        self::CART_CRON => TickSettings::CART_CRON_TOKEN,
    ];

    /**
     * Seconds a tick from a URL may take at most.
     */
    private const MAX_BUDGET = 50;

    private TickSettings $settings;

    /** @var callable(): Tick */
    private $tick;

    /**
     * @param callable(): Tick $tick
     */
    public function __construct(TickSettings $settings, callable $tick)
    {
        $this->settings = $settings;
        $this->tick = $tick;
    }

    /**
     * Runs the tick through $door when $token is the door's token.
     *
     * @param int $idShop the shop the request is for; a legacy token is read at its scope
     * @param int $maxExecutionTime the request's max_execution_time, 0 for no limit
     *
     * @return array{0: int, 1: string} the HTTP status and the plain-text answer
     */
    public function open(string $door, string $token, int $idShop, int $maxExecutionTime): array
    {
        if (!isset(self::TOKEN_KEYS[$door])) {
            throw new \InvalidArgumentException(sprintf('No door is named "%s".', $door));
        }
        $stored = $this->settings->storedToken(self::TOKEN_KEYS[$door], $idShop);
        if ($stored === '' || $token === '' || !hash_equals($stored, $token)) {
            return [403, 'Access denied!'];
        }

        $report = ($this->tick)()->run($door, self::budget($maxExecutionTime));
        switch ($report->status()) {
            case TickReport::BUSY:
                return [200, 'Busy: another tick is running.'];
            case TickReport::INERT:
                return [200, 'Waiting: the upgrade of the module has not finished.'];
            default:
                return [200, $report->jobs() === [] ? 'OK: no job was due.' : 'OK: ran ' . implode(', ', $report->jobs()) . '.'];
        }
    }

    /**
     * The tick's budget in a request that may take $maxExecutionTime seconds (0: no limit): 50 s,
     * or 5 s less than the limit.
     */
    public static function budget(int $maxExecutionTime): int
    {
        if ($maxExecutionTime <= 0) {
            return self::MAX_BUDGET;
        }

        return max(1, min(self::MAX_BUDGET, $maxExecutionTime - 5));
    }
}
