<?php
declare(strict_types=1);

namespace Tests\Integration\Engine;

use PrestaShop\Module\SmailyForPrestaShop\Engine\Client;
use PrestaShop\Module\SmailyForPrestaShop\Engine\Connection;
use PrestaShop\Module\SmailyForPrestaShop\Engine\Ingest\CatalogManifest;
use PrestaShop\Module\SmailyForPrestaShop\Engine\Ingest\IngestQueue;
use PrestaShop\Module\SmailyForPrestaShop\Engine\Payload\CatalogPayloadBuilder;
use PrestaShop\Module\SmailyForPrestaShop\Engine\Payload\Keys;
use PrestaShop\Module\SmailyForPrestaShop\Queue\EventQueue;
use PrestaShop\Module\SmailyForPrestaShop\Scheduler\Deadline;
use PrestaShop\Module\SmailyForPrestaShop\Scope\StoreLayout;
use Tests\Integration\Migration\LegacyStore;
use Tests\Integration\MultistoreShop;

require_once __DIR__ . '/IngestTestCase.php';
require_once __DIR__ . '/../MultistoreShop.php';
require_once __DIR__ . '/../Migration/LegacyStore.php';

/**
 * The nightly product list (contract §3c): once a night, the connection's own products only, under
 * its prefix, and none for a connection that shares its engine store with another while either
 * holds no prefix (ARCHITECTURE 5.3).
 */
final class CatalogManifestTest extends IngestTestCase
{
    use MultistoreShop;

    private int $now;

    protected function setUp(): void
    {
        parent::setUp();
        // 04:00 of the shop's day: past the time from which a night's list is due.
        $this->now = (int) strtotime(date('Y-m-d') . ' 04:00:00');
    }

    protected function tearDown(): void
    {
        if (defined('_PS_ROOT_DIR_') && $this->savedContext > 0) {
            $this->closeMultistore();
        }
        parent::tearDown();
    }

    public function testTheListHoldsTheSkuAndStockOfEveryRowOfTheConnectionsProducts(): void
    {
        $this->engine->set('next_prefix', 'c2-');
        $connection = $this->connect();
        $expected = self::expectedSkus('c2-', (int) \Configuration::get('PS_SHOP_DEFAULT'));

        $this->manifest()->run(self::deadline());

        $requests = $this->engine->requestsTo(self::MANIFEST);
        self::assertCount(1, $requests);
        self::assertSame('Bearer ' . $connection->apiKey(), $requests[0]['authorization']);
        self::assertSame(['products'], array_keys($requests[0]['body']));
        $products = $requests[0]['body']['products'];
        self::assertSame($expected, array_column($products, 'sku'), 'every row of every product that is switched on, and no other');
        self::assertGreaterThan(20, count($products));
        foreach ($products as $item) {
            self::assertSame(['sku', 'in_stock'], array_keys($item), 'nothing but the key and the stock');
            self::assertIsBool($item['in_stock']);
            self::assertStringStartsWith('c2-' . Keys::PLATFORM_NAMESPACE, $item['sku']);
        }

        $row = self::rows(IngestQueue::DOMAIN_MANIFEST)[0];
        self::assertSame([(string) $connection->id(), EventQueue::STATUS_SENT, null], [$row['id_connection'], $row['status'], $row['last_error']]);
        $logged = json_decode((string) $row['last_request'], true);
        self::assertSame([count($products), 20], [$logged['count'], count($logged['products'])], 'the Log keeps the size of the list and a sample of it');
        self::assertSame(count($products), json_decode((string) $row['last_response'], true)['body']['products_in_manifest']);
    }

    public function testAListGoesOnceANightFromHalfPastThree(): void
    {
        $this->connect();
        $today = (int) strtotime(date('Y-m-d') . ' 00:00:00');

        $this->now = $today + 3 * 3600 + 29 * 60;
        $this->manifest()->run(self::deadline());
        self::assertCount(1, $this->engine->requestsTo(self::MANIFEST), 'no list was sent since half past three of the day before');

        $this->now = $today + 3 * 3600 + 30 * 60;
        $this->manifest()->run(self::deadline());
        $this->now = $today + 23 * 3600;
        $this->manifest()->run(self::deadline());
        self::assertCount(2, $this->engine->requestsTo(self::MANIFEST), 'one list for this night, however often the job runs');

        $this->now = $today + 86400 + 4 * 3600;
        $this->manifest()->run(self::deadline());
        self::assertCount(3, $this->engine->requestsTo(self::MANIFEST));
    }

