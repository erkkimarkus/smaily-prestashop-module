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

namespace PrestaShop\Module\SmailyForPrestaShop\Privacy;

if (!defined('_PS_VERSION_')) {
    exit;
}

use PrestaShop\Module\SmailyForPrestaShop\Config\GdprPaceSettings;
use PrestaShop\Module\SmailyForPrestaShop\Engine\Connection;
use PrestaShop\Module\SmailyForPrestaShop\Engine\Exception\EngineException;
use PrestaShop\Module\SmailyForPrestaShop\Engine\Exception\EngineTransportException;
use PrestaShop\Module\SmailyForPrestaShop\Queue\Deferred;
use PrestaShop\Module\SmailyForPrestaShop\Queue\RetryPolicy;

/**
 * The pace of the privacy requests to Campaign Intelligence: the engine takes 10 of them per 60
 * seconds per engine store, one limit for the export, the deletion and the opt-out (contract
 * "Rate limiting", §8–§10).
 *
 * Every privacy request is counted when it goes, whatever comes back: the export (PrivacyRequest),
 * which goes at once, and the requests of the two queue handlers (CustomerDeleteHandler,
 * ProfilingConsentHandler). A handler sends a row only while the store has a request left in the
 * last 60 seconds, and defers the others, with no attempt spent, to the moment the oldest request
 * leaves the window. A 429 pauses the store for the wait it asked for, else 60 seconds, at most
 * the retry ladder's ceiling: the row and every later row of the store wait until then, and none
 * spends an attempt.
 *
 * Concurrency: the queue's flushes run under the tick's lock; an export may run beside one, and
 * the later of two writes then keeps its own count. One request too many meets a 429.
 *
 * Kept per engine store (tenant id), as the engine counts; a connection without one counts alone.
 */
final class GdprPace
{
    /** The requests an engine store takes per window. */
    public const LIMIT = 10;

    public const WINDOW_SECONDS = 60;

    /** The pause after a 429 that names no wait. */
    public const DEFAULT_PAUSE_SECONDS = 60;

    private GdprPaceSettings $settings;

    /** @var callable(): int */
    private $clock;

    /**
     * @param (callable(): int)|null $clock the current Unix time; time() when null
     */
    public function __construct(GdprPaceSettings $settings, ?callable $clock = null)
    {
        $this->settings = $settings;
        $this->clock = $clock ?? 'time';
    }

    /**
     * Makes the privacy request $call to the connection's engine store, counted, when the store
     * has a request left and no pause; else defers it.
     *
     * @param callable(): mixed $call the request
     *
     * @return Deferred|null null when the request was answered; a Deferred to the time the store
     *                       takes a request again when it was held back or answered 429
     *
     * @throws EngineException any other failure of the request
     */
    public function send(Connection $connection, callable $call): ?Deferred
    {
        $held = $this->heldUntil($connection);
        if ($held !== null) {
            return new Deferred($held);
        }
        $this->spend($connection);
        try {
            $call();
        } catch (EngineTransportException $failure) {
            if ($failure->httpStatus() !== 429) {
                throw $failure;
            }

            return new Deferred($this->pause($connection, $failure->retryAfter()), $failure->getMessage());
        }

        return null;
    }

    /**
     * The Unix time until which the connection's engine store takes no privacy request: the
     * moment its oldest request of the window leaves it, or its pause ends. Null when a request
     * may go now.
     */
    public function heldUntil(Connection $connection): ?int
    {
        $now = ($this->clock)();
        $store = $this->stores($now)[self::key($connection)] ?? ['calls' => [], 'until' => 0];
        $calls = $store['calls'];
        $free = max($store['until'], count($calls) < self::LIMIT ? 0 : $calls[count($calls) - self::LIMIT] + self::WINDOW_SECONDS);

        return $free > $now ? $free : null;
    }

    /**
     * Counts a privacy request to the connection's engine store, made now.
     */
    public function spend(Connection $connection): void
    {
        $now = ($this->clock)();
        $stores = $this->stores($now);
        $key = self::key($connection);
        $stores[$key] = $stores[$key] ?? ['calls' => [], 'until' => 0];
        $stores[$key]['calls'][] = $now;
        $this->settings->saveCalls($stores);
    }

    /**
     * The engine store answered 429: no privacy request goes to it for $seconds (null: the
     * default), at most the retry ladder's ceiling.
     *
     * @return int the Unix time the pause ends
     */
    private function pause(Connection $connection, ?int $seconds): int
    {
        $now = ($this->clock)();
        $ceiling = RetryPolicy::BACKOFF_SECONDS[count(RetryPolicy::BACKOFF_SECONDS) - 1];
        $until = $now + min($seconds !== null && $seconds > 0 ? $seconds : self::DEFAULT_PAUSE_SECONDS, $ceiling);
        $stores = $this->stores($now);
        $key = self::key($connection);
        $stores[$key] = ['calls' => $stores[$key]['calls'] ?? [], 'until' => max($until, $stores[$key]['until'] ?? 0)];
        $this->settings->saveCalls($stores);

        return $stores[$key]['until'];
    }

    /**
     * The stored engine stores with their requests of the last window, oldest first, and a pause
     * that has not ended; a store with neither is left out.
     *
     * @return array<string, array{calls: list<int>, until: int}>
     */
    private function stores(int $now): array
    {
        $stores = [];
        foreach ($this->settings->calls() as $key => $store) {
            $calls = is_array($store) && is_array($store['calls'] ?? null) ? $store['calls'] : [];
            $calls = array_values(array_filter(array_map('intval', $calls), static fn (int $at): bool => $at > $now - self::WINDOW_SECONDS));
            sort($calls);
            $until = is_array($store) ? (int) ($store['until'] ?? 0) : 0;
            if ($calls !== [] || $until > $now) {
                $stores[(string) $key] = ['calls' => $calls, 'until' => $until > $now ? $until : 0];
            }
        }

        return $stores;
    }

    private static function key(Connection $connection): string
    {
        return $connection->tenantId() !== '' ? $connection->tenantId() : 'connection:' . $connection->id();
    }
}
