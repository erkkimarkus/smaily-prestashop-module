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
 * - contactSyncs(): `status` = sent, through the index (`status`, `next_retry_at`): the sent rows,
 *   which the queue keeps 30 days (Scheduler\QueueJanitor), counted exactly, as Magento counts them.
 * - catalogItems(): `domain` = catalog and `status` = sent, through the index (`domain`, `status`,
 *   `next_retry_at`): the sent catalog rows, which the queue keeps 30 days, counted exactly, as
 *   Magento counts them.
 * - queuedToday(): `status` = pending and `created_at` from midnight UTC, in both queues; the
 *   index on the status or (`created_at`), whichever the optimizer finds narrower.
 * - failedSince(): `status` = failed, in both queues, through the index (`status`,
 *   `next_retry_at`) of the event queue and (`domain`, `status`, `next_retry_at`) of the ingest
 *   queue, which is why the statement names every domain.
 * - latestRows(), latestIngestRows(), latestJobs(): the newest rows by primary key.
 */
final class DashboardQueries
{
    private const DAY = 86400;

    /** Every domain of the ingest queue: the rows that wait for a flush and the nightly lists. */
    private const INGEST_DOMAINS = [
        IngestQueue::DOMAIN_CATALOG,
        IngestQueue::DOMAIN_CATALOG_REMOVE,
        IngestQueue::DOMAIN_CUSTOMERS,
        IngestQueue::DOMAIN_ORDERS,
        IngestQueue::DOMAIN_MANIFEST,
    ];

    /**
     * The contact syncs that reached Smaily in the 30 days before $now, every one of them.
     */
    public function contactSyncs(int $now): int
    {
        return Sql::count(sprintf(
            'SELECT COUNT(*) FROM %s WHERE `status` = %s AND `event_type` = %s AND `sent_at` >= %s',
            self::table(EventQueue::TABLE),
            self::quote(EventQueue::STATUS_SENT),
            self::quote(ContactSyncHandler::EVENT_TYPE),
            self::time($now - 30 * self::DAY)
        ));
    }

    /**
     * The catalog rows that reached Campaign Intelligence over the given connections in the 30
     * days before $now, every one of them.
     *
     * @param list<int> $connections the ids of the connections
     */
    public function catalogItems(array $connections, int $now): int
    {
        return Sql::count(sprintf(
            'SELECT COUNT(*) FROM %s WHERE `domain` = %s AND `status` = %s AND `id_connection` IN (%s) AND `sent_at` >= %s',
            self::table(IngestQueue::TABLE),
            self::quote(IngestQueue::DOMAIN_CATALOG),
            self::quote(EventQueue::STATUS_SENT),
            implode(', ', array_map('intval', $connections ?: [0])),
            self::time($now - 30 * self::DAY)
        ));
    }

    /**
     * The rows of both queues created since midnight UTC that wait for their first attempt or a
     * retry, as Magento counts them (`DashboardStats::queuedToday()`).
     */
    public function queuedToday(int $now): int
    {
        return self::inBothQueues(sprintf(
            '`status` = %s AND `created_at` >= %s',
            self::quote(EventQueue::STATUS_PENDING),
            self::quote(gmdate('Y-m-d 00:00:00', $now))
        ));
    }

    /**
     * The rows of both queues parked as failed since $since, as Magento counts them
     * (`QueueHealth::failedSince()`).
     */
    public function failedSince(int $since): int
    {
        return self::inBothQueues(sprintf(
            '`status` = %s AND `updated_at` >= %s',
            self::quote(EventQueue::STATUS_FAILED),
            self::time($since)
        ));
    }

    /**
     * The rows of the event queue and of the ingest queue that match $condition, in one statement.
     */
    private static function inBothQueues(string $condition): int
    {
        return Sql::count(sprintf(
            'SELECT (SELECT COUNT(*) FROM %s WHERE %s) + (SELECT COUNT(*) FROM %s WHERE `domain` IN (%s) AND %s)',
            self::table(EventQueue::TABLE),
            $condition,
            self::table(IngestQueue::TABLE),
            implode(', ', array_map([self::class, 'quote'], self::INGEST_DOMAINS)),
            $condition
        ));
    }

    /**
     * The newest queue rows, newest first: no payload and no request; the stored error only to
     * tell a withdrawn reminder (Log\LogStatus), never shown.
     *
     * @return list<array{event_type: string, entity_id: string|null, status: string, last_error: string|null, updated_at: string}>
     */
    public function latestRows(int $limit): array
    {
        return self::rows(sprintf(
            'SELECT `event_type`, `entity_id`, `status`, `last_error`, `updated_at` FROM %s ORDER BY `id` DESC LIMIT %d',
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
