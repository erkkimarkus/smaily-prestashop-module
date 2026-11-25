<?php
declare(strict_types=1);

namespace Tests\Integration\Log;

use PrestaShop\Module\SmailyForPrestaShop\Contacts\Import\JobStore;
use PrestaShop\Module\SmailyForPrestaShop\Log\FailureMessage;
use PrestaShop\Module\SmailyForPrestaShop\Log\LogRows;
use PrestaShop\Module\SmailyForPrestaShop\Queue\EventQueue;
use Tests\Integration\IntegrationTestCase;

/**
 * The base of the Log's tests in the shop: the two queue tables, the job table and the connection
 * table start and end empty, and rows are written as the queues store them, with times of the
 * test's choosing.
 */
abstract class LogTestCase extends IntegrationTestCase
{
    protected const INGEST_TABLE = 'smaily_connect_ingest_queue';

    protected const CONNECTION_TABLE = 'smaily_connect_connection';

    private const TABLES = [EventQueue::TABLE, self::INGEST_TABLE, JobStore::TABLE, self::CONNECTION_TABLE, 'smaily_connect_cart'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->emptyTables();
    }

    protected function tearDown(): void
    {
        if (defined('_PS_ROOT_DIR_')) {
            $this->emptyTables();
        }
        parent::tearDown();
    }

    /**
     * @param list<int>|null $shops
     */
    protected static function rows(?array $shops = null): LogRows
    {
        return new LogRows($shops, static fn (string $term): array => FailureMessage::patternsShowing($term, static fn (string $text): string => $text));
    }

    /**
     * An event-queue row; `created_at` also sets `updated_at` unless that is given.
     *
     * @param array<string, mixed> $columns
     */
    protected static function event(array $columns = []): int
    {
        $columns += ['id_shop' => 1, 'event_type' => 'contact.sync', 'entity_id' => 'shopper1@example.test', 'language' => 'en',
            'payload' => '{"email":"shopper1@example.test"}', 'status' => 'sent', 'attempts' => 0, 'created_at' => '2026-11-20 10:00:00', ];
        $columns += ['updated_at' => $columns['created_at']];

        return self::insert(EventQueue::TABLE, $columns);
    }

    /**
     * @param array<string, mixed> $columns
     */
    protected static function ingest(array $columns = []): int
    {
        $columns += ['id_connection' => 1, 'domain' => 'catalog', 'entity_key' => 'ps-12', 'status' => 'sent', 'attempts' => 0, 'created_at' => '2026-11-20 10:00:00'];
        $columns += ['updated_at' => $columns['created_at']];

        return self::insert(self::INGEST_TABLE, $columns);
    }

    /**
     * @param array<string, mixed> $columns
     */
    protected static function job(array $columns = []): int
    {
        $columns += ['type' => 'contacts_import', 'id_shop' => 0, 'status' => 'done', 'counts' => '{"total":128,"sent":128,"failed":0,"skipped":0}',
            'started_at' => '2026-11-20 10:00:00', ];
        $columns += ['updated_at' => $columns['started_at'] ?? '2026-11-20 10:00:00'];

        return self::insert(JobStore::TABLE, $columns);
    }

    protected static function connection(int $idShop): int
    {
        return self::insert(self::CONNECTION_TABLE, [
            'id_shop' => $idShop, 'smaily_subdomain' => 'example-store', 'tenant_id' => '00000000-0000-4000-8000-00000000000' . $idShop,
            'api_key_enc' => 'x', 'settings' => '{}', 'date_add' => '2026-11-20 10:00:00', 'date_upd' => '2026-11-20 10:00:00',
        ]);
    }

    /**
     * @return array<string, string|null>
     */
    protected static function stored(int $id): array
    {
        return self::db()->getRow('SELECT * FROM `' . _DB_PREFIX_ . EventQueue::TABLE . '` WHERE `id` = ' . $id, false);
    }

    /**
     * @param array<string, mixed> $columns
     */
    private static function insert(string $table, array $columns): int
    {
        $values = array_map(static fn ($value) => $value === null ? null : pSQL((string) $value, true), $columns);
        self::assertTrue(self::db()->insert($table, $values, true), self::db()->getMsgError());

        return (int) self::db()->Insert_ID();
    }

    private function emptyTables(): void
    {
        foreach (self::TABLES as $table) {
            self::db()->delete($table);
        }
    }
}
