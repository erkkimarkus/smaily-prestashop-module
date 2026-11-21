<?php
declare(strict_types=1);

namespace Tests\Integration\Engine;

use PrestaShop\Module\SmailyForPrestaShop\Engine\Ingest\IngestQueue;
use PrestaShop\Module\SmailyForPrestaShop\Engine\Payload\Keys;
use PrestaShop\Module\SmailyForPrestaShop\Scope\StoreLayout;
use Tests\Integration\Contacts\ContactRows;
use Tests\Integration\Migration\LegacyStore;
use Tests\Integration\MultistoreShop;

require_once __DIR__ . '/IngestTestCase.php';
require_once __DIR__ . '/../MultistoreShop.php';
require_once __DIR__ . '/../Contacts/ContactRows.php';
require_once __DIR__ . '/../Migration/LegacyStore.php';

/**
 * A multishop store (ARCHITECTURE 5.3): shops that share one setup share one connection, which
 * names each shop as a website; shops that are set up on their own each send their own products
 * and orders through their own connection, under its prefix.
 */
final class MultishopIngestTest extends IngestTestCase
{
    use MultistoreShop;
    use ContactRows;

    /** A demo product with combinations, and one without. */
    private const SHIRT = 1;

    private const MUG = 6;

    /** @var list<int> */
    private array $orders = [];

    protected function tearDown(): void
    {
        if (defined('_PS_ROOT_DIR_')) {
            if ($this->orders !== []) {
                self::db()->delete('order_detail', '`id_order` IN (' . implode(',', $this->orders) . ')');
                self::db()->delete('orders', '`id_order` IN (' . implode(',', $this->orders) . ')');
            }
            $this->removeContactRows();
            if ($this->savedContext > 0) {
                $this->closeMultistore();
            }
        }
        parent::tearDown();
    }

    public function testShopsThatShareOneSetupShareOneConnectionWhichNamesEachShop(): void
    {
        $this->openMultistore();
        $first = $this->defaultShopId();
        $second = $this->addShop((int) \Shop::getGroupFromShop($first));
        $this->shareProducts([self::SHIRT], $second);
        $this->engine->set('next_prefix', 'c2-');
        $connection = $this->connect();
        self::assertSame(0, $connection->idShop(), 'the installation-wide connection');

        (new \Product(self::SHIRT))->save();
        (new \Product(self::MUG))->save();
        self::assertSame(2, $this->commit(), 'one row per product: both shops send through the one connection');
        $customer = new \Customer($this->addCustomer($second, 'shopper32@example.test', false, (int) \Configuration::get('PS_LANG_DEFAULT')));
        $order = $this->order((int) $customer->id, $second);
        $this->fire('actionValidateOrder', ['order' => $order, 'customer' => $customer]);
        $this->commit();
        $this->flush();

        $products = array_column($this->sent(self::CATALOG, 'products'), null, 'sku');
        $shirt = $products['c2-' . Keys::PLATFORM_NAMESPACE . self::SHIRT . '-' . self::combinations(self::SHIRT)[0]];
        self::assertSame([(string) $first, (string) $second], array_map('strval', array_keys($shirt['websites'])), 'the shirt sells in both shops');
        self::assertStringContainsString('shop' . $second . '.example.invalid', $shirt['websites'][(string) $second]['product_url'], 'each shop with its own link');
        self::assertSame($shirt['product_url'], $shirt['websites'][(string) $first]['product_url']);
        $mug = $products['c2-' . Keys::PLATFORM_NAMESPACE . self::MUG];
        self::assertSame([(string) $first], array_map('strval', array_keys($mug['websites'])), 'the mug sells in the first shop only');

        self::assertSame((string) $second, $this->sent(self::CUSTOMERS, 'customers')[0]['website']);
        $sent = $this->sent(self::ORDERS, 'orders')[0];
        self::assertSame(['c2-' . $order->id, (string) $second], [$sent['external_order_id'], $sent['website']]);
        self::assertSame([(string) $connection->id()], array_values(array_unique(array_column(self::rows(), 'id_connection'))));
    }

