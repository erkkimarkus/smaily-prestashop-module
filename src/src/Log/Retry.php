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

use PrestaShop\Module\SmailyForPrestaShop\Engine\Ingest\IngestQueue;
use PrestaShop\Module\SmailyForPrestaShop\Queue\EventQueue;

/**
 * The Log's mass "Retry": returns the failed rows of a selection to `pending`, in place, with
 * their attempts to spend anew (EventQueue::retry(), IngestQueue::retry()), as the Magento
 * connector's does for both of its queues. The selection is the ticked rows, or every row that
 * the list's filter selects. Rows that are not failed and import jobs are no part of it.
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

    private ?IngestQueue $ingest;

    /**
     * @param IngestQueue|null $ingest the Campaign Intelligence ingest queue, whose failed rows
     *                                 the retry covers too
     */
    public function __construct(LogRows $rows, ResendGuard $guard, EventQueue $queue, ?IngestQueue $ingest = null)
    {
        $this->rows = $rows;
        $this->guard = $guard;
        $this->queue = $queue;
        $this->ingest = $ingest;
    }

    /**
     * @param list<int>|null $ids the ticked event-queue rows; null takes every row of the filter,
     *                            of both queues
     * @param list<int> $ingestIds the ticked ingest-queue rows
     *
     * @return array{0: int, 1: int} the rows queued for retry, and the rows the guard refused
     */
    public function retry(LogFilter $filter, ?array $ids, array $ingestIds = []): array
    {
        $events = $this->drain(
            fn (int $after): array => $this->rows->failedEventIds($filter, $ids, $after, self::BATCH_SIZE),
            fn (array $batch): array => $this->guard->refusals($this->rows->eventRows($batch)),
            fn (array $batch): int => $this->queue->retry($batch)
        );
        $ingest = $this->ingest;
        if ($ingest === null) {
            return $events;
        }
        $rows = $this->drain(
            fn (int $after): array => $this->rows->failedIngestIds($filter, $ids === null ? null : $ingestIds, $after, self::BATCH_SIZE),
            fn (array $batch): array => $this->guard->ingestRefusals($this->rows->ingestRows($batch)),
            static fn (array $batch): int => $ingest->retry($batch)
        );

        return [$events[0] + $rows[0], $events[1] + $rows[1]];
    }

    /**
     * Retries the failed rows of one queue, batch after batch by ascending id.
     *
     * @param callable(int): list<int> $next the ids of the next batch above an id
     * @param callable(list<int>): array<int, string> $refusals the guard's refusals of a batch, by id
     * @param callable(list<int>): int $retry returns rows to pending and answers how many
     *
     * @return array{0: int, 1: int} the rows queued for retry, and the rows the guard refused
     */
    private function drain(callable $next, callable $refusals, callable $retry): array
    {
        $retried = 0;
        $skipped = 0;
        $after = 0;
        do {
            $batch = $next($after);
            if ($batch === []) {
                break;
            }
            $refused = $refusals($batch);
            $retried += $retry(array_values(array_diff($batch, array_keys($refused))));
            $skipped += count($refused);
            $after = max($batch);
        } while (count($batch) === self::BATCH_SIZE);

        return [$retried, $skipped];
    }
}
