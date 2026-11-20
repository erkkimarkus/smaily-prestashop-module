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

namespace PrestaShop\Module\SmailyForPrestaShop\Queue;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * When a failed row is tried again, as on the sibling connectors: at most 5 attempts, the next one
 * 60 s, 5 min, 15 min and 1 h after a failure (about 81 minutes in all); the fifth failure parks the
 * row as failed for a retry from the Log. The last step, 6 h, only caps a Retry-After.
 */
final class RetryPolicy
{
    public const MAX_ATTEMPTS = 5;

    public const BACKOFF_SECONDS = [60, 300, 900, 3600, 21600];

    /**
     * Whether a row whose attempt number $attempts failed is parked as failed.
     */
    public static function parks(int $attempts, Failure $failure): bool
    {
        return $failure->isPermanent() || $attempts >= self::MAX_ATTEMPTS;
    }

    /**
     * Seconds until the next attempt after attempt number $attempts failed: the delay Smaily asked
     * for, capped at the ladder's ceiling, else the ladder's step.
     */
    public static function delay(int $attempts, ?int $retryAfter): int
    {
        $ceiling = self::BACKOFF_SECONDS[count(self::BACKOFF_SECONDS) - 1];
        if ($retryAfter !== null && $retryAfter > 0) {
            return min($retryAfter, $ceiling);
        }

        return self::BACKOFF_SECONDS[max(0, min($attempts, count(self::BACKOFF_SECONDS)) - 1)];
    }
}
