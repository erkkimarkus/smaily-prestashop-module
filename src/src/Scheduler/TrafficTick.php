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
 * The fallback tick that visitor traffic starts, for stores without a cron line.
 *
 * A page request (front office or back office) whose last tick is STALE_AFTER seconds old or older
 * runs the tick at its shutdown, after it has sent its response to the browser with
 * fastcgi_finish_request() or litespeed_finish_request(), with a budget of BUDGET seconds. Where
 * neither function exists, it does nothing, so a visitor never waits for the tick. The decision
 * reads the tick state that the request loaded with its configuration, so it costs no query.
 */
final class TrafficTick
{
    public const STALE_AFTER = 60;

    public const BUDGET = 10;

    private TickSettings $settings;

    /** @var callable(): Tick */
    private $tick;

    /** @var callable(): int */
    private $clock;

    /** @var (callable(): mixed)|null */
    private $finishRequest;

    /** @var callable(callable): void */
    private $onShutdown;

    private bool $scheduled = false;

    /**
     * @param callable(): Tick $tick
     * @param callable(): int $clock the current Unix time
     * @param (callable(): mixed)|null $finishRequest sends the response before the request ends;
     *                                                null where the server has no such function
     * @param callable(callable): void $onShutdown registers a function to run at shutdown
     */
    public function __construct(TickSettings $settings, callable $tick, callable $clock, ?callable $finishRequest, callable $onShutdown)
    {
        $this->settings = $settings;
        $this->tick = $tick;
        $this->clock = $clock;
        $this->finishRequest = $finishRequest;
        $this->onShutdown = $onShutdown;
    }

    /**
     * The function of this server that sends the response before the request ends, if any.
     */
    public static function finishFunction(): ?callable
    {
        foreach (['fastcgi_finish_request', 'litespeed_finish_request'] as $function) {
            if (function_exists($function)) {
                return $function;
            }
        }

        return null;
    }

    /**
     * Called by a page request: schedules the tick for after the response when it is due.
     */
    public function onRequest(): void
    {
        if ($this->scheduled || $this->finishRequest === null) {
            return;
        }
        $lastTick = $this->settings->state()->lastTickAt();
        if ($lastTick !== null && ($this->clock)() - $lastTick < self::STALE_AFTER) {
            return;
        }

        $this->scheduled = true;
        ($this->onShutdown)([$this, 'afterResponse']);
    }

    /**
     * Sends the response, then runs the tick unless another tick ran since the request started.
     */
    public function afterResponse(): void
    {
        ignore_user_abort(true);
        ($this->finishRequest)();
        try {
            ($this->tick)()->run(Tick::SOURCE_TRAFFIC, self::BUDGET, self::STALE_AFTER);
        } catch (\Throwable $error) {
            Tick::logFailure('tick', $error);
        }
    }
}
