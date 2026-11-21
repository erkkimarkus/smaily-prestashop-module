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

use PrestaShop\Module\SmailyForPrestaShop\Contacts\Import\JobStore;
use PrestaShop\Module\SmailyForPrestaShop\Database\Sql;
use PrestaShop\Module\SmailyForPrestaShop\Queue\EventQueue;

/**
 * The Log's reads: one list over the event queue (source Smaily), the ingest queue (source Campaign
 * Intelligence) and the import jobs, newest first by their creation. The Log has no table of its
 * own (ARCHITECTURE 2.2). Read-only SQL.
 *
 * A row's log id is `smaily-<id>`, `intelligence-<id>` or `job-<id>`. A job belongs to Campaign
 * Intelligence when it names a connection, else to Smaily; its creation time is the time it
 * started, or its last change while it has not started.
 *
 * Each table is filtered by its own columns and read newest first through its own index, only as
 * far as the page reaches; the pages of the three are then merged. The shops limit every read: a
 * queue row by its shop, an ingest row by its connection's shop, a job by its shop, where shop 0
 * (every shop) is in every selection.
 */
final class LogRows
{
    public const SMAILY = 'smaily';

    public const INTELLIGENCE = 'intelligence';

    public const JOB = 'job';

    private const CONNECTION_TABLE = 'smaily_connect_connection';

    /** The table, the type column and the entity column of each queue. */
    private const QUEUES = [
        self::SMAILY => [EventQueue::TABLE, 'event_type', 'entity_id'],
        self::INTELLIGENCE => ['smaily_connect_ingest_queue', 'domain', 'entity_key'],
    ];

    private const JOB_STATUSES = [
        JobStore::STATUS_QUEUED,
        JobStore::STATUS_RUNNING,
        JobStore::STATUS_DONE,
        JobStore::STATUS_FAILED,
        JobStore::STATUS_CANCELLED,
    ];

    /** @var list<int>|null */
    private ?array $shops;

    /** @var callable(string): list<string> */
    private $codesShowing;

    /**
     * @param list<int>|null $shops the shops whose rows are read; null reads every shop
     * @param callable(string): list<string> $codesShowing the reason codes that the list shows with
     *                                                     a term in their wording
     *                                                     (FailureMessage::codesShowing())
     */
    public function __construct(?array $shops, callable $codesShowing)
    {
        $this->shops = $shops === null ? null : array_map('intval', $shops);
        $this->codesShowing = $codesShowing;
    }

    /**
     * @return list<array<string, string|null>> rows with `source`, `kind` (a queue's source, or
     *                                          `job`), `id`, `type`, `entity`, `status` (as
     *                                          stored), `attempts`, `last_error`, `created_at`,
     *                                          `updated_at` and `counts`
     */
    public function page(LogFilter $filter, int $offset, int $limit): array
    {
        $selects = [];
        foreach ($this->selects($filter) as $select) {
            $selects[] = $select . ' ORDER BY `created_at` DESC, `id` DESC LIMIT ' . ($offset + $limit);
        }
        if ($selects === []) {
            return [];
        }
        // A derived table, so that the statement has one form on every MySQL and MariaDB version.
        $union = count($selects) === 1 ? $selects[0] : '(' . implode(') UNION ALL (', $selects) . ')';
        $rows = Sql::rows(
            'SELECT * FROM (' . $union . ') AS `log` ORDER BY `created_at` DESC, `kind`, `id` DESC LIMIT ' . $offset . ', ' . $limit
        );

        return $rows;
    }

    public function count(LogFilter $filter): int
    {
        $counts = [];
        foreach ([self::SMAILY, self::INTELLIGENCE, self::JOB] as $kind) {
            $condition = $this->condition($kind, $filter);
            if ($condition !== null) {
                $counts[] = '(SELECT COUNT(*) FROM ' . self::table(self::tableOf($kind)) . ' WHERE ' . $condition . ')';
            }
        }

        return $counts === [] ? 0 : Sql::count('SELECT ' . implode(' + ', $counts));
    }

    /**
     * The queue rows that were parked as failed since $since (a Unix time).
     */
    public function failedSince(int $since): int
    {
        $counts = [];
        foreach (array_keys(self::QUEUES) as $kind) {
            $counts[] = sprintf(
                '(SELECT COUNT(*) FROM %s WHERE `status` = \'%s\' AND `updated_at` >= \'%s\'%s)',
                self::table(self::tableOf($kind)),
                EventQueue::STATUS_FAILED,
                gmdate('Y-m-d H:i:s', $since),
                $this->scope($kind) === null ? '' : ' AND ' . $this->scope($kind)
            );
        }

        return Sql::count('SELECT ' . implode(' + ', $counts));
    }

