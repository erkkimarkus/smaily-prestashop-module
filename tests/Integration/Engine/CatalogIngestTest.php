<?php
declare(strict_types=1);

namespace Tests\Integration\Engine;

use PrestaShop\Module\SmailyForPrestaShop\Engine\HealthCheck;
use PrestaShop\Module\SmailyForPrestaShop\Engine\Ingest\IngestFlusher;
use PrestaShop\Module\SmailyForPrestaShop\Engine\Ingest\IngestQueue;
use PrestaShop\Module\SmailyForPrestaShop\Engine\Payload\Keys;
use PrestaShop\Module\SmailyForPrestaShop\Queue\EventQueue;

require_once __DIR__ . '/IngestTestCase.php';

/**
 * A change of the catalog reaches the engine as the contract's rows (§3) and removals (§3b): one
 * queue row per product per request, one catalog row per combination, gross prices, the key of
 * ARCHITECTURE 8.2 under the connection's prefix, and nothing without a stored connection.
 */
final class CatalogIngestTest extends IngestTestCase
{
    /** A demo product with combinations: "Hummingbird printed t-shirt", 20 % off. */
    private const SHIRT = 1;

    /** @var array<string, int> the stock that a test changed, by "product:combination" */
    private array $savedStock = [];

    protected function tearDown(): void
    {
        if (defined('_PS_ROOT_DIR_')) {
            foreach ($this->savedStock as $key => $quantity) {
                [$idProduct, $idAttribute] = array_map('intval', explode(':', $key));
                \StockAvailable::setQuantity($idProduct, $idAttribute, $quantity);
            }
            $this->savedStock = [];
        }
        parent::tearDown();
    }

    public function testAProductSaveQueuesOneRowHoweverOftenItsHookFires(): void
    {
        $this->connect();
        $product = new \Product(self::SHIRT);

        // The v2 product page fires the hook seven times in one save (facts file part 2).
        for ($call = 0; $call < 7; ++$call) {
            \Hook::exec('actionObjectProductUpdateAfter', ['object' => $product]);
        }

        self::assertSame(1, $this->commit());
        self::assertSame([[IngestQueue::DOMAIN_CATALOG, (string) self::SHIRT, EventQueue::STATUS_PENDING]], self::summary());
        self::assertNull(self::rows()[0]['payload'], 'the row is a marker: it holds nothing of the product');
        self::assertSame([], $this->engine->requestsTo(self::CATALOG), 'nothing is sent before the flush');
    }

    public function testACombinationSaveListQueuesOneRowForTheProduct(): void
    {
        $this->connect();

        // "Save list" saves every row of the list, changed or not.
        foreach (self::combinations(self::SHIRT) as $idAttribute) {
            (new \Combination($idAttribute))->save();
        }

        self::assertSame(1, $this->commit());
        self::assertSame([[IngestQueue::DOMAIN_CATALOG, (string) self::SHIRT, EventQueue::STATUS_PENDING]], self::summary());
    }

    public function testASavedProductGoesOutAsOneRowPerCombinationWithGrossPricesAndTheKey(): void
    {
        $this->engine->set('next_prefix', 'c2-');
        $connection = $this->connect();
        (new \Product(self::SHIRT))->save();
        $this->commit();

        $this->flush();

        $requests = $this->engine->requestsTo(self::CATALOG);
        self::assertCount(1, $requests);
        self::assertSame('Bearer ' . $connection->apiKey(), $requests[0]['authorization']);
        self::assertSame(['products'], array_keys($requests[0]['body']));
        $sent = $requests[0]['body']['products'];
        $combinations = self::combinations(self::SHIRT);
        self::assertCount(8, $combinations);
        self::assertSame(
            array_map(static fn (int $idAttribute): string => 'c2-' . Keys::PLATFORM_NAMESPACE . self::SHIRT . '-' . $idAttribute, $combinations),
            array_column($sent, 'sku')
        );

        $first = $sent[0];
        self::assertSame(
            ['event_id', 'sku', 'name', 'category_path', 'price', 'compare_price', 'currency', 'in_stock', 'description', 'image_url', 'product_url', 'external_id', 'tags', 'product_type', 'is_virtual'],
            array_keys($first),
            'the fields of contract §3 that the module sends, and no other'
        );
        self::assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $first['event_id']);
        self::assertSame('Hummingbird printed t-shirt - Size: S, Color: White', $first['name']);
        self::assertSame('clothes/men', $first['category_path']);
        // Gross, after the product's 20 % reduction; the price before it as the compare price.
        $gross = round((float) \Product::priceCalculation(1, self::SHIRT, $combinations[0], (int) \Configuration::get('PS_COUNTRY_DEFAULT'), 0, '', (int) \Configuration::get('PS_CURRENCY_DEFAULT'), (int) \Configuration::get('PS_UNIDENTIFIED_GROUP'), 1, true, 6, false, false, true, $none, true), 2);
        self::assertSame(round($gross * 0.8, 2), $first['price']);
        self::assertSame($gross, $first['compare_price']);
        self::assertSame((new \Currency((int) \Configuration::get('PS_CURRENCY_DEFAULT')))->iso_code, $first['currency']);
        self::assertTrue($first['in_stock']);
        self::assertNotSame('', $first['description']);
        self::assertStringNotContainsString('<', $first['description']);
        self::assertMatchesRegularExpression('~^https?://[^/]+/.+\.jpg$~', $first['image_url']);
        self::assertMatchesRegularExpression('~^https?://[^/]+/.*' . self::SHIRT . '-' . $combinations[0] . '-hummingbird-printed-t-shirt\.html#/~', $first['product_url']);
        self::assertSame(self::SHIRT . '-' . $combinations[0], $first['external_id']);
        self::assertSame(['category_path' => 'clothes/men', 'product_id' => 'c2-' . self::SHIRT, 'brand' => 'Studio Design'], $first['tags']);
        self::assertSame('combinations', $first['product_type']);
        self::assertFalse($first['is_virtual']);
        self::assertSame([], array_filter(array_column($sent, 'in_stock'), static fn (bool $inStock): bool => !$inStock));

