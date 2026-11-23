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
use PrestaShop\Module\SmailyForPrestaShop\Engine\Client;
use PrestaShop\Module\SmailyForPrestaShop\Engine\Connection;
use PrestaShop\Module\SmailyForPrestaShop\Engine\Exception\EngineException;
use PrestaShop\Module\SmailyForPrestaShop\Engine\Ingest\IngestQueue;

/**
 * Tells the engine where a connection's history import stands (contract §25,
 * `POST /api/v1/ingest/history/progress`), as the Magento connector's HistoryProgress does. The
 * history import is the connection's newest orders import; the catalog and customers counts are
 * those of its newest catalog and customers imports. Counts and dates only.
 *
 * The phase counts what the engine took, never what is only queued: `started` once the import
 * began; `start_part_complete` once the orders of the start part are delivered and neither the
 * catalog nor the customers import is still to run; `complete` once the orders import is done.
 * A phase change is reported at the next run of the import job, and the counts at most every
 * REPORT_EVERY_SECONDS while the import runs. A call that fails is tried again after
 * REPORT_EVERY_SECONDS; it never stops the import. Nothing is reported after `complete`.
 *
 * An import that stopped before `complete` gets one last call with the phase it reached, its
 * counts and `stop_reason` (`cancelled`, `failed` or `stalled`), and nothing after it. An import
 * that ended because the connection is gone or refused, and one the engine never heard of, is
 * not reported. What was reported is kept in the orders import's state.
 */
final class HistoryProgress
{
    public const STARTED = 'started';

    public const START_PART_COMPLETE = 'start_part_complete';

    public const COMPLETE = 'complete';

    public const REPORT_EVERY_SECONDS = 300;

    private HistoryJobs $jobs;

    private IngestQueue $queue;

    private Client $client;

    /** @var callable(): int */
    private $now;

    /**
     * @param callable(): int $now the current Unix time
     */
    public function __construct(HistoryJobs $jobs, IngestQueue $queue, Client $client, callable $now)
    {
        $this->jobs = $jobs;
        $this->queue = $queue;
        $this->client = $client;
        $this->now = $now;
    }

    public function report(Connection $connection): void
    {
        $orders = $this->jobs->latest($connection->id(), HistoryJobs::ORDERS);
        if ($orders === null || (string) $orders['import_id'] === '') {
            return;
        }
        $state = $orders['state'];
        $reported = $state['reported_phase'] ?? null;
        if ($reported === self::COMPLETE || !empty($state['stop_reported'])) {
            return;
        }

        $now = ($this->now)();
        $stop = self::stopReason($orders);
        if ($stop === '' || ($stop !== null && $reported === null)) {
            $this->jobs->note($orders, ['stop_reported' => true] + $state);

            return;
        }
        $phase = $this->phase($connection, $orders);
        $state['start_done'] = $phase !== self::STARTED;
        if ($now - (int) ($state['reported_at'] ?? 0) < self::REPORT_EVERY_SECONDS
            && (isset($state['report_failed']) || ($stop === null && $phase === $reported))
        ) {
            return;
        }

        try {
            $this->client->historyProgress($connection, $this->body($connection, $orders, $phase, $stop));
        } catch (EngineException $error) {
            $this->jobs->note($orders, ['reported_at' => $now, 'report_failed' => true] + $state);

            return;
        }
        unset($state['report_failed']);
        $this->jobs->note($orders, ['reported_phase' => $phase, 'reported_at' => $now, 'stop_reported' => $stop !== null] + $state);
    }

    /**
     * The phase that the engine's rows have reached.
     *
     * @param array<string, mixed> $orders the orders import
     */
    private function phase(Connection $connection, array $orders): string
    {
        if ($orders['status'] === JobStore::STATUS_DONE) {
            return self::COMPLETE;
        }
        $state = $orders['state'];
        if (!empty($state['start_done'])) {
            return self::START_PART_COMPLETE;
        }
        if (!isset($state['start_last_row'])) {
            return self::STARTED;
        }
        foreach ([HistoryJobs::CATALOG, HistoryJobs::CUSTOMERS] as $entity) {
            $job = $this->jobs->latest($connection->id(), $entity);
            if ($job !== null && in_array($job['status'], JobStore::ACTIVE, true)) {
                return self::STARTED;
            }
        }
        $waiting = (int) $state['start_last_row'] === 0 ? [] : $this->queue->countMarked(
            $connection->id(),
            HistoryJobs::ORDERS,
            [HistoryImport::MARKER => (int) $orders['id']],
            (int) $state['first_row'],
            (int) $state['start_last_row']
        );

        return isset($waiting['pending']) || isset($waiting['processing']) ? self::STARTED : self::START_PART_COMPLETE;
    }

    /**
     * Why an import stopped before `complete`: a §25 `stop_reason`, '' for a stop that is not
     * reported, null for an import that did not stop.
     *
     * @param array<string, mixed> $orders
     */
    private static function stopReason(array $orders): ?string
    {
        if ($orders['status'] === JobStore::STATUS_CANCELLED) {
            return $orders['last_error'] === null ? 'cancelled' : '';
        }
        if ($orders['status'] === JobStore::STATUS_FAILED) {
            return $orders['last_error'] === HistoryJobs::REASON_STALLED ? 'stalled' : 'failed';
        }

        return null;
    }

    /**
     * @param array<string, mixed> $orders
     *
     * @return array<string, mixed>
     */
    private function body(Connection $connection, array $orders, string $phase, ?string $stop): array
    {
        $state = $orders['state'];
        $entities = [];
        foreach ([HistoryJobs::CATALOG, HistoryJobs::CUSTOMERS] as $entity) {
            $job = $this->jobs->latest($connection->id(), $entity);
            $entities[$entity] = $job === null || $job['status'] === JobStore::STATUS_QUEUED
                ? ['sent' => 0]
                : ['expected' => (int) ($job['state']['total'] ?? 0), 'sent' => (int) ($job['state']['sent'] ?? 0)];
        }
        $entities[HistoryJobs::ORDERS] = ['sent' => (int) ($state['sent'] ?? 0)];
        if ($orders['status'] !== JobStore::STATUS_QUEUED) {
            $entities[HistoryJobs::ORDERS]['expected'] = (int) ($state['total'] ?? 0);
        }

        $body = [
            'import_id' => (string) $orders['import_id'],
            'phase' => $phase,
            'horizon_start' => substr((string) $state['horizon'], 0, 10),
            'entities' => $entities,
        ];
        if ($stop !== null) {
            $body['stop_reason'] = $stop;
        }

        return $body;
    }
}