    /**
     * The row of a log id as stored, with `kind`, `source`, `type`, `entity` and `created_at` as
     * page() gives them; null for an id of another form, a row that is gone, or a row of a shop
     * outside the selection.
     *
     * @return array<string, mixed>|null
     */
    public function find(string $logId): ?array
    {
        if (preg_match('/^(' . self::SMAILY . '|' . self::INTELLIGENCE . '|' . self::JOB . ')-([1-9][0-9]{0,18})$/', $logId, $matches) !== 1) {
            return null;
        }
        $kind = $matches[1];
        $scope = $this->scope($kind);
        $row = Sql::row(
            'SELECT * FROM ' . self::table(self::tableOf($kind)) . ' WHERE `id` = ' . $matches[2] . ($scope === null ? '' : ' AND ' . $scope)
        );
        if ($row === null) {
            return null;
        }
        if ($kind === self::JOB) {
            return [
                'kind' => $kind,
                'source' => $row['id_connection'] === null ? self::SMAILY : self::INTELLIGENCE,
                'entity' => null,
                'attempts' => null,
                'created_at' => $row['started_at'] ?? $row['updated_at'],
            ] + $row;
        }

        return ['kind' => $kind, 'source' => $kind, 'type' => $row[self::QUEUES[$kind][1]], 'entity' => $row[self::QUEUES[$kind][2]]] + $row;
    }

    /**
     * The ids of the event-queue rows that are parked as failed among those the filter selects (and,
     * with $ids, among those rows only), above the id $after, lowest first.
     *
     * @param list<int>|null $ids
     *
     * @return list<int>
     */
    public function failedEventIds(LogFilter $filter, ?array $ids, int $after, int $limit): array
    {
        $condition = $this->condition(self::SMAILY, $filter);
        if ($condition === null || $ids === []) {
            return [];
        }
        $rows = Sql::rows(
            'SELECT `id` FROM ' . self::table(EventQueue::TABLE) . ' WHERE ' . $condition
            . ' AND `status` = \'' . EventQueue::STATUS_FAILED . '\' AND `id` > ' . $after
            . ($ids === null ? '' : ' AND `id` IN (' . implode(', ', array_map('intval', $ids)) . ')')
            . ' ORDER BY `id` LIMIT ' . $limit
        );

        return array_map(static fn (array $row): int => (int) $row['id'], $rows);
    }

    /**
     * The columns of event-queue rows that the resend guard reads, by row id.
     *
     * @param list<int> $ids
     *
     * @return array<int, array<string, string|null>>
     */
    public function eventRows(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $rows = Sql::rows(
            'SELECT `id`, `id_shop`, `event_type`, `entity_id`, `status`, `last_error` FROM ' . self::table(EventQueue::TABLE)
            . ' WHERE `id` IN (' . implode(', ', array_map('intval', $ids)) . ')'
        );

        return array_column($rows, null, 'id');
    }

    /**
     * failedEventIds() for the Campaign Intelligence ingest queue.
     *
     * @param list<int>|null $ids
     *
     * @return list<int>
     */
    public function failedIngestIds(LogFilter $filter, ?array $ids, int $after, int $limit): array
    {
        $condition = $this->condition(self::INTELLIGENCE, $filter);
        if ($condition === null || $ids === []) {
            return [];
        }
        $rows = Sql::rows(
            'SELECT `id` FROM ' . self::table(self::QUEUES[self::INTELLIGENCE][0]) . ' WHERE ' . $condition
            . ' AND `status` = \'' . EventQueue::STATUS_FAILED . '\' AND `id` > ' . $after
            . ($ids === null ? '' : ' AND `id` IN (' . implode(', ', array_map('intval', $ids)) . ')')
            . ' ORDER BY `id` LIMIT ' . $limit
        );

        return array_map(static fn (array $row): int => (int) $row['id'], $rows);
    }

    /**
     * The columns of ingest-queue rows that the resend guard reads, by row id.
     *
     * @param list<int> $ids
     *
     * @return array<int, array<string, string|null>>
     */
    public function ingestRows(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $rows = Sql::rows(
            'SELECT `id`, `domain`, `entity_key`, `status` FROM ' . self::table(self::QUEUES[self::INTELLIGENCE][0])
            . ' WHERE `id` IN (' . implode(', ', array_map('intval', $ids)) . ')'
        );

        return array_column($rows, null, 'id');
    }

