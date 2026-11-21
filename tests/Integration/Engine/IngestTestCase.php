<?php
declare(strict_types=1);

namespace Tests\Integration\Engine;

use PrestaShop\Module\SmailyForPrestaShop\Engine\Capture\IdentityCapture;
use PrestaShop\Module\SmailyForPrestaShop\Engine\Capture\IngestMarkers;
use PrestaShop\Module\SmailyForPrestaShop\Engine\Ingest\IngestFlusher;
use PrestaShop\Module\SmailyForPrestaShop\Engine\Ingest\IngestQueue;
use PrestaShop\Module\SmailyForPrestaShop\Hook\Dispatcher;
use PrestaShop\Module\SmailyForPrestaShop\Queue\EventQueue;
use PrestaShop\Module\SmailyForPrestaShop\Runtime\Services;
use PrestaShop\Module\SmailyForPrestaShop\Scheduler\Deadline;

require_once __DIR__ . '/EngineTestCase.php';

/**
 * The base of the ingest tests: an empty ingest queue, the engine mock in place of the engine, and
 * the store's own hooks as the source of what is queued.
 *
 * A change made through PrestaShop's own objects fires the module's hooks, whose listeners belong
 * to the shop's runtime (Services::instance()); commit() writes what they noted, as the end of a
 * request does. flush() then sends through the test's runtime, whose every request goes to the
 * mock.
 */
abstract class IngestTestCase extends EngineTestCase
{
    protected const CATALOG = '/api/v1/ingest/catalog';

    protected const REMOVE = '/api/v1/ingest/catalog/remove';

    protected const MANIFEST = '/api/v1/ingest/catalog/manifest';

    protected const CUSTOMERS = '/api/v1/ingest/customers';

    protected const ORDERS = '/api/v1/ingest/orders';

    protected const MERGE = '/api/v1/identity/merge';

    /** The tables that hold a product for a shop. */
    private const PRODUCT_TABLES = ['product_shop', 'product_lang', 'product_attribute_shop', 'stock_available', 'image_shop'];

    /** The tables that give a shop its languages and its category names. */
    private const SHOP_TABLES = ['lang_shop', 'category_lang'];

    /** @var list<\ObjectModel> what the test created, deleted again at its end, newest first */
    private array $created = [];

    /** @var list<int> the shops that shareProducts() gave products */
    private array $sharedWith = [];

    protected function setUp(): void
    {
        parent::setUp();
        // Markers that an earlier test's hooks left in the shop's runtime are not this test's.
        Services::instance()->get(IngestMarkers::class)->commit();
        self::clearQueues();
    }

    protected function tearDown(): void
    {
        if (defined('_PS_ROOT_DIR_')) {
            foreach (array_reverse($this->created) as $object) {
                try {
                    $object->delete();
                } catch (\Throwable $error) {
                    // Already gone: the test deleted it.
                }
            }
            $this->created = [];
            foreach ($this->sharedWith as $idShop) {
                foreach (array_merge(self::PRODUCT_TABLES, self::SHOP_TABLES) as $table) {
                    self::db()->delete($table, '`id_shop` = ' . $idShop);
                }
            }
            $this->sharedWith = [];
            Services::instance()->get(IngestMarkers::class)->commit();
            self::clearQueues();
        }
        parent::tearDown();
    }

    /**
     * Makes another shop hold products of the default shop as the default shop holds them: their
     * texts, combinations, stock and cover image, with the default shop's languages and category
     * names. The rows go again when the test ends.
     *
     * @param list<int> $idProducts
     */
    protected function shareProducts(array $idProducts, int $idShop): void
    {
        $default = (int) \Configuration::get('PS_SHOP_DEFAULT');
        $first = !in_array($idShop, $this->sharedWith, true);
        $this->sharedWith[] = $idShop;
        foreach (array_merge(self::PRODUCT_TABLES, $first ? self::SHOP_TABLES : []) as $table) {
            $columns = array_column(self::db()->executeS('SHOW COLUMNS FROM `' . _DB_PREFIX_ . $table . '`', true, false), 'Field');
            $columns = array_values(array_diff($columns, ['id_stock_available']));
            $select = array_map(static fn (string $column): string => $column === 'id_shop' ? (string) $idShop : '`' . $column . '`', $columns);
            self::db()->execute(
                'INSERT IGNORE INTO `' . _DB_PREFIX_ . $table . '` (`' . implode('`, `', $columns) . '`) SELECT ' . implode(', ', $select)
                . ' FROM `' . _DB_PREFIX_ . $table . '` WHERE `id_shop` = ' . $default
                . (in_array($table, self::PRODUCT_TABLES, true) ? ' AND `id_product` IN (' . implode(', ', array_map('intval', $idProducts)) . ')' : '')
            );
        }
        \Language::loadLanguages();
    }