    public function testEachShopOfSeparateStoresSendsThroughItsOwnConnectionUnderItsOwnPrefix(): void
    {
        $this->openMultistore();
        $first = $this->defaultShopId();
        $second = $this->addShop((int) \Shop::getGroupFromShop($first));
        $this->shareProducts([self::SHIRT], $second);
        \Configuration::updateGlobalValue(StoreLayout::SEPARATE_STORES, '1');
        LegacyStore::reloadConfiguration();
        $this->engine->set('next_prefix', 'c2-');
        $one = $this->connect($first);
        $this->engine->set('next_prefix', 'c3-');
        $two = $this->connect($second);

        (new \Product(self::SHIRT))->save();
        (new \Product(self::MUG))->save();
        self::assertSame(3, $this->commit(), 'the shirt for both connections, the mug for the first shop\'s alone');
        $customer = new \Customer($this->addCustomer($second, 'shopper33@example.test', false, (int) \Configuration::get('PS_LANG_DEFAULT')));
        $order = $this->order((int) $customer->id, $second);
        $this->fire('actionValidateOrder', ['order' => $order, 'customer' => $customer]);
        $this->commit();
        $this->flush();

        $byKey = [];
        foreach ($this->engine->requestsTo(self::CATALOG) as $request) {
            foreach ($request['body']['products'] as $product) {
                $byKey[$request['authorization']][] = $product;
            }
        }
        $firstSkus = array_column($byKey['Bearer ' . $one->apiKey()], 'sku');
        $secondSkus = array_column($byKey['Bearer ' . $two->apiKey()], 'sku');
        self::assertCount(9, $firstSkus);
        self::assertSame([], array_filter($firstSkus, static fn (string $sku): bool => strpos($sku, 'c2-') !== 0), 'the first shop\'s products go under its prefix');
        self::assertCount(8, $secondSkus, 'the second shop sends the shirt, which it holds, and not the mug');
        self::assertSame([], array_filter($secondSkus, static fn (string $sku): bool => strpos($sku, 'c3-') !== 0));
        self::assertSame('c3-' . self::SHIRT, $byKey['Bearer ' . $two->apiKey()][0]['tags']['product_id']);
        self::assertArrayNotHasKey('websites', $byKey['Bearer ' . $two->apiKey()][0], 'a connection of one shop names no website');
        self::assertStringContainsString('shop' . $second . '.example.invalid', $byKey['Bearer ' . $two->apiKey()][0]['product_url']);

        $orders = $this->engine->requestsTo(self::ORDERS);
        self::assertSame(['Bearer ' . $two->apiKey()], array_column($orders, 'authorization'), 'an order goes through the connection of its shop');
        self::assertSame('c3-' . $order->id, $orders[0]['body']['orders'][0]['external_order_id']);
        self::assertArrayNotHasKey('website', $orders[0]['body']['orders'][0]);
        $ordersRow = self::rows(IngestQueue::DOMAIN_ORDERS)[0];
        self::assertSame((string) $two->id(), $ordersRow['id_connection']);
    }

    public function testAProductDeletedFromOneShopIsRemovedForThatShopsConnectionOnly(): void
    {
        $this->openMultistore();
        $first = $this->defaultShopId();
        $second = $this->addShop((int) \Shop::getGroupFromShop($first));
        \Configuration::updateGlobalValue(StoreLayout::SEPARATE_STORES, '1');
        LegacyStore::reloadConfiguration();
        $languages = array_map('intval', \Language::getIDs(false));
        $product = new \Product();
        $product->name = array_fill_keys($languages, 'Ingest test product');
        $product->link_rewrite = array_fill_keys($languages, 'ingest-test-product');
        $product->price = 10.0;
        $product->id_category_default = (int) \Configuration::get('PS_HOME_CATEGORY');
        self::assertTrue($product->add());
        $this->keep($product);
        $this->shareProducts([(int) $product->id], $second);
        $this->engine->set('next_prefix', 'c2-');
        $one = $this->connect($first);
        $this->engine->set('next_prefix', 'c3-');
        $two = $this->connect($second);
        $this->commit();

        // PrestaShop takes the product out of the shops of its shop context.
        \Shop::setContext(\Shop::CONTEXT_SHOP, $second);
        self::db()->delete('product_shop', '`id_product` = ' . (int) $product->id . ' AND `id_shop` = ' . $second);
        \Hook::exec('actionObjectProductDeleteAfter', ['object' => $product]);
        \Shop::setContext(\Shop::CONTEXT_ALL);

        self::assertSame(1, $this->commit());
        $row = self::rows(IngestQueue::DOMAIN_CATALOG_REMOVE)[0];
        self::assertSame([(string) $two->id(), (string) $product->id], [$row['id_connection'], $row['entity_key']], 'the removal belongs to the second shop\'s connection');
        $this->flush();
        $removals = $this->engine->requestsTo(self::REMOVE);
        self::assertSame(['Bearer ' . $two->apiKey()], array_column($removals, 'authorization'));
        self::assertSame(['product_ids' => ['c3-' . $product->id]], $removals[0]['body']);
        self::assertNotSame($one->apiKey(), $two->apiKey());
    }

    private function order(int $idCustomer, int $idShop): \Order
    {
        $now = date('Y-m-d H:i:s');
        self::db()->insert('orders', [
            'reference' => 'TESTORDER',
            'id_shop_group' => (int) \Shop::getGroupFromShop($idShop),
            'id_shop' => $idShop,
            'id_carrier' => 0,
            'id_lang' => (int) \Configuration::get('PS_LANG_DEFAULT'),
            'id_customer' => $idCustomer,
            'id_cart' => 0,
            'id_currency' => (int) \Configuration::get('PS_CURRENCY_DEFAULT'),
            'id_address_delivery' => 0,
            'id_address_invoice' => 0,
            'current_state' => 0,
            'payment' => 'test',
            'total_paid' => 23.9,
            'total_paid_tax_incl' => 23.9,
            'invoice_date' => $now,
            'delivery_date' => $now,
            'date_add' => $now,
            'date_upd' => $now,
        ]);
        $id = (int) self::db()->Insert_ID();
        $this->orders[] = $id;
        self::db()->insert('order_detail', [
            'id_order' => $id,
            'id_shop' => $idShop,
            'product_id' => self::SHIRT,
            'product_attribute_id' => 1,
            'product_name' => 'Test line',
            'product_quantity' => 1,
            'product_price' => 23.9,
            'unit_price_tax_incl' => 23.9,
            'total_price_tax_incl' => 23.9,
        ]);

        return new \Order($id);
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