    /**
     * One SELECT per table that the filter can match, in the columns of page().
     *
     * @return list<string>
     */
    private function selects(LogFilter $filter): array
    {
        $selects = [];
        foreach (self::QUEUES as $kind => [$table, $type, $entity]) {
            $condition = $this->condition($kind, $filter);
            if ($condition !== null) {
                $selects[] = sprintf(
                    'SELECT \'%1$s\' AS `source`, \'%1$s\' AS `kind`, `id`, `%2$s` AS `type`, `%3$s` AS `entity`, `status`, `attempts`,'
                    . ' `last_error`, `created_at`, `updated_at`, NULL AS `counts` FROM %4$s WHERE %5$s',
                    $kind,
                    $type,
                    $entity,
                    self::table($table),
                    $condition
                );
            }
        }
        $condition = $this->condition(self::JOB, $filter);
        if ($condition !== null) {
            $selects[] = sprintf(
                'SELECT IF(`id_connection` IS NULL, \'%s\', \'%s\') AS `source`, \'%s\' AS `kind`, `id`, `type`, NULL AS `entity`, `status`,'
                . ' NULL AS `attempts`, `last_error`, COALESCE(`started_at`, `updated_at`) AS `created_at`, `updated_at`, `counts`'
                . ' FROM %s WHERE %s',
                self::SMAILY,
                self::INTELLIGENCE,
                self::JOB,
                self::table(JobStore::TABLE),
                $condition
            );
        }

        return $selects;
    }

    /**
     * The WHERE condition of one table for the filter and the shops, or null when no row of the
     * table can match.
     */
    private function condition(string $kind, LogFilter $filter): ?string
    {
        $where = [];
        $source = $filter->get(LogFilter::SOURCE);
        $status = $filter->get(LogFilter::STATUS);
        $entity = $filter->get(LogFilter::ENTITY);
        if ($kind === self::JOB) {
            if ($entity !== '' || ($status !== '' && !in_array($status, self::JOB_STATUSES, true))) {
                return null;
            }
            if ($source !== '') {
                $where[] = '`id_connection` IS ' . ($source === self::SMAILY ? '' : 'NOT ') . 'NULL';
            }
            if ($status !== '') {
                $where[] = '`status` = \'' . $status . '\'';
            }
            $type = '`type`';
            $created = 'COALESCE(`started_at`, `updated_at`)';
        } else {
            if ($source !== '' && $source !== $kind) {
                return null;
            }
            $type = '`' . self::QUEUES[$kind][1] . '`';
            if ($status !== '') {
                $shown = LogStatus::queueCondition($status, $type, '`last_error`');
                if ($shown === null) {
                    return null;
                }
                $where[] = $shown;
            }
            if ($entity !== '') {
                $where[] = '`' . self::QUEUES[$kind][2] . '` LIKE ' . self::like($entity);
            }
            $created = '`created_at`';
        }
        if ($filter->get(LogFilter::TYPE) !== '') {
            $where[] = $type . ' LIKE ' . self::like($filter->get(LogFilter::TYPE));
        }
        $error = $filter->get(LogFilter::ERROR);
        if ($error !== '') {
            $codes = array_map(static fn (string $code): string => '\'' . pSQL($code) . '\'', ($this->codesShowing)($error));
            $where[] = '(`last_error` LIKE ' . self::like($error) . ($codes === [] ? '' : ' OR `last_error` IN (' . implode(', ', $codes) . ')') . ')';
        }
        foreach (['created' => $created, 'updated' => '`updated_at`'] as $name => $column) {
            if ($filter->get($name . '_from') !== '') {
                $where[] = $column . ' >= \'' . self::utc($filter->get($name . '_from'), 0) . '\'';
            }
            if ($filter->get($name . '_to') !== '') {
                $where[] = $column . ' < \'' . self::utc($filter->get($name . '_to'), 1) . '\'';
            }
        }
        if ($this->scope($kind) !== null) {
            $where[] = $this->scope($kind);
        }

        return $where === [] ? '1' : implode(' AND ', $where);
    }

    /**
     * The condition that keeps a table's rows to the selected shops, or null when every shop is read.
     */
    private function scope(string $kind): ?string
    {
        if ($this->shops === null) {
            return null;
        }
        $shops = implode(', ', $this->shops === [] ? [0] : $this->shops);
        switch ($kind) {
            case self::SMAILY:
                return '`id_shop` IN (' . $shops . ')';
            case self::INTELLIGENCE:
                return '`id_connection` IN (SELECT `id` FROM ' . self::table(self::CONNECTION_TABLE) . ' WHERE `id_shop` IN (0, ' . $shops . '))';
            default:
                return '`id_shop` IN (0, ' . $shops . ')';
        }
    }

    private static function tableOf(string $kind): string
    {
        return $kind === self::JOB ? JobStore::TABLE : self::QUEUES[$kind][0];
    }

    /**
     * The start of a day of the shop's time zone, plus $days, as a UTC DATETIME.
     */
    private static function utc(string $day, int $days): string
    {
        return gmdate('Y-m-d H:i:s', (int) strtotime($day . ' 00:00:00 +' . $days . ' day'));
    }

    private static function like(string $term): string
    {
        return '\'%' . pSQL(addcslashes($term, '%_\\')) . '%\'';
    }

    private static function table(string $name): string
    {
        return '`' . _DB_PREFIX_ . $name . '`';
    }
}
