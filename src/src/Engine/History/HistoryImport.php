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

namespace PrestaShop\Module\SmailyForPrestaShop\Engine\History;

if (!defined('_PS_VERSION_')) {
    exit;
}

use PrestaShop\Module\SmailyForPrestaShop\Contacts\Import\JobStore;
use PrestaShop\Module\SmailyForPrestaShop\Database\Sql;
use PrestaShop\Module\SmailyForPrestaShop\Engine\Connection;
use PrestaShop\Module\SmailyForPrestaShop\Engine\Connections;
use PrestaShop\Module\SmailyForPrestaShop\Engine\Ingest\ConnectionShops;
use PrestaShop\Module\SmailyForPrestaShop\Engine\Ingest\CustomerScope;
use PrestaShop\Module\SmailyForPrestaShop\Engine\Ingest\IngestQueue;
use PrestaShop\Module\SmailyForPrestaShop\Queue\EventQueue;
use PrestaShop\Module\SmailyForPrestaShop\Queue\Failure;
use PrestaShop\Module\SmailyForPrestaShop\Runtime\Log;
use PrestaShop\Module\SmailyForPrestaShop\Scheduler\Deadline;

/**
 * The first data load of a connection (contract §25 "Sender rules"), as the tick job
 * `engine_import`: the whole catalog, every customer in scope, then the orders newest first, the
 * last `start_months` months and then back to `horizon_months`; no older order is read. Each is a
 * job row (HistoryJobs), and each connection's imports run one after another, a connection's own
 * beside another connection's.
 *
 * An import writes markers into the ingest queue, as the live capture does: a marker names a
 * product, a customer or an order, and the ingest flush builds and sends the row from the store
 * as it is then, with the payload builders, the outcomes and the Log of every other row
 * (ARCHITECTURE 8.11). A marker of an import carries the job's id in its payload, by which the
 * import counts what the engine took. No more than BACKLOG markers of an import wait at a time.
 *
 * An import is done once every product, customer or order is queued and none of its markers
 * waits. Only orders placed before the import began are read; a later one goes by the live
 * capture. An import whose connection is gone, or refused by the engine, is cancelled; one that
 * throws fails with the place of the error, never its message; one that nothing has moved for
 * an hour is ended as stalled.
 */
final class HistoryImport
{
    /** Products, customers or orders queued per page. */
    public const PAGE = 200;

    /** The markers of one import that may wait in the ingest queue. */
    public const BACKLOG = 1000;

    /** The payload key under which a marker names its import job. */
    public const MARKER = 'import';

    public const PHASE_START = 'start';

    public const PHASE_OLDER = 'older';

    private const DEFAULT_START_MONTHS = 18;

    private HistoryJobs $jobs;

    private Connections $connections;

    private IngestQueue $queue;

    private CustomerScope $scope;

    private HistoryProgress $progress;

    /** @var callable(): int */
    private $now;

    /**
     * @param callable(): int $now the current Unix time
     */
    public function __construct(HistoryJobs $jobs, Connections $connections, IngestQueue $queue, CustomerScope $scope, HistoryProgress $progress, callable $now)
    {
        $this->jobs = $jobs;
        $this->connections = $connections;
        $this->queue = $queue;
        $this->scope = $scope;
        $this->progress = $progress;
        $this->now = $now;
    }

    public function run(Deadline $deadline): void
    {
        $connections = [];
        foreach ($this->connections->all() as $connection) {
            $connections[$connection->id()] = $connection;
        }

        $working = [];
        foreach ($this->jobs->active() as $job) {
            $connection = $connections[(int) $job['id_connection']] ?? null;
            if ($connection === null || !$connection->isTaken()) {
                $this->jobs->end($job, JobStore::STATUS_CANCELLED, $connection === null ? HistoryJobs::REASON_NO_CONNECTION : HistoryJobs::REASON_NOT_ACTIVE);
                continue;
            }
            try {
                if (HistoryJobs::entityOf($job) === HistoryJobs::ORDERS && (string) $job['import_id'] === '') {
                    // The history import begins before the imports ahead of it send a row, so the
                    // engine hears `started` first (§25 sender rules 2 and 3).
                    $job = $this->begin($job, $connection);
                }
                if (!isset($working[$connection->id()])) {
                    $working[$connection->id()] = true;
                    $this->advance($job, $connection, $deadline);
                }
            } catch (\Throwable $error) {
                Log::failure('the Campaign Intelligence import', $error);
                $this->jobs->end($job, JobStore::STATUS_FAILED, Failure::of($error)->reason());
            }
        }

        foreach ($connections as $connection) {
            $this->progress->report($connection);
        }
    }

