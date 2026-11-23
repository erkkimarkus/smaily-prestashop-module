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

/**
 * The rows of the job table (ARCHITECTURE 2.2) that the Campaign Intelligence imports keep: one
 * row per connection and import, of the types `engine_catalog`, `engine_customers` and
 * `engine_orders`. The statuses are the contact import's (Contacts\Import\JobStore).
 *
 * `counts` holds the import's state as JSON: `total` (the number in scope when the import
 * began), `queued` (markers written to the ingest queue), `sent`, `failed` and `skipped` (how
 * those markers stand), `first_row` and `last_row` (the ingest rows the import wrote), and
 * `queued_all`. An orders import, which is the connection's history import (contract §25), also
 * holds its window (`until`, `start_from`, `horizon`, `start_months`, `horizon_months`), its
 * `phase` (`start`, then `older`), `start_last_row`, and what was reported to the engine.
 * `import_id` names the history import; `cursor` is the last product, customer or order queued.
 *
 * `updated_at` moves only when the import moved (a page queued, a count changed): an import that
 * is still to run and has not moved for STALLED_SECONDS shows as stalled. Every write names its
 * row by primary key; a write of the tick names the statuses it starts from, so a cancel that
 * the admin makes during a tick is never overwritten. Every time is UTC.
 */
final class HistoryJobs
{
    public const CATALOG = 'catalog';

    public const CUSTOMERS = 'customers';

    public const ORDERS = 'orders';

    /** The imports, in the order they run; each name is also its ingest domain. */
    public const ENTITIES = [self::CATALOG, self::CUSTOMERS, self::ORDERS];

    private const TYPE_PREFIX = 'engine_';

    /** An import that has not moved for this long is stalled (as the Magento connector). */
    public const STALLED_SECONDS = 3600;

    /** The status that progress() shows for a stalled import; never stored. */
    public const STALLED = 'stalled';

    /** `last_error` of an import that the tick ended because nothing moved it. */
    public const REASON_STALLED = 'stalled';

    /** `last_error` of an import that ended because the engine refuses the connection. */
    public const REASON_NOT_ACTIVE = 'engine_not_active';

    /** `last_error` of an import whose connection is no longer stored. */
    public const REASON_NO_CONNECTION = 'no_connection';

    /** @var callable(): int */
    private $now;

    /**
     * @param callable(): int $now the current Unix time
     */
    public function __construct(callable $now)
    {
        $this->now = $now;
    }

    public static function entityOf(array $job): string
    {
        return substr((string) $job['type'], strlen(self::TYPE_PREFIX));
    }