    /**
     * Keeps an object that the test added, so that it is deleted when the test ends.
     *
     * @template T of \ObjectModel
     *
     * @param T $object
     *
     * @return T
     */
    protected function keep(\ObjectModel $object): \ObjectModel
    {
        $this->created[] = $object;

        return $object;
    }

    /**
     * Writes what the hooks of this "request" noted to the ingest queue.
     *
     * @return int the number of rows written
     */
    protected function commit(): int
    {
        return Services::instance()->get(IngestMarkers::class)->commit() + $this->services->get(IngestMarkers::class)->commit();
    }

    /**
     * Hands a hook call to the listeners of the test's runtime, as the main class does for the
     * shop's; a listener that fails fails the test.
     *
     * @param array<string, mixed> $params
     */
    protected function fire(string $hook, array $params): void
    {
        (new Dispatcher(static fn (): bool => true, fn (): Services => $this->services, static function (string $hook, \Throwable $error): void {
            throw $error;
        }))->run($hook, $params);
    }

    protected function flush(): void
    {
        $this->services->get(IngestFlusher::class)->flush(new Deadline(microtime(true) + 60, static fn (): float => microtime(true)));
    }

    /**
     * The rows of the ingest queue, oldest first.
     *
     * @return list<array<string, string|null>>
     */
    protected static function rows(?string $domain = null): array
    {
        return self::text(self::db()->executeS(
            'SELECT * FROM `' . _DB_PREFIX_ . IngestQueue::TABLE . '`'
            . ($domain === null ? '' : ' WHERE `domain` = \'' . pSQL($domain) . '\'') . ' ORDER BY `id`',
            true,
            false
        ) ?: []);
    }

    /**
     * The domain, entity and status of each row of the ingest queue, oldest first.
     *
     * @return list<array{0: string, 1: string, 2: string}>
     */
    protected static function summary(): array
    {
        return array_map(static fn (array $row): array => [$row['domain'], $row['entity_key'], $row['status']], self::rows());
    }

    /**
     * The rows of the event queue that hold an identity merge, oldest first.
     *
     * @return list<array<string, string|null>>
     */
    protected static function mergeRows(): array
    {
        return self::text(self::db()->executeS(
            'SELECT * FROM `' . _DB_PREFIX_ . EventQueue::TABLE . '` WHERE `event_type` = \'' . IdentityCapture::EVENT_TYPE . '\' ORDER BY `id`',
            true,
            false
        ) ?: []);
    }

    /**
     * Rows with every value as text: PrestaShop 9 on a newer PHP answers a number column as a
     * number, PrestaShop 8 as text.
     *
     * @param list<array<string, mixed>> $rows
     *
     * @return list<array<string, string|null>>
     */
    private static function text(array $rows): array
    {
        return array_map(
            static fn (array $row): array => array_map(static fn ($value): ?string => $value === null ? null : (string) $value, $row),
            $rows
        );
    }

    /**
     * The items of every request that the mock got on a path, oldest first.
     *
     * @return list<array<string, mixed>>
     */
    protected function sent(string $path, string $wrapper): array
    {
        $items = [];
        foreach ($this->engine->requestsTo($path) as $request) {
            foreach ($request['body'][$wrapper] ?? [] as $item) {
                $items[] = $item;
            }
        }

        return $items;
    }

    private static function clearQueues(): void
    {
        self::db()->delete(IngestQueue::TABLE);
        self::db()->delete(EventQueue::TABLE, '`event_type` = \'' . IdentityCapture::EVENT_TYPE . '\'');
    }
}
