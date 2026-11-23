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

namespace PrestaShop\Module\SmailyForPrestaShop\Admin\Dashboard;

if (!defined('_PS_VERSION_')) {
    exit;
}

use PrestaShop\Module\SmailyForPrestaShop\Contacts\Import\JobStore;
use PrestaShop\Module\SmailyForPrestaShop\Database\Sql;
use PrestaShop\Module\SmailyForPrestaShop\Engine\Ingest\IngestQueue;
use PrestaShop\Module\SmailyForPrestaShop\Queue\EventQueue;
use PrestaShop\Module\SmailyForPrestaShop\Queue\Handler\ContactSyncHandler;

/**
 * The Dashboard's reads of the event queue, the ingest queue and the job table: read-only SQL,
 * each statement bounded by an index or by a row limit, so the page stays fast on a queue of a
 * million rows. The queue classes have no such reads; a later clean-up can move them there.
 *
 * - contactSyncs(), catalogItems(): the newest SYNC_SCAN_ROWS rows by primary key, counted in one
 *   pass.
 * - queuedToday(): `status` = pending and `created_at` from midnight UTC; the index (`status`,
 *   `next_retry_at`) or (`created_at`), whichever the optimizer finds narrower.
 * - failedSince(): `status` = failed, through the index (`status`, `next_retry_at`).
 * - latestRows(), latestIngestRows(), latestJobs(): the newest rows by primary key.
 */
final class DashboardQueries
{
    /** contactSyncs() and catalogItems() read at most this many of the newest rows of their queue. */
    public const SYNC_SCAN_ROWS = 50000;

    private const DAY = 86400;

    /**
     * The contact syncs that reached Smaily in the 30 days before $now. `more` is true when the
     * newest SYNC_SCAN_ROWS rows were all younger than 30 days: older ones were not read, so the
     * count is a lower bound.
     *
     * @return array{count: int, more: bool}
     */
    public function contactSyncs(int $now): array
    {
        return self::delivered(EventQueue::TABLE, '`event_type`', 't.`event_type` = ' . self::quote(ContactSyncHandler::EVENT_TYPE), $now);
    }

    /**
     * The catalog rows that reached Campaign Intelligence over the given connections in the 30
     * days before $now, counted as contactSyncs() counts.
     *
     * @param list<int> $connections the ids of the connections
     *
     * @return array{count: int, more: bool}
     */
    public function catalogItems(array $connections, int $now): array
    {
        return self::delivered(
            IngestQueue::TABLE,
            '`domain`, `id_connection`',
            't.`domain` = ' . self::quote(IngestQueue::DOMAIN_CATALOG) . ' AND t.`id_connection` IN (' . implode(', ', array_map('intval', $connections ?: [0])) . ')',
            $now
        );
    }

    /**
     * The rows of a queue that match $condition and were sent in the 30 days before $now, among
     * the newest SYNC_SCAN_ROWS rows of the table.
     *
     * @param string $columns the columns of the table that $condition reads
     *
     * @return array{count: int, more: bool}
     */
    private static function delivered(string $table, string $columns, string $condition, int $now): array
    {
        $since = gmdate('Y-m-d H:i:s', $now - 30 * self::DAY);
        $row = Sql::row(sprintf(
            'SELECT SUM(t.`status` = %s AND %s AND t.`sent_at` >= %s) AS `delivered`,'
            . ' COUNT(*) AS `scanned`, MIN(t.`created_at`) AS `oldest`'
            . ' FROM (SELECT `status`, %s, `sent_at`, `created_at` FROM %s ORDER BY `id` DESC LIMIT %d) t',
            self::quote(EventQueue::STATUS_SENT),
            $condition,
            self::quote($since),
            $columns,
            self::table($table),
            self::SYNC_SCAN_ROWS
        ));

        return [
            'count' => (int) ($row['delivered'] ?? 0),
            'more' => (int) ($row['scanned'] ?? 0) === self::SYNC_SCAN_ROWS && (string) $row['oldest'] >= $since,
        ];
    }

    /**
     * The rows created since midnight UTC that wait for their first attempt or a retry.
     */
    public function queuedToday(int $now): int
    {
        return Sql::count(sprintf(
            'SELECT COUNT(*) FROM %s WHERE `status` = %s AND `created_at` >= %s',
            self::table(EventQueue::TABLE),
            self::quote(EventQueue::STATUS_PENDING),
            self::quote(gmdate('Y-m-d 00:00:00', $now))
        ));
    }

    /**
     * The rows parked as failed since $since.
     */
    public function failedSince(int $since): int
    {
        return Sql::count(sprintf(
            'SELECT COUNT(*) FROM %s WHERE `status` = %s AND `updated_at` >= %s',
            self::table(EventQueue::TABLE),
            self::quote(EventQueue::STATUS_FAILED),
            self::time($since)
        ));
    }

    /**
     * The newest queue rows, newest first: no payload, no request, no error text.
     *
     * @return list<array{event_type: string, entity_id: string|null, status: string, updated_at: string}>
     */
    public function latestRows(int $limit): array
    {
        return self::rows(sprintf(
            'SELECT `event_type`, `entity_id`, `status`, `updated_at` FROM %s ORDER BY `id` DESC LIMIT %d',
            self::table(EventQueue::TABLE),
            $limit
        ));
    }

    /**
     * The newest rows of the ingest queue, which go to Campaign Intelligence, newest first: no
     * payload, no request, no error text.
     *
     * @return list<array{domain: string, entity_key: string, status: string, updated_at: string}>
     */
    public function latestIngestRows(int $limit): array
    {
        return self::rows(sprintf(
            'SELECT `domain`, `entity_key`, `status`, `updated_at` FROM %s ORDER BY `id` DESC LIMIT %d',
            self::table(IngestQueue::TABLE),
            $limit
        ));
    }

    /**
     * The newest import jobs, newest first. A job that names a connection is an import to Campaign
     * Intelligence.
     *
     * @return list<array{type: string, id_connection: string|null, status: string, counts: string|null, updated_at: string}>
     */
    public function latestJobs(int $limit): array
    {
        return self::rows(sprintf(
            'SELECT `type`, `id_connection`, `status`, `counts`, `updated_at` FROM %s ORDER BY `id` DESC LIMIT %d',
            self::table(JobStore::TABLE),
            $limit
        ));
    }

    /**
     * @return list<array<string, string|null>>
     */
    private static function rows(string $sql): array
    {
        return Sql::rows($sql);
    }

    private static function time(int $time): string
    {
        return self::quote(gmdate('Y-m-d H:i:s', $time));
    }

    private static function table(string $name): string
    {
        return '`' . _DB_PREFIX_ . $name . '`';
    }

    private static function quote(string $value): string
    {
        return '\'' . pSQL($value) . '\'';
    }
}