    /**
     * Fixes the window of a history import and names it.
     *
     * @param array<string, mixed> $job the orders import
     *
     * @return array<string, mixed> the job with its window
     */
    private function begin(array $job, Connection $connection): array
    {
        $now = ($this->now)();
        $startMonths = $connection->historyMonths('start_months', self::DEFAULT_START_MONTHS);
        $job['state'] = [
            'until' => date('Y-m-d H:i:s', $now),
            'start_from' => date('Y-m-d H:i:s', (int) strtotime('-' . $startMonths . ' months', $now)),
            'horizon' => $this->scope->horizon($connection),
            'start_months' => $startMonths,
            'horizon_months' => $connection->historyMonths('horizon_months', CustomerScope::DEFAULT_HORIZON_MONTHS),
            'phase' => self::PHASE_START,
        ] + $job['state'];
        $job['import_id'] = sprintf('presta-%s-%d', gmdate('Y-m-d', $now), (int) $job['id']);
        $this->jobs->move($job, $job['state'], ['import_id' => $job['import_id']]);

        return $job;
    }

    /**
     * Queues pages of an import until its backlog is full or the deadline passes, counts what
     * the engine took, and ends the import when nothing is left.
     *
     * @param array<string, mixed> $job
     */
    private function advance(array $job, Connection $connection, Deadline $deadline): void
    {
        $entity = HistoryJobs::entityOf($job);
        $state = $job['state'];
        $moved = false;
        if ($job['status'] === JobStore::STATUS_QUEUED) {
            // Taken up before the count, so an import that dies on its count is running, not new.
            $this->jobs->move($job, $state, ['status' => JobStore::STATUS_RUNNING, 'started_at' => gmdate('Y-m-d H:i:s', ($this->now)())]);
            $state['total'] = $this->total($entity, $connection, $state);
            $this->jobs->move($job, $state);
            $moved = true;
        }

        $cursor = (string) $job['cursor'];
        while (empty($state['queued_all']) && !$deadline->passed()) {
            if ($this->waiting($job, $state) >= self::BACKLOG) {
                break;
            }
            $current = $this->jobs->find((int) $job['id']);
            if ($current === null || !in_array($current['status'], JobStore::ACTIVE, true)) {
                return;
            }
            $page = $this->page($entity, $connection, $state, $cursor);
            if ($page === []) {
                if ($entity === HistoryJobs::ORDERS && $state['phase'] === self::PHASE_START) {
                    $state['phase'] = self::PHASE_OLDER;
                    $state['start_last_row'] = (int) ($state['last_row'] ?? 0);
                } else {
                    $state['queued_all'] = true;
                }
            }
            foreach ($page as [$id, $cursor]) {
                $row = $this->queue->enqueue($connection->id(), $entity, (string) $id, [self::MARKER => (int) $job['id']]);
                $state['first_row'] = $state['first_row'] ?? $row;
                $state['last_row'] = $row;
                ++$state['queued'];
            }
            $this->jobs->move($job, $state, ['cursor' => $cursor]);
            $moved = true;
        }

        $tally = $this->tally($job, $state);
        $counts = ['sent' => $tally['sent'], 'failed' => $tally['failed'], 'skipped' => $tally['skipped']];
        if ($counts != array_intersect_key($state, $counts)) {
            $state = $counts + $state;
            $this->jobs->move($job, $state);
            $moved = true;
        }
        if (!empty($state['queued_all']) && $tally['waiting'] === 0) {
            $this->jobs->end($job, JobStore::STATUS_DONE, null);
        } elseif (!$moved && $this->jobs->isStalled($job)) {
            $this->jobs->end($job, JobStore::STATUS_FAILED, HistoryJobs::REASON_STALLED);
        }
    }

