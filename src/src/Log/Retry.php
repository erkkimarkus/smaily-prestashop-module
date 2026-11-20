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

use PrestaShop\Module\SmailyForPrestaShop\Queue\EventQueue;

/**
 * The Log's mass "Retry": returns the failed event-queue rows of a selection to `pending`, in
 * place, with their attempts to spend anew (EventQueue::retry()). The selection is the ticked rows,
 * or every row that the list's filter selects. Rows that are not failed, ingest rows and import
 * jobs are no part of it.
 *
 * Every row passes ResendGuard: a row it refuses stays as it is and is counted as skipped. The
 * selection is read by primary key in batches, so no statement names more than BATCH_SIZE rows, and
 * a retried row (now pending) is never read twice.
 */
final class Retry
{
    public const BATCH_SIZE = 1000;

    private LogRows $rows;

    private ResendGuard $guard;

    private EventQueue $queue;

    public function __construct(LogRows $rows, ResendGuard $guard, EventQueue $queue)
    {
        $this->rows = $rows;
        $this->guard = $guard;
        $this->queue = $queue;
    }

    /**
     * @param list<int>|null $ids the ticked event-queue rows; null takes every row of the filter
     *
     * @return array{0: int, 1: int} the rows queued for retry, and the rows the guard refused
     */
    public function retry(LogFilter $filter, ?array $ids): array
    {
        $retried = 0;
        $skipped = 0;
        $after = 0;
        do {
            $batch = $this->rows->failedEventIds($filter, $ids, $after, self::BATCH_SIZE);
            if ($batch === []) {
                break;
            }
            $refused = $this->guard->refusals($this->rows->eventRows($batch));
            $retried += $this->queue->retry(array_values(array_diff($batch, array_keys($refused))));
            $skipped += count($refused);
            $after = max($batch);
        } while (count($batch) === self::BATCH_SIZE);

        return [$retried, $skipped];
    }
}
