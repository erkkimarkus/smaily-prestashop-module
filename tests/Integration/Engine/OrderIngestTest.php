<?php
declare(strict_types=1);

namespace Tests\Integration\Engine;

use PrestaShop\Module\SmailyForPrestaShop\Engine\Ingest\IngestFlusher;
use PrestaShop\Module\SmailyForPrestaShop\Engine\Ingest\IngestQueue;
use PrestaShop\Module\SmailyForPrestaShop\Engine\Payload\Keys;
use PrestaShop\Module\SmailyForPrestaShop\Queue\EventQueue;
use Tests\Integration\Contacts\ContactRows;

require_once __DIR__ . '/IngestTestCase.php';
require_once __DIR__ . '/../Contacts/ContactRows.php';

/**
 * An order reaches the engine as the contract's row (§5): gross amounts in the order's own
 * currency that add up to its total, the engine's status of its state, the keys of ARCHITECTURE
 * 8.2, and the lines that its credit slips took back.
 */
final class OrderIngestTest extends IngestTestCase
{
    use ContactRows;

    private const REC_ID = '3fa85f64-5717-4562-b3fc-2c963f66afa6';

    private int $shop;

    private int $idLang;

    /** @var list<int> */
    private array $orders = [];

    /** @var list<int> */
    private array $slips = [];