    /**
     * How the markers of an import stand: `sent`, `skipped`, `failed` (refused for good) and
     * `waiting` (still to go, or being sent).
     *
     * @param array<string, mixed> $job
     * @param array<string, mixed> $state
     *
     * @return array{sent: int, failed: int, skipped: int, waiting: int}
     */
    private function tally(array $job, array $state): array
    {
        $counts = isset($state['first_row']) ? $this->queue->countMarked(
            (int) $job['id_connection'],
            HistoryJobs::entityOf($job),
            [self::MARKER => (int) $job['id']],
            (int) $state['first_row'],
            (int) $state['last_row']
        ) : [];

        return [
            'sent' => $counts[EventQueue::STATUS_SENT] ?? 0,
            'failed' => $counts[EventQueue::STATUS_FAILED] ?? 0,
            'skipped' => $counts[EventQueue::STATUS_SKIPPED] ?? 0,
            'waiting' => ($counts[EventQueue::STATUS_PENDING] ?? 0) + ($counts[EventQueue::STATUS_PROCESSING] ?? 0),
        ];
    }

    /**
     * The `waiting` of tally() alone, asked before every page: it reads the waiting markers, not
     * every marker that the import wrote so far.
     *
     * @param array<string, mixed> $job
     * @param array<string, mixed> $state
     */
    private function waiting(array $job, array $state): int
    {
        return isset($state['first_row']) ? $this->queue->countWaitingMarked(
            (int) $job['id_connection'],
            HistoryJobs::entityOf($job),
            [self::MARKER => (int) $job['id']],
            (int) $state['first_row'],
            (int) $state['last_row']
        ) : 0;
    }

    /**
     * The number of products, customers or orders in the import's scope.
     *
     * @param array<string, mixed> $state
     */
    private function total(string $entity, Connection $connection, array $state): int
    {
        switch ($entity) {
            case HistoryJobs::CATALOG:
                return (int) Sql::value('SELECT COUNT(DISTINCT `id_product`) FROM ' . ConnectionShops::products($connection));
            case HistoryJobs::CUSTOMERS:
                return $this->scope->count($connection);
            default:
                return (int) Sql::value(
                    'SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'orders` WHERE `id_shop` IN (' . implode(', ', ConnectionShops::of($connection)) . ')'
                    . ' AND `date_add` >= \'' . pSQL($state['horizon']) . '\' AND `date_add` < \'' . pSQL($state['until']) . '\''
                );
        }
    }

    /**
     * The next page after the cursor: each entry is an id and the cursor that stands after it.
     * Products and customers go by ascending id; orders newest first, within the phase's part of
     * the window.
     *
     * @param array<string, mixed> $state
     *
     * @return list<array{int, string}>
     */
    private function page(string $entity, Connection $connection, array $state, string $cursor): array
    {
        if ($entity === HistoryJobs::CUSTOMERS) {
            return array_map(static fn (int $id): array => [$id, (string) $id], $this->scope->page($connection, (int) $cursor, self::PAGE));
        }
        if ($entity === HistoryJobs::CATALOG) {
            return array_map(static fn (int $id): array => [$id, (string) $id], ConnectionShops::productIds($connection, (int) $cursor, self::PAGE));
        }
        $shops = implode(', ', ConnectionShops::of($connection));

        [$from, $before] = $state['phase'] === self::PHASE_START ? [$state['start_from'], $state['until']] : [$state['horizon'], $state['start_from']];
        $after = '';
        if (strpos($cursor, '|') !== false) {
            [$date, $id] = explode('|', $cursor);
            $after = sprintf(' AND (`date_add` < \'%1$s\' OR (`date_add` = \'%1$s\' AND `id_order` < %2$d))', pSQL($date), (int) $id);
        }
        $rows = Sql::rows(
            'SELECT `id_order`, `date_add` FROM `' . _DB_PREFIX_ . 'orders` WHERE `id_shop` IN (' . $shops . ')'
            . ' AND `date_add` >= \'' . pSQL($from) . '\' AND `date_add` < \'' . pSQL($before) . '\'' . $after
            . ' ORDER BY `date_add` DESC, `id_order` DESC LIMIT ' . self::PAGE
        );

        return array_map(static fn (array $row): array => [(int) $row['id_order'], $row['date_add'] . '|' . $row['id_order']], $rows);
    }
}
