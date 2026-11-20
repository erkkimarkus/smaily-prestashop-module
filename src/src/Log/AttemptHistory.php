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

namespace PrestaShop\Module\SmailyForPrestaShop\Log;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * A queue row's attempts in order, for Details, as the Magento connector lists them. It is built
 * from what the row stores: the number of failed attempts, its times and its latest outcome. An
 * earlier failure therefore has no time, and the last error belongs to the latest failure, while
 * that failure is the row's latest change. A delivery does not raise the stored count, so it is the
 * attempt after the failures.
 */
final class AttemptHistory
{
    public const QUEUED = 'queued';

    public const FAILED = 'failed';

    public const DELIVERED = 'delivered';

    public const SKIPPED = 'skipped';

    public const WITHDRAWN = 'withdrawn';

    public const SCHEDULED = 'scheduled';

    public const WAITING = 'waiting';

    public const IN_PROGRESS = 'in_progress';

    /**
     * @param string $status the row's shown status (LogStatus)
     * @param string|null $nextRetryAt the stored times, as stored
     *
     * @return list<array{attempt: int|null, result: string, at: string|null, latest_error: bool}>
     */
    public static function entries(string $status, int $failures, string $createdAt, string $updatedAt, ?string $nextRetryAt): array
    {
        // The latest failure is the row's latest change only while the row is parked by it or
        // waits for the retry after it.
        $failureIsLatest = $status === LogStatus::FAILED || ($status === LogStatus::PENDING && $nextRetryAt !== null);
        $entries = [self::entry(null, self::QUEUED, $createdAt)];
        for ($attempt = 1; $attempt <= $failures; ++$attempt) {
            $latest = $failureIsLatest && $attempt === $failures;
            $entries[] = self::entry($attempt, self::FAILED, $latest ? $updatedAt : null, $latest);
        }
        $next = $failures + 1;
        switch ($status) {
            case LogStatus::SENT:
                $entries[] = self::entry($next, self::DELIVERED, $updatedAt);
                break;
            case LogStatus::SENDING:
                $entries[] = self::entry($next, self::IN_PROGRESS, $updatedAt);
                break;
            case LogStatus::PENDING:
                $entries[] = $nextRetryAt !== null
                    ? self::entry($next, self::SCHEDULED, $nextRetryAt)
                    : self::entry($next, self::WAITING, null);
                break;
            case LogStatus::SKIPPED:
                $entries[] = self::entry(null, self::SKIPPED, $updatedAt);
                break;
            case LogStatus::WITHDRAWN:
                $entries[] = self::entry(null, self::WITHDRAWN, $updatedAt);
                break;
        }

        return $entries;
    }

    /**
     * @return array{attempt: int|null, result: string, at: string|null, latest_error: bool}
     */
    private static function entry(?int $attempt, string $result, ?string $at, bool $latestError = false): array
    {
        return ['attempt' => $attempt, 'result' => $result, 'at' => $at, 'latest_error' => $latestError];
    }
}