    private int $addedCurrency = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->shop = (int) \Configuration::get('PS_SHOP_DEFAULT');
        $this->idLang = (int) \Configuration::get('PS_LANG_DEFAULT');
    }

    protected function tearDown(): void
    {
        if (defined('_PS_ROOT_DIR_')) {
            if ($this->orders !== []) {
                $ids = implode(',', $this->orders);
                self::db()->delete('order_detail', '`id_order` IN (' . $ids . ')');
                self::db()->delete('orders', '`id_order` IN (' . $ids . ')');
                self::db()->delete('smaily_connect_order_attribution', '`id_order` IN (' . $ids . ')');
            }
            if ($this->slips !== []) {
                $ids = implode(',', $this->slips);
                self::db()->delete('order_slip_detail', '`id_order_slip` IN (' . $ids . ')');
                self::db()->delete('order_slip', '`id_order_slip` IN (' . $ids . ')');
            }
            if ($this->addedCurrency > 0) {
                self::db()->delete('currency', '`id_currency` = ' . $this->addedCurrency);
            }
            $this->removeContactRows();
        }
        parent::tearDown();
    }

    public function testAnOrderGoesOutWithGrossAmountsThatAddUpAndTheKeysOfItsLines(): void
    {
        $this->engine->set('next_prefix', 'c2-');
        $this->connect();
        $customer = $this->buyer('Shopper21@Example.test');
        $order = $this->order((int) $customer->id, [
            'total_paid_tax_incl' => 104.1,
            'total_shipping_tax_incl' => 8.4,
            'current_state' => (int) \Configuration::get('PS_OS_PAYMENT'),
            'date_add' => '2026-11-20 14:30:00',
        ], [[1, 1, 1, 23.9], [2, 9, 2, 35.9]]);

        // The checkout fires both in one request (facts file part 2).
        $this->fire('actionValidateOrder', ['order' => $order, 'customer' => $customer]);
        $this->fire('actionOrderStatusPostUpdate', ['id_order' => (int) $order->id]);

        self::assertSame(2, $this->commit(), 'one row for the order, one for its buyer');
        $row = self::rows(IngestQueue::DOMAIN_ORDERS)[0];
        self::assertSame([(string) $order->id, null], [$row['entity_key'], $row['payload']], 'the row holds the order\'s id and nothing of the order');
        $this->flush();

        $requests = $this->engine->requestsTo(self::ORDERS);
        self::assertCount(1, $requests);
        self::assertSame(['orders'], array_keys($requests[0]['body']));
        $sent = $requests[0]['body']['orders'][0];
        self::assertSame(
            ['event_id', 'external_order_id', 'customer_email', 'ordered_at', 'total_amount', 'currency', 'status', 'items'],
            array_keys($sent),
            'the fields of contract §5 that the module sends for an order without a voucher or an attribution'
        );
        self::assertSame('c2-' . $order->id, $sent['external_order_id']);
        self::assertSame('shopper21@example.test', $sent['customer_email']);
        self::assertSame(gmdate('Y-m-d\TH:i:s\Z', (int) strtotime('2026-11-20 14:30:00')), $sent['ordered_at']);
        self::assertSame(104.1, $sent['total_amount']);
        self::assertSame((new \Currency((int) \Configuration::get('PS_CURRENCY_DEFAULT')))->iso_code, $sent['currency']);
        self::assertSame('processing', $sent['status']);
        self::assertEquals([
            ['sku' => 'c2-' . Keys::PLATFORM_NAMESPACE . '1-1', 'qty' => 1, 'unit_price' => 23.9, 'line_total' => 23.9],
            ['sku' => 'c2-' . Keys::PLATFORM_NAMESPACE . '2-9', 'qty' => 2, 'unit_price' => 35.9, 'line_total' => 71.8],
        ], $sent['items']);
        self::assertEqualsWithDelta($sent['total_amount'], array_sum(array_column($sent['items'], 'line_total')) + 8.4, 0.01, 'Σ line_total + shipping ≈ total_amount');
        self::assertSame(EventQueue::STATUS_SENT, self::rows(IngestQueue::DOMAIN_ORDERS)[0]['status']);
    }

    public function testAVoucherIsSharedOverTheLinesSoThatTheAmountsStillAddUp(): void
    {
        $this->connect();
        $customer = $this->buyer('shopper22@example.test');
        $order = $this->order((int) $customer->id, [
            'total_paid_tax_incl' => 94.1,
            'total_shipping_tax_incl' => 8.4,
            'total_discounts_tax_incl' => 10.0,
        ], [[1, 1, 1, 23.9], [2, 9, 2, 35.9]]);

        $this->fire('actionOrderEdited', ['order' => $order]);
        $this->commit();
        $this->flush();

        $sent = $this->sent(self::ORDERS, 'orders')[0];
        self::assertEquals(10.0, $sent['discount_amount']);
        self::assertEqualsWithDelta(10.0, array_sum(array_column($sent['items'], 'discount_amount')), 0.0001, 'the voucher is shared out in full');
        self::assertEqualsWithDelta(94.1, array_sum(array_column($sent['items'], 'line_total')) + 8.4, 0.01, 'Σ line_total + shipping ≈ total_amount');
        self::assertEquals([23.9, 35.9], array_column($sent['items'], 'unit_price'), 'a unit price is the price before the voucher');
    }

    public function testAnOrderInASecondCurrencyCarriesThatCurrency(): void
    {
        $this->connect();
        $customer = $this->buyer('shopper23@example.test');
        $currency = $this->secondCurrency();
        $order = $this->order((int) $customer->id, [
            'id_currency' => $currency['id'],
            'total_paid_tax_incl' => 123.45,
            'total_shipping_tax_incl' => 9.25,
        ], [[1, 1, 3, 38.066667]]);

        $this->fire('actionValidateOrder', ['order' => $order, 'customer' => $customer]);
        $this->commit();
        $this->flush();

        $sent = $this->sent(self::ORDERS, 'orders')[0];
        self::assertSame($currency['iso'], $sent['currency']);
        self::assertNotSame((new \Currency((int) \Configuration::get('PS_CURRENCY_DEFAULT')))->iso_code, $sent['currency']);
        self::assertSame(123.45, $sent['total_amount'], 'the amount as charged, not converted');
        self::assertEqualsWithDelta(123.45, array_sum(array_column($sent['items'], 'line_total')) + 9.25, 0.01);
    }

    /**
     * @dataProvider states
     */
    public function testTheStatusFollowsTheOrdersState(string $state, string $status): void
    {
        $this->connect();
        $customer = $this->buyer('shopper24@example.test');
        $order = $this->order((int) $customer->id, [], [[1, 1, 1, 23.9]]);
        self::db()->update('orders', ['current_state' => (int) \Configuration::get($state)], '`id_order` = ' . (int) $order->id);

        $this->fire('actionOrderStatusPostUpdate', ['id_order' => (int) $order->id]);
        self::assertSame(1, $this->commit());
        $this->flush();

        self::assertSame($status, $this->sent(self::ORDERS, 'orders')[0]['status']);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function states(): iterable
    {
        yield 'a full refund' => ['PS_OS_REFUND', 'refunded'];
        yield 'canceled' => ['PS_OS_CANCELED', 'cancelled'];
        yield 'payment error' => ['PS_OS_ERROR', 'cancelled'];
        yield 'shipped' => ['PS_OS_SHIPPING', 'completed'];
        yield 'delivered' => ['PS_OS_DELIVERED', 'completed'];
        yield 'payment accepted' => ['PS_OS_PAYMENT', 'processing'];
        yield 'awaiting payment' => ['PS_OS_BANKWIRE', 'processing'];
        yield 'being prepared' => ['PS_OS_PREPARATION', 'processing'];
    }

    public function testALineTakenBackInFullCarriesItsReturnOnEveryLaterSend(): void
    {
        $this->connect();
        $customer = $this->buyer('shopper25@example.test');
        $order = $this->order((int) $customer->id, ['total_paid_tax_incl' => 95.7], [[1, 1, 1, 23.9], [2, 9, 2, 35.9]]);
        [$single, $double] = $this->lines((int) $order->id);

        // A partial refund: one of the two pieces of the second line, and the whole first line.
        $this->slip((int) $order->id, '2026-11-21 09:00:00', [$single => 1, $double => 1]);
        $this->fire('actionOrderSlipAdd', ['order' => $order, 'productList' => [], 'qtyList' => []]);
        $this->commit();
        $this->flush();
        // The second piece follows; then the order only changes its status.
        $this->slip((int) $order->id, '2026-11-21 10:30:00', [$double => 1]);
        $this->fire('actionOrderSlipAdd', ['order' => $order, 'productList' => [], 'qtyList' => []]);
        $this->commit();
        $this->flush();
        $this->fire('actionOrderStatusPostUpdate', ['id_order' => (int) $order->id]);
        $this->commit();
        $this->flush();

        $sent = $this->sent(self::ORDERS, 'orders');
        self::assertCount(3, $sent);
        $first = gmdate('Y-m-d\TH:i:s\Z', (int) strtotime('2026-11-21 09:00:00'));
        $second = gmdate('Y-m-d\TH:i:s\Z', (int) strtotime('2026-11-21 10:30:00'));
        self::assertSame([$first, null], [$sent[0]['items'][0]['returned_at'], $sent[0]['items'][1]['returned_at'] ?? null], 'a line refunded in part stays kept');
        self::assertSame([$first, $second], array_column($sent[1]['items'], 'returned_at'));
        self::assertSame([$first, $second], array_column($sent[2]['items'], 'returned_at'), 'a later send of the order does not lose the return');
        self::assertSame(['sku', 'qty', 'unit_price', 'line_total', 'returned_at'], array_keys($sent[2]['items'][0]), 'no return reason is sent');
    }

    public function testTheAttributionOfTheOrderIsSentOnlyInTheContractsForm(): void
    {
        $this->connect();
        $customer = $this->buyer('shopper26@example.test');
        $valid = $this->order((int) $customer->id, [], [[1, 1, 1, 23.9]]);
        $malformed = $this->order((int) $customer->id, [], [[1, 1, 1, 23.9]]);
        self::db()->insert('smaily_connect_order_attribution', ['id_order' => (int) $valid->id, 'rec_id' => self::REC_ID, 'context' => 'storefront', 'visitor_token' => 'vs_AbCdEfGhIjKlMnOpQrStUv', 'created_at' => gmdate('Y-m-d H:i:s')]);
        self::db()->insert('smaily_connect_order_attribution', ['id_order' => (int) $malformed->id, 'rec_id' => 'rec_abc123', 'context' => 'a context with spaces', 'visitor_token' => 'vs_short', 'created_at' => gmdate('Y-m-d H:i:s')]);

        $this->fire('actionOrderEdited', ['order' => $valid]);
        $this->fire('actionOrderEdited', ['order' => $malformed]);
        $this->commit();
        $this->flush();

        [$first, $second] = $this->sent(self::ORDERS, 'orders');
        self::assertSame(
            [self::REC_ID, 'vs_AbCdEfGhIjKlMnOpQrStUv', 'storefront'],
            [$first['smaily_rec_id'], $first['smaily_visitor_token'], $first['smaily_rec_ctx']]
        );
        self::assertSame([], array_intersect(['smaily_rec_id', 'smaily_visitor_token', 'smaily_rec_ctx', 'session_id'], array_keys($second)), 'a malformed value is left out, and the order still goes');
        self::assertSame([EventQueue::STATUS_SENT, EventQueue::STATUS_SENT], array_column(self::rows(), 'status'));
    }

    public function testALineKeepsTheKeyOfAProductThatWasDeletedSince(): void
    {
        $this->connect();
        $customer = $this->buyer('shopper27@example.test');
        $order = $this->order((int) $customer->id, [], [[987654, 0, 1, 12.5]]);

        $this->fire('actionOrderEdited', ['order' => $order]);
        $this->commit();
        $this->flush();

        self::assertSame(Keys::sku('', 987654), $this->sent(self::ORDERS, 'orders')[0]['items'][0]['sku']);
    }

    public function testAnOrderThatIsGoneOrWhoseCustomerWasDeletedIsNotSent(): void
    {
        $this->connect();
        $customer = $this->buyer('shopper28@example.test');
        $deleted = $this->buyer('shopper29@example.test');
        $gone = $this->order((int) $customer->id, [], [[1, 1, 1, 23.9]]);
        $orphan = $this->order((int) $deleted->id, [], [[1, 1, 1, 23.9]]);
        $this->fire('actionOrderEdited', ['order' => $gone]);
        $this->fire('actionOrderEdited', ['order' => $orphan]);
        self::assertSame(2, $this->commit());
        self::db()->delete('orders', '`id_order` = ' . (int) $gone->id);
        self::db()->update('customer', ['deleted' => 1], '`id_customer` = ' . (int) $deleted->id);

        $this->flush();

        self::assertSame(
            [[EventQueue::STATUS_SKIPPED, IngestFlusher::SKIPPED_ORDER_GONE], [EventQueue::STATUS_SKIPPED, IngestFlusher::SKIPPED_ORDER_GONE]],
            array_map(static fn (array $row): array => [$row['status'], $row['last_error']], self::rows())
        );
        self::assertSame([], $this->engine->requestsTo(self::ORDERS));
    }

    public function testNothingIsQueuedWithoutAConnection(): void
    {
        $customer = $this->buyer('shopper30@example.test');
        $order = $this->order((int) $customer->id, [], [[1, 1, 1, 23.9]]);

        $this->fire('actionValidateOrder', ['order' => $order, 'customer' => $customer]);
        $this->fire('actionOrderStatusPostUpdate', ['id_order' => (int) $order->id]);
        $this->fire('actionOrderEdited', ['order' => $order]);
        $this->fire('actionOrderSlipAdd', ['order' => $order]);

        self::assertSame(0, $this->commit());
        $this->flush();
        self::assertSame([], $this->engine->requests());
    }

    private function buyer(string $email): \Customer
    {
        return new \Customer($this->addCustomer($this->shop, $email, false, $this->idLang, [
            'passwd' => pSQL(password_hash('not-a-real-password', PASSWORD_BCRYPT)),
            'birthday' => '0000-00-00',
            'secure_key' => md5(uniqid('', true)),
        ]));
    }

    /**
     * An order with its lines.
     *
     * @param array<string, mixed> $columns more `orders` columns
     * @param list<array{0: int, 1: int, 2: int, 3: float}> $lines product, combination, quantity, unit price with tax
     */
    private function order(int $idCustomer, array $columns, array $lines): \Order
    {
        $placedAt = (string) ($columns['date_add'] ?? date('Y-m-d H:i:s'));
        self::db()->insert('orders', $columns + [
            'reference' => 'TESTORDER',
            'id_shop_group' => (int) \Shop::getGroupFromShop($this->shop),
            'id_shop' => $this->shop,
            'id_carrier' => 0,
            'id_lang' => $this->idLang,
            'id_customer' => $idCustomer,
            'id_cart' => 0,
            'id_currency' => (int) \Configuration::get('PS_CURRENCY_DEFAULT'),
            'id_address_delivery' => 0,
            'id_address_invoice' => 0,
            'current_state' => 0,
            'payment' => 'test',
            'total_paid' => 0,
            'total_paid_tax_incl' => array_sum(array_map(static fn (array $line): float => round($line[2] * $line[3], 2), $lines)),
            'invoice_date' => $placedAt,
            'delivery_date' => $placedAt,
            'date_add' => $placedAt,
            'date_upd' => $placedAt,
        ]);
        $id = (int) self::db()->Insert_ID();
        $this->orders[] = $id;
        foreach ($lines as [$idProduct, $idAttribute, $quantity, $unitPrice]) {
            self::db()->insert('order_detail', [
                'id_order' => $id,
                'id_shop' => $this->shop,
                'product_id' => $idProduct,
                'product_attribute_id' => $idAttribute,
                'product_name' => 'Test line',
                'product_quantity' => $quantity,
                'product_price' => $unitPrice,
                'unit_price_tax_incl' => $unitPrice,
                'unit_price_tax_excl' => $unitPrice,
                'total_price_tax_incl' => round($quantity * $unitPrice, 2),
                'total_price_tax_excl' => round($quantity * $unitPrice, 2),
            ]);
        }

        return new \Order($id);
    }

    /**
     * @return list<int> the ids of the order's lines, in their order
     */
    private function lines(int $idOrder): array
    {
        return array_map('intval', array_column(self::db()->executeS(
            'SELECT `id_order_detail` FROM `' . _DB_PREFIX_ . 'order_detail` WHERE `id_order` = ' . $idOrder . ' ORDER BY `id_order_detail`',
            true,
            false
        ), 'id_order_detail'));
    }

    /**
     * A credit slip of the order.
     *
     * @param array<int, int> $quantities the quantity taken back, by order line
     */
    private function slip(int $idOrder, string $at, array $quantities): void
    {
        self::db()->insert('order_slip', [
            'conversion_rate' => 1,
            'id_customer' => 0,
            'id_order' => $idOrder,
            'date_add' => $at,
            'date_upd' => $at,
        ]);
        $idSlip = (int) self::db()->Insert_ID();
        $this->slips[] = $idSlip;
        foreach ($quantities as $idLine => $quantity) {
            self::db()->insert('order_slip_detail', ['id_order_slip' => $idSlip, 'id_order_detail' => $idLine, 'product_quantity' => $quantity]);
        }
    }

    /**
     * A currency other than the shop's default: one that the shop has, or a row added for the test.
     *
     * @return array{id: int, iso: string}
     */
    private function secondCurrency(): array
    {
        $default = (int) \Configuration::get('PS_CURRENCY_DEFAULT');
        $existing = self::db()->getRow('SELECT `id_currency`, `iso_code` FROM `' . _DB_PREFIX_ . 'currency` WHERE `id_currency` <> ' . $default . ' AND `deleted` = 0', false);
        if (is_array($existing)) {
            return ['id' => (int) $existing['id_currency'], 'iso' => (string) $existing['iso_code']];
        }
        $iso = (new \Currency($default))->iso_code === 'USD' ? 'SEK' : 'USD';
        self::db()->insert('currency', ['iso_code' => $iso, 'conversion_rate' => 1.1, 'deleted' => 0, 'active' => 1]);
        $this->addedCurrency = (int) self::db()->Insert_ID();

        return ['id' => $this->addedCurrency, 'iso' => $iso];
    }
}