    public function testNoListIsSentWhileCatalogRowsWaitOrTheConnectionIsRefused(): void
    {
        $connection = $this->connect();
        $queue = $this->services->get(IngestQueue::class);
        $waiting = $queue->enqueue($connection->id(), IngestQueue::DOMAIN_CATALOG_REMOVE, '987654');

        $this->manifest()->run(self::deadline());
        self::assertSame([], $this->engine->requestsTo(self::MANIFEST), 'a removal still waits: the list would be older than it');

        self::db()->delete(IngestQueue::TABLE, '`id` = ' . $waiting);
        $this->engine->revokeKeys();
        $this->check($connection);
        $this->manifest()->run(self::deadline());
        self::assertSame([], $this->engine->requestsTo(self::MANIFEST), 'the engine refuses the connection');
        self::assertSame([], self::rows(), 'a night without a list leaves no row');
    }

    public function testAListThatTheEngineDoesNotTakeIsRecordedAndNotSentAgainThatNight(): void
    {
        $this->connect();
        $this->engine->fail('catalog_manifest', 503, null, 'No customer with email shopper@example.test');

        $this->manifest()->run(self::deadline());
        $this->engine->set('fail', []);
        $this->manifest()->run(self::deadline());

        self::assertCount(1, $this->engine->requestsTo(self::MANIFEST));
        $row = self::rows(IngestQueue::DOMAIN_MANIFEST)[0];
        self::assertSame([EventQueue::STATUS_FAILED, 'internal_error'], [$row['status'], $row['last_error']]);
        self::assertStringNotContainsString('shopper@example.test', implode('|', array_map('strval', $row)));
    }

    public function testEachShopOfSeparateStoresSendsItsOwnProductsUnderItsOwnPrefix(): void
    {
        [$first, $second] = $this->separateStores();
        $this->shareProducts([1, 2], $second);
        $this->engine->set('tenant_id', '550e8400-e29b-41d4-a716-446655440000');
        $this->engine->set('next_prefix', 'c2-');
        $one = $this->connect($first);
        $this->engine->set('next_prefix', 'c3-');
        $two = $this->connect($second);
        self::assertSame($one->tenantId(), $two->tenantId(), 'two connections of one engine store');

        $this->manifest()->run(self::deadline());

        $requests = $this->engine->requestsTo(self::MANIFEST);
        self::assertSame(['Bearer ' . $one->apiKey(), 'Bearer ' . $two->apiKey()], array_column($requests, 'authorization'));
        self::assertSame(self::expectedSkus('c2-', $first), array_column($requests[0]['body']['products'], 'sku'));
        self::assertSame(self::expectedSkus('c3-', $second), array_column($requests[1]['body']['products'], 'sku'), 'the second shop lists its own two products');
        self::assertCount(count(self::combinations(1)) + count(self::combinations(2)), $requests[1]['body']['products']);
    }