    /**
     * The newest import of an entity for a connection, with its state decoded as `state`.
     *
     * @return array<string, mixed>|null
     */
    public function latest(int $idConnection, string $entity): ?array
    {
        return self::decode(Sql::row(
            'SELECT * FROM ' . self::table() . ' WHERE `type` = \'' . pSQL(self::TYPE_PREFIX . $entity) . '\''
            . ' AND `id_connection` = ' . $idConnection . ' ORDER BY `id` DESC'
        ));
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(int $id): ?array
    {
        return self::decode(Sql::row('SELECT * FROM ' . self::table() . ' WHERE `id` = ' . $id));
    }

    /**
     * Every import that is still to run, oldest first.
     *
     * @return list<array<string, mixed>>
     */
    public function active(): array
    {
        return array_map([self::class, 'decode'], Sql::rows(
            'SELECT * FROM ' . self::table() . ' WHERE `status` IN (' . self::activeList() . ') AND `type` IN ('
            . implode(', ', array_map(static fn (string $entity): string => '\'' . self::TYPE_PREFIX . $entity . '\'', self::ENTITIES))
            . ') ORDER BY `id`'
        ));
    }

    /**
     * Starts a new import of an entity from the beginning, unless one is still to run.
     */
    public function start(Connection $connection, string $entity): void
    {
        $latest = $this->latest($connection->id(), $entity);
        if ($latest !== null && in_array($latest['status'], JobStore::ACTIVE, true)) {
            return;
        }
        $now = $this->time();
        Sql::write(sprintf(
            'INSERT INTO %s (`type`, `id_shop`, `id_connection`, `status`, `counts`, `updated_at`) VALUES (\'%s\', %d, %d, \'%s\', \'%s\', \'%s\')',
            self::table(),
            pSQL(self::TYPE_PREFIX . $entity),
            $connection->idShop(),
            $connection->id(),
            JobStore::STATUS_QUEUED,
            pSQL((string) json_encode(['total' => 0, 'queued' => 0, 'sent' => 0, 'failed' => 0, 'skipped' => 0])),
            $now
        ));
    }

    /** The catalog, the customers and the orders import, to run one after another. */
    public function startAll(Connection $connection): void
    {
        foreach (self::ENTITIES as $entity) {
            $this->start($connection, $entity);
        }
    }

    /**
     * The admin's cancel: the import of the entity stops before its next page, and the imports
     * that wait behind it do not start (the sequence ends, as on the sibling connectors). The
     * markers already written still go out.
     */
    public function cancel(Connection $connection, string $entity): void
    {
        $behind = false;
        foreach (self::ENTITIES as $name) {
            $job = $this->latest($connection->id(), $name);
            if ($name === $entity) {
                $behind = true;
                if ($job !== null) {
                    $this->end($job, JobStore::STATUS_CANCELLED, null);
                }
            } elseif ($behind && $job !== null && $job['status'] === JobStore::STATUS_QUEUED) {
                $this->end($job, JobStore::STATUS_CANCELLED, null);
            }
        }
    }

    /**
     * Ends an import that is still to run; one that already ended stays as it is.
     *
     * @param array<string, mixed> $job
     */
    public function end(array $job, string $status, ?string $reason): void
    {
        $now = $this->time();
        Sql::write(sprintf(
            'UPDATE %s SET `status` = \'%s\', `last_error` = %s, `finished_at` = \'%s\', `updated_at` = \'%s\' WHERE `id` = %d AND `status` IN (%s)',
            self::table(),
            pSQL($status),
            $reason === null ? 'NULL' : '\'' . pSQL($reason) . '\'',
            $now,
            $now,
            (int) $job['id'],
            self::activeList()
        ));
    }

    /**
     * The tick's write of an import that moved: its state and the given columns, and
     * `updated_at`. Nothing is written for an import that is no longer to run.
     *
     * @param array<string, mixed> $job
     * @param array<string, mixed> $state
     * @param array<string, string> $columns `status`, `cursor`, `started_at` or `import_id`
     */
    public function move(array $job, array $state, array $columns = []): void
    {
        $assignments = '';
        foreach ($columns as $column => $value) {
            $assignments .= ', `' . $column . '` = \'' . pSQL($value) . '\'';
        }
        Sql::write(sprintf(
            'UPDATE %s SET `counts` = \'%s\', `updated_at` = \'%s\'%s WHERE `id` = %d AND `status` IN (%s)',
            self::table(),
            pSQL((string) json_encode($state)),
            $this->time(),
            $assignments,
            (int) $job['id'],
            self::activeList()
        ));
    }

    /**
     * Writes an import's state alone, in any status and without `updated_at`: what was reported
     * to the engine is no movement of the import.
     *
     * @param array<string, mixed> $job
     * @param array<string, mixed> $state
     */
    public function note(array $job, array $state): void
    {
        Sql::write(sprintf(
            'UPDATE %s SET `counts` = \'%s\' WHERE `id` = %d',
            self::table(),
            pSQL((string) json_encode($state)),
            (int) $job['id']
        ));
    }

    /**
     * Whether nothing has moved an import that is still to run for STALLED_SECONDS.
     *
     * @param array<string, mixed> $job
     */
    public function isStalled(array $job): bool
    {
        return in_array($job['status'], JobStore::ACTIVE, true)
            && (int) strtotime($job['updated_at'] . ' UTC') <= ($this->now)() - self::STALLED_SECONDS;
    }

    /**
     * Where the three imports of a connection stand, for the import cards: per entity null (no
     * import yet) or `status` (a job status, or STALLED), `total`, `sent`, `failed`, `phase`
     * (of a running orders import), `start_months`, `horizon_months`, `reason` and
     * `finished_at`. Counts and codes only. Only the import that the tick works on can stall:
     * one that waits behind it is queued.
     *
     * @return array<string, array<string, mixed>|null>
     */
    public function progress(Connection $connection): array
    {
        $progress = [];
        $first = true;
        foreach (self::ENTITIES as $entity) {
            $job = $this->latest($connection->id(), $entity);
            if ($job === null) {
                $progress[$entity] = null;
                continue;
            }
            $active = in_array($job['status'], JobStore::ACTIVE, true);
            $stalled = ($active && $first && $this->isStalled($job))
                || ($job['status'] === JobStore::STATUS_FAILED && $job['last_error'] === self::REASON_STALLED);
            $first = $first && !$active;
            $state = $job['state'];
            $progress[$entity] = [
                'status' => $stalled ? self::STALLED : (string) $job['status'],
                'active' => $active,
                'total' => (int) ($state['total'] ?? 0),
                'sent' => (int) ($state['sent'] ?? 0),
                'failed' => (int) ($state['failed'] ?? 0),
                'phase' => $state['phase'] ?? null,
                'start_months' => $state['start_months'] ?? null,
                'horizon_months' => $state['horizon_months'] ?? null,
                'reason' => $job['last_error'],
                'finished_at' => $job['finished_at'],
            ];
        }

        return $progress;
    }

    /**
     * @param array<string, mixed>|null $row
     *
     * @return array<string, mixed>|null
     */
    private static function decode(?array $row): ?array
    {
        if ($row === null) {
            return null;
        }
        $state = json_decode((string) $row['counts'], true);
        $row['state'] = is_array($state) ? $state : [];

        return $row;
    }

    private function time(): string
    {
        return gmdate('Y-m-d H:i:s', ($this->now)());
    }

    private static function activeList(): string
    {
        return '\'' . implode('\', \'', JobStore::ACTIVE) . '\'';
    }

    private static function table(): string
    {
        return '`' . _DB_PREFIX_ . JobStore::TABLE . '`';
    }
}
