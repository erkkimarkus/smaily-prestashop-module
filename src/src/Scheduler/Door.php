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
 *
 * Where the server can send a response before the request ends (TrafficTick::finishFunction()),
 * the door answers as soon as the tick holds the lock, and the jobs run after the answer: a web
 * server's own limit (30 s on nginx) never cuts the cron's request while a job waits on Smaily or
 * Campaign Intelligence. Elsewhere it answers when the tick has ended.
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

    private const ANSWER_WAITING = 'Waiting: the upgrade of the module has not finished.';

    private TickSettings $settings;

    /** @var callable(): Tick */
    private $tick;

    /** @var (callable(): mixed)|null */
    private $finishRequest;

    /**
     * @param callable(): Tick $tick
     * @param (callable(): mixed)|null $finishRequest sends the response before the request ends;
     *                                                null where the server has no such function
     */
    public function __construct(TickSettings $settings, callable $tick, ?callable $finishRequest = null)
    {
        $this->settings = $settings;
        $this->tick = $tick;
        $this->finishRequest = $finishRequest;
    }

    /**
     * Runs the tick through $door when $token is the door's token, and answers once through
     * $respond.
     *
     * @param int $idShop the shop the request is for; a legacy token is read at its scope
     * @param int $maxExecutionTime the request's max_execution_time, 0 for no limit
     * @param callable(int, string): void $respond sends the HTTP status and the plain-text answer
     */
    public function open(string $door, string $token, int $idShop, int $maxExecutionTime, callable $respond): void
    {
        if (!isset(self::TOKEN_KEYS[$door])) {
            throw new \InvalidArgumentException(sprintf('No door is named "%s".', $door));
        }
        $stored = $this->settings->storedToken(self::TOKEN_KEYS[$door], $idShop);
        if ($stored === '' || $token === '' || !hash_equals($stored, $token)) {
            $respond(403, 'Access denied!');

            return;
        }

        $answered = false;
        $started = null;
        if ($this->finishRequest !== null) {
            $started = function (bool $schemaIsCurrent) use ($respond, &$answered): void {
                $respond(200, $schemaIsCurrent ? 'OK: the tick is running.' : self::ANSWER_WAITING);
                $answered = true;
                ignore_user_abort(true);
                ($this->finishRequest)();
                // PHP keeps the session locked until the request ends.
                if (session_status() === PHP_SESSION_ACTIVE) {
                    session_write_close();
                }
            };
        }

        try {
            $report = ($this->tick)()->run($door, self::budget($maxExecutionTime), 0, $started);
        } catch (\Throwable $error) {
            if (!$answered) {
                throw $error;
            }
            // The answer has gone: only the log can tell.
            Tick::logFailure('tick', $error);

            return;
        }
        if ($answered) {
            return;
        }
        switch ($report->status()) {
            case TickReport::BUSY:
                $respond(200, 'Busy: another tick is running.');
                break;
            case TickReport::INERT:
                $respond(200, self::ANSWER_WAITING);
                break;
            default:
                $respond(200, $report->jobs() === [] ? 'OK: no job was due.' : 'OK: ran ' . implode(', ', $report->jobs()) . '.');
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