    /**
     * @dataProvider unprefixed
     */
    public function testNoListIsSentForConnectionsOfOneEngineStoreWhileEitherHoldsNoPrefix(string $firstPrefix, string $secondPrefix): void
    {
        [$first, $second] = $this->separateStores();
        $this->shareProducts([1, 2], $second);
        $this->engine->set('tenant_id', '550e8400-e29b-41d4-a716-446655440000');
        $this->engine->set('next_prefix', $firstPrefix);
        $one = $this->connect($first);
        $this->engine->set('next_prefix', $secondPrefix);
        $two = $this->connect($second);
        self::assertSame([$firstPrefix, $secondPrefix], [$one->prefix(), $two->prefix()]);

        $this->manifest()->run(self::deadline());

        self::assertSame([], $this->engine->requestsTo(self::MANIFEST), 'two lists without a prefix would each claim the whole engine store');
        self::assertTrue(CatalogManifest::suppressed($one, [$one, $two]));
        self::assertTrue(CatalogManifest::suppressed($two, [$one, $two]));

        // A connection of another engine store is not held back by them.
        $this->engine->set('tenant_id', '660e8400-e29b-41d4-a716-446655440001');
        $other = new Connection(99, 0, '', '660e8400-e29b-41d4-a716-446655440001', 'k', ['config' => ['connection' => ['prefix' => '']]]);
        self::assertFalse(CatalogManifest::suppressed($other, [$one, $two, $other]));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function unprefixed(): iterable
    {
        yield 'neither holds a prefix, as the engine assigns today' => ['', ''];
        yield 'the first holds none' => ['', 'c3-'];
        yield 'the second holds none' => ['c2-', ''];
    }

    public function testAProductThatWasDeletedWithoutItsHookIsHealedByTheList(): void
    {
        $connection = $this->connect();
        $product = new \Product();
        $product->name = array_fill_keys(array_map('intval', \Language::getIDs(false)), 'Ingest test product');
        $product->link_rewrite = array_fill_keys(array_map('intval', \Language::getIDs(false)), 'ingest-test-product');
        $product->price = 10.0;
        $product->id_category_default = (int) \Configuration::get('PS_HOME_CATEGORY');
        self::assertTrue($product->add());
        $this->keep($product);
        // The engine holds more rows than this one: its guard takes a list that drops over 20 %.
        (new \Product(1))->save();
        $this->commit();
        $this->flush();
        // The row of the product leaves the shop, and no hook tells the module.
        self::db()->delete('product_shop', '`id_product` = ' . (int) $product->id);

        $this->manifest()->run(self::deadline());

        $row = self::rows(IngestQueue::DOMAIN_MANIFEST)[0];
        self::assertSame((string) $connection->id(), $row['id_connection']);
        $answer = json_decode((string) $row['last_response'], true)['body'];
        self::assertSame([1, false], [$answer['removed'], $answer['guard_tripped']], 'the engine stops recommending the product that the list leaves out');
    }

    private function manifest(): CatalogManifest
    {
        return new CatalogManifest(
            $this->connections(),
            new IngestQueue(fn (): int => $this->now),
            $this->services->get(Client::class),
            $this->services->get(CatalogPayloadBuilder::class),
            fn (): int => $this->now
        );
    }

    private function check(Connection $connection): void
    {
        $this->services->get(\PrestaShop\Module\SmailyForPrestaShop\Engine\HealthCheck::class)->check($connection);
    }

    private static function deadline(): Deadline
    {
        return new Deadline(microtime(true) + 60, static fn (): float => microtime(true));
    }

    /**
     * Two shops, each set up on its own.
     *
     * @return array{0: int, 1: int}
     */
    private function separateStores(): array
    {
        $this->openMultistore();
        $second = $this->addShop((int) \Shop::getGroupFromShop($this->defaultShopId()));
        \Configuration::updateGlobalValue(StoreLayout::SEPARATE_STORES, '1');
        LegacyStore::reloadConfiguration();

        return [$this->defaultShopId(), $second];
    }

    /**
     * The keys of the rows that the list of a shop holds: every combination of every product that
     * is switched on there, and one row for a product without combinations.
     *
     * @return list<string>
     */
    private static function expectedSkus(string $prefix, int $idShop): array
    {
        $skus = [];
        $products = self::db()->executeS('SELECT `id_product` FROM `' . _DB_PREFIX_ . 'product_shop` WHERE `id_shop` = ' . $idShop . ' AND `active` = 1 ORDER BY `id_product`', true, false);
        foreach (array_column($products, 'id_product') as $idProduct) {
            $combinations = self::combinations((int) $idProduct);
            foreach ($combinations === [] ? [0] : $combinations as $idAttribute) {
                $skus[] = Keys::sku($prefix, (int) $idProduct, $idAttribute);
            }
        }

        return $skus;
    }

    /**
     * @return list<int>
     */
    private static function combinations(int $idProduct): array
    {
        return array_map('intval', array_column(self::db()->executeS(
            'SELECT `id_product_attribute` FROM `' . _DB_PREFIX_ . 'product_attribute` WHERE `id_product` = ' . $idProduct . ' ORDER BY `id_product_attribute`',
            true,
            false
        ), 'id_product_attribute'));
    }
}