        $row = self::rows()[0];
        self::assertSame(EventQueue::STATUS_SENT, $row['status']);
        self::assertSame($requests[0]['body'], json_decode((string) $row['last_request'], true), 'the row keeps what was sent for it');
        self::assertSame(
            ['http_status' => 200, 'body' => ['errors' => [], 'ok' => true, 'processed' => 8, 'deduplicated' => 0, 'unmapped_attributes' => []]],
            json_decode((string) $row['last_response'], true)
        );
    }

    public function testAStockChangeSendsTheRowsWithEachCombinationsOwnStock(): void
    {
        $this->connect();
        $combinations = self::combinations(self::SHIRT);
        $this->setStock(self::SHIRT, $combinations[1], 0);

        self::assertSame(1, $this->commit(), 'the stock hooks of the combination and of the product leave one row');
        $this->flush();

        $inStock = array_column($this->sent(self::CATALOG, 'products'), 'in_stock', 'sku');
        self::assertFalse($inStock[Keys::sku('', self::SHIRT, $combinations[1])]);
        self::assertTrue($inStock[Keys::sku('', self::SHIRT, $combinations[0])]);
        self::assertCount(8, $inStock);
    }

    public function testASpecificPriceChangesThePriceAndTheComparePrice(): void
    {
        $this->connect();
        $product = $this->product(['price' => 20.0]);
        $this->commit();
        $this->flush();
        $before = $this->sent(self::CATALOG, 'products')[0];
        self::assertArrayNotHasKey('compare_price', $before, 'no reduction: no compare price');

        $specificPrice = $this->keep(self::specificPrice((int) $product->id, 0.25));
        self::assertSame(1, $this->commit());
        $this->flush();
        $specificPrice->delete();
        self::assertSame(1, $this->commit());
        $this->flush();

        $sent = $this->sent(self::CATALOG, 'products');
        self::assertCount(3, $sent);
        // The mock keeps a whole number without its fraction: compared as numbers.
        self::assertEquals([round($before['price'] * 0.75, 2), $before['price']], [$sent[1]['price'], $sent[1]['compare_price']]);
        self::assertEquals($before['price'], $sent[2]['price']);
        self::assertArrayNotHasKey('compare_price', $sent[2]);
        self::assertSame(Keys::sku('', (int) $product->id), $sent[2]['sku'], 'a product without combinations is one row');
        self::assertSame((string) $product->id, $sent[2]['external_id']);
        self::assertSame('standard', $sent[2]['product_type']);
    }

    public function testADeletedProductBecomesARemovalAndTheEngineTombstonesItsRows(): void
    {
        $this->engine->set('next_prefix', 'c3-');
        $this->connect();
        $product = $this->product();
        $idProduct = (int) $product->id;
        $this->commit();
        $this->flush();
        self::assertCount(1, $this->sent(self::CATALOG, 'products'));

        $product->save();
        $product->delete();

        self::assertSame(1, $this->commit(), 'the save before the delete leaves no row of its own');
        self::assertSame([IngestQueue::DOMAIN_CATALOG_REMOVE, (string) $idProduct, EventQueue::STATUS_PENDING], self::summary()[1]);
        $this->flush();

        $requests = $this->engine->requestsTo(self::REMOVE);
        self::assertCount(1, $requests);
        self::assertSame(['product_ids' => ['c3-' . $idProduct]], $requests[0]['body']);
        $row = self::rows(IngestQueue::DOMAIN_CATALOG_REMOVE)[0];
        self::assertSame(EventQueue::STATUS_SENT, $row['status']);
        self::assertSame(['http_status' => 200, 'body' => ['outcome' => 'removed']], json_decode((string) $row['last_response'], true));
        self::assertCount(1, $this->sent(self::CATALOG, 'products'), 'no catalog row follows the removal');
    }

    public function testADeletedCombinationIsSentOnceMoreAsOutOfStock(): void
    {
        $this->connect();
        $product = $this->product();
        $idProduct = (int) $product->id;
        $kept = $this->combination($idProduct);
        $removed = $this->combination($idProduct);
        $this->commit();
        $this->flush();

        (new \Combination($removed))->delete();

        self::assertSame(1, $this->commit());
        $row = self::rows()[1];
        self::assertSame([IngestQueue::DOMAIN_CATALOG, (string) $idProduct, [IngestFlusher::REMOVED => [$removed]]], [$row['domain'], $row['entity_key'], json_decode((string) $row['payload'], true)]);
        $this->flush();

        $last = $this->engine->requestsTo(self::CATALOG);
        $inStock = array_column(end($last)['body']['products'], 'in_stock', 'sku');
        self::assertSame([Keys::sku('', $idProduct, $kept) => true, Keys::sku('', $idProduct, $removed) => false], $inStock);
        self::assertSame([], $this->engine->requestsTo(self::REMOVE), 'a product removal would take the whole product');
    }

    public function testAProductThatIsSwitchedOffKeepsItsRowsAsOutOfStock(): void
    {
        $this->connect();
        $product = $this->product();
        $this->commit();
        $this->flush();

        $product->active = false;
        $product->save();
        $this->commit();
        $this->flush();

        $sent = $this->sent(self::CATALOG, 'products');
        self::assertSame([true, false], array_column($sent, 'in_stock'));
        self::assertSame([], $this->engine->requestsTo(self::REMOVE));
    }

    public function testADraftWithoutANameIsNeitherSentNorKept(): void
    {
        $this->connect();
        $this->product(['name' => '', 'active' => false]);

        self::assertSame(1, $this->commit());
        $this->flush();

        self::assertSame([], self::rows(), 'a product that was never in the catalog leaves nothing behind');
        self::assertSame([], $this->engine->requestsTo(self::CATALOG));
    }

    public function testARowTheEngineRefusesIsParkedAndTheOtherRowsOfTheRequestAreSent(): void
    {
        $this->connect();
        $nameless = $this->product(['name' => '']);
        $this->product();
        $this->commit();

        $this->flush();

        self::assertCount(1, $this->engine->requestsTo(self::CATALOG), 'both rows went in one request');
        [$refused, $sent] = self::rows();
        self::assertSame([(string) $nameless->id, EventQueue::STATUS_FAILED, IngestFlusher::FAILED_ITEM_REFUSED, '1'], [$refused['entity_key'], $refused['status'], $refused['last_error'], $refused['attempts']]);
        $answer = json_decode((string) $refused['last_response'], true);
        self::assertSame([['index' => 0, 'sku' => Keys::sku('', (int) $nameless->id), 'field' => 'name']], $answer['body']['errors'], 'the row keeps the engine\'s verdict on it, without the engine\'s text');
        self::assertSame([EventQueue::STATUS_SENT, null], [$sent['status'], $sent['last_error']]);
        self::assertSame([], json_decode((string) $sent['last_response'], true)['body']['errors'], 'no row carries another row\'s error');
    }

    public function testARequestThatFailsLeavesItsRowsOnTheRetryLadder(): void
    {
        $this->connect();
        $this->product();
        $this->commit();
        $this->engine->fail('catalog', 503, null, 'No customer with email shopper@example.test');

        $this->flush();

        $row = self::rows()[0];
        self::assertSame([EventQueue::STATUS_PENDING, '1', 'internal_error'], [$row['status'], $row['attempts'], $row['last_error']]);
        self::assertNotNull($row['next_retry_at']);
        self::assertSame(['http_status' => 503, 'body' => ['error' => 'internal_error']], json_decode((string) $row['last_response'], true));
        self::assertStringNotContainsString('shopper@example.test', implode('|', array_map('strval', $row)), 'the engine\'s message is never stored');
        self::assertCount(1, $this->engine->requestsTo(self::CATALOG), 'one attempt: the row waits for its retry');
    }

    public function testNothingIsQueuedOrSentWithoutAConnection(): void
    {
        $product = $this->product();
        $product->save();
        $this->setStock((int) $product->id, 0, 3);
        $product->delete();

        self::assertSame(0, $this->commit());
        $this->flush();

        self::assertSame([], self::rows());
        self::assertSame([], $this->engine->requests(), 'no request at all');
    }

    public function testARefusedConnectionSendsNothingAndItsRowsWait(): void
    {
        $connection = $this->connect();
        $this->product();
        $this->commit();
        $this->engine->revokeKeys();

        // The engine answers the batch with 401; a ping confirms that it refuses the connection.
        $this->flush();

        self::assertSame(HealthCheck::REFUSED, $this->connections()->find(0)->health()['status']);
        self::assertNotSame('', $this->connections()->find(0)->refusedAt());
        $row = self::rows()[0];
        self::assertSame([EventQueue::STATUS_PENDING, '0', null, null], [$row['status'], $row['attempts'], $row['last_error'], $row['next_retry_at']], 'the row waits as it was');

        $requests = count($this->engine->requests());
        $this->product();
        self::assertSame(1, $this->commit(), 'a change is still noted');
        $this->flush();

        self::assertCount($requests, $this->engine->requests(), 'a refused connection sends nothing');
        self::assertSame([EventQueue::STATUS_PENDING, EventQueue::STATUS_PENDING], array_column(self::rows(), 'status'));
        self::assertSame($connection->id(), (int) self::rows()[1]['id_connection']);
    }

    public function testTheRowsOfAConnectionThatIsGoneAreClosed(): void
    {
        $connection = $this->connect();
        $this->product();
        $this->commit();
        $this->connections()->delete($connection);

        $this->flush();

        $row = self::rows()[0];
        self::assertSame([EventQueue::STATUS_SKIPPED, IngestQueue::SKIPPED_NO_CONNECTION], [$row['status'], $row['last_error']]);
        self::assertSame([], $this->engine->requestsTo(self::CATALOG));
    }

    /**
     * Adds a simple product with stock, in the home category.
     *
     * @param array<string, mixed> $fields
     */
    private function product(array $fields = []): \Product
    {
        $languages = array_map('intval', \Language::getIDs(false));
        $product = new \Product();
        $product->name = array_fill_keys($languages, (string) ($fields['name'] ?? 'Ingest test product'));
        $product->link_rewrite = array_fill_keys($languages, 'ingest-test-product');
        $product->description_short = array_fill_keys($languages, '<p>A product of the ingest tests.</p>');
        $product->price = (float) ($fields['price'] ?? 10.0);
        $product->id_category_default = (int) \Configuration::get('PS_HOME_CATEGORY');
        $product->active = (bool) ($fields['active'] ?? true);
        $product->id_tax_rules_group = 0;
        self::assertTrue($product->add());
        $this->keep($product);
        \StockAvailable::setQuantity((int) $product->id, 0, 5);

        return $product;
    }

    /**
     * Adds a combination with stock to a product; the answer is its id.
     */
    private function combination(int $idProduct): int
    {
        $combination = new \Combination();
        $combination->id_product = $idProduct;
        $combination->price = 0;
        $combination->minimal_quantity = 1;
        self::assertTrue($combination->add());
        $idAttribute = (int) self::db()->getValue('SELECT `id_attribute` FROM `' . _DB_PREFIX_ . 'attribute` ORDER BY `id_attribute`', false);
        $combination->setAttributes([$idAttribute]);
        \StockAvailable::setQuantity($idProduct, (int) $combination->id, 5);

        return (int) $combination->id;
    }

    private static function specificPrice(int $idProduct, float $reduction): \SpecificPrice
    {
        $specificPrice = new \SpecificPrice();
        $specificPrice->id_product = $idProduct;
        $specificPrice->id_shop = 0;
        $specificPrice->id_currency = 0;
        $specificPrice->id_country = 0;
        $specificPrice->id_group = 0;
        $specificPrice->id_customer = 0;
        $specificPrice->price = -1;
        $specificPrice->from_quantity = 1;
        $specificPrice->reduction = $reduction;
        $specificPrice->reduction_type = 'percentage';
        $specificPrice->from = '0000-00-00 00:00:00';
        $specificPrice->to = '0000-00-00 00:00:00';
        self::assertTrue($specificPrice->add());

        return $specificPrice;
    }

    private function setStock(int $idProduct, int $idAttribute, int $quantity): void
    {
        $key = $idProduct . ':' . $idAttribute;
        if (!isset($this->savedStock[$key]) && $idProduct === self::SHIRT) {
            $this->savedStock[$key] = (int) \StockAvailable::getQuantityAvailableByProduct($idProduct, $idAttribute);
        }
        \StockAvailable::setQuantity($idProduct, $idAttribute, $quantity);
    }

    /**
     * @return list<int> the combinations of a product, lowest id first
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
