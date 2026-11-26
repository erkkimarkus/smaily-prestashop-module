<?php
declare(strict_types=1);

namespace Tests\Integration\Engine;

use PrestaShop\Module\SmailyForPrestaShop\Contacts\Import\JobStore;
use PrestaShop\Module\SmailyForPrestaShop\Engine\Client;
use PrestaShop\Module\SmailyForPrestaShop\Engine\Connection;
use PrestaShop\Module\SmailyForPrestaShop\Engine\History\HistoryImport;
use PrestaShop\Module\SmailyForPrestaShop\Engine\History\HistoryJobs;
use PrestaShop\Module\SmailyForPrestaShop\Engine\History\HistoryProgress;
use PrestaShop\Module\SmailyForPrestaShop\Engine\Ingest\CustomerScope;
use PrestaShop\Module\SmailyForPrestaShop\Engine\Ingest\IngestQueue;
use PrestaShop\Module\SmailyForPrestaShop\Scheduler\Deadline;
use Tests\Integration\Contacts\ContactRows;

require_once __DIR__ . '/IngestTestCase.php';
require_once __DIR__ . '/../Contacts/ContactRows.php';

/**
 * The history import of Story 5.3 against the engine mock: the three imports run one after
 * another through the ingest queue, the orders newest first inside the window, the progress
 * calls of contract §25, "Hold back", "Run again", a cancel and a stalled import.
 */
final class HistoryImportTest extends IngestTestCase
{
    use ContactRows;

    private const PROGRESS = '/api/v1/ingest/history/progress';

    private int $shop;

    private int $now;

    /** @var list<int> */
    private array $orders = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->shop = (int) \Configuration::get('PS_SHOP_DEFAULT');
        $this->now = time();
        self::db()->delete(JobStore::TABLE, '`type` LIKE \'engine\\_%\'');
    }

    protected function tearDown(): void
    {
        if (defined('_PS_ROOT_DIR_')) {
            if ($this->orders !== []) {
                $ids = implode(',', $this->orders);
                self::db()->delete('order_detail', '`id_order` IN (' . $ids . ')');
                self::db()->delete('orders', '`id_order` IN (' . $ids . ')');
            }
            $this->removeContactRows();
            self::db()->delete(JobStore::TABLE, '`type` LIKE \'engine\\_%\'');
        }
        parent::tearDown();
    }

    public function testTheImportsRunOneAfterAnotherAndTheOrdersGoNewestFirstInsideTheWindow(): void
    {
        $connection = $this->connect();
        $buyer = $this->addCustomer($this->shop, 'history1@example.test', false, (int) \Configuration::get('PS_LANG_DEFAULT'));
        $recent = $this->order($buyer, '-2 months');
        $newest = $this->order($buyer, '-1 month');
        $older = $this->order($buyer, '-20 months');
        $tooOld = $this->order($buyer, '-61 months');
        $this->jobs()->startAll($connection);

        self::assertSame([], $this->engine->requestsTo(self::CATALOG), 'nothing goes before the first background run');

        $this->tick();
        $progress = $this->jobs()->progress($connection);
        self::assertSame(['done', 'queued', 'queued'], array_column($progress, 'status'), 'the catalog first; the other two wait');
        self::assertGreaterThan(0, $progress['catalog']['total']);
        self::assertSame($progress['catalog']['total'], $progress['catalog']['sent'] + $progress['catalog']['failed'] + $this->state($connection, 'catalog')['skipped']);
        $calls = $this->calls();
        self::assertSame('started', $calls[0]['phase'], 'the engine hears "started" before a row');
        self::assertSame($this->position(self::PROGRESS), 0, 'the progress call is the first request after the exchange');

        $this->tick();
        self::assertSame(['done', 'done', 'queued'], array_column($this->jobs()->progress($connection), 'status'));
        self::assertContains('history1@example.test', array_column($this->sent(self::CUSTOMERS, 'customers'), 'email'), 'a buyer inside the horizon is in scope');

        $this->tick();
        $progress = $this->jobs()->progress($connection);
        self::assertSame('done', $progress['orders']['status']);
        $sent = array_column($this->sent(self::ORDERS, 'orders'), 'external_order_id');
        $mine = array_values(array_intersect($sent, array_map('strval', [$newest, $recent, $older, $tooOld])));
        self::assertSame([(string) $newest, (string) $recent, (string) $older], $mine, 'newest first, and no order older than the horizon');
        self::assertSame(count($sent), $progress['orders']['sent']);

        $calls = $this->calls();
        self::assertSame(['started', 'complete'], array_values(array_unique(array_column($calls, 'phase'))), 'a small store goes from started to complete');
        $last = end($calls);
        self::assertSame(1, count(array_unique(array_column($calls, 'import_id'))));
        self::assertSame(date('Y-m-d', (int) strtotime('-60 months', $this->now)), $last['horizon_start']);
        self::assertSame(['expected' => $progress['catalog']['total'], 'sent' => $progress['catalog']['sent']], $last['entities']['catalog']);
        self::assertSame($progress['orders']['sent'], $last['entities']['orders']['sent']);
        self::assertArrayNotHasKey('stop_reason', $last);

        $before = count($calls);
        $this->tick();
        self::assertCount($before, $this->calls(), 'nothing is reported after "complete"');
    }

    public function testTheStartPartIsReportedBeforeTheOlderOrders(): void
    {
        $connection = $this->connect();
        $buyer = $this->addCustomer($this->shop, 'history2@example.test', false, (int) \Configuration::get('PS_LANG_DEFAULT'));
        $this->order($buyer, '-1 month');
        $this->order($buyer, '-30 months');
        $this->jobs()->start($connection, HistoryJobs::ORDERS);

        // The run's time ends once the start part is queued: the older orders wait for the next run.
        $asked = 0;
        $this->import()->run(new Deadline(3.0, static function () use (&$asked): float {
            return (float) ++$asked;
        }));
        self::assertSame('older', $this->state($connection, 'orders')['phase']);
        self::assertSame(['started'], array_column($this->calls(), 'phase'));
        $this->flush();
        $this->import()->run($this->deadline());

        self::assertSame(['started', 'start_part_complete'], array_column($this->calls(), 'phase'), 'the start part is in while the older orders wait');
        self::assertSame('running', $this->jobs()->progress($connection)['orders']['status']);
        $this->flush();
        $this->import()->run($this->deadline());
        self::assertSame(['started', 'start_part_complete', 'complete'], array_column($this->calls(), 'phase'));
        self::assertSame('done', $this->jobs()->progress($connection)['orders']['status']);
    }

    public function testAnImportOfManyPagesStopsAtItsBacklogAndCountsEveryOrder(): void
    {
        $connection = $this->connect();
        $buyer = $this->addCustomer($this->shop, 'history3@example.test', false, (int) \Configuration::get('PS_LANG_DEFAULT'));
        $this->orders($buyer, '-1 month', 700);
        $this->orders($buyer, '-30 months', 500);
        $this->jobs()->start($connection, HistoryJobs::ORDERS);

        // The first run: the start part in pages, then older orders until BACKLOG markers wait.
        $this->import()->run($this->deadline());
        $state = $this->state($connection, 'orders');
        $start = $this->ordersBetween($state['start_from'], $state['until']);
        $total = $start + $this->ordersBetween($state['horizon'], $state['start_from']);
        self::assertGreaterThan(HistoryImport::BACKLOG - 2 * HistoryImport::PAGE, $start, 'the start part and one older page stay under the backlog');
        self::assertLessThanOrEqual(HistoryImport::BACKLOG - HistoryImport::PAGE, $start, 'and a second older page reaches it');
        self::assertGreaterThan($start + 2 * HistoryImport::PAGE, $total, 'orders are left for the next run');
        $queued = $start + 2 * HistoryImport::PAGE;
        self::assertSame(
            [$total, $queued, 0, 0, 0, 'older', false],
            [$state['total'], $state['queued'], $state['sent'], $state['failed'], $state['skipped'], $state['phase'], !empty($state['queued_all'])]
        );
        self::assertSame($queued, $state['last_row'] - $state['first_row'] + 1);
        self::assertSame(array_fill(0, $queued, 'pending'), array_column(self::rows('orders'), 'status'));
        self::assertSame(
            ['status' => 'running', 'active' => true, 'total' => $total, 'sent' => 0, 'failed' => 0, 'phase' => 'older'],
            array_intersect_key($this->jobs()->progress($connection)['orders'], array_flip(['status', 'active', 'total', 'sent', 'failed', 'phase']))
        );

        // The second run: the rest is queued, and what the flush sent is counted.
        $this->flush();
        $this->import()->run($this->deadline());
        $state = $this->state($connection, 'orders');
        self::assertSame(
            [$total, $total, $queued, 0, 0, true],
            [$state['total'], $state['queued'], $state['sent'], $state['failed'], $state['skipped'], !empty($state['queued_all'])]
        );
        self::assertSame(
            ['status' => 'running', 'active' => true, 'total' => $total, 'sent' => $queued, 'failed' => 0, 'phase' => 'older'],
            array_intersect_key($this->jobs()->progress($connection)['orders'], array_flip(['status', 'active', 'total', 'sent', 'failed', 'phase']))
        );

        // The third run: every marker is delivered, and the import ends.
        $this->flush();
        $this->import()->run($this->deadline());
        $state = $this->state($connection, 'orders');
        self::assertSame([$total, $total, 0, 0], [$state['queued'], $state['sent'], $state['failed'], $state['skipped']]);
        self::assertSame(
            ['status' => 'done', 'active' => false, 'total' => $total, 'sent' => $total, 'failed' => 0],
            array_intersect_key($this->jobs()->progress($connection)['orders'], array_flip(['status', 'active', 'total', 'sent', 'failed']))
        );
        self::assertCount($total, $this->sent(self::ORDERS, 'orders'));

        $calls = $this->calls();
        self::assertSame(['started', 'start_part_complete', 'complete'], array_column($calls, 'phase'));
        self::assertSame(
            [[0, $total], [$queued, $total], [$total, $total]],
            array_map(static fn (array $call): array => [$call['entities']['orders']['sent'], $call['entities']['orders']['expected']], $calls)
        );

        $this->import()->run($this->deadline());
        self::assertCount(3, $this->calls(), 'nothing is reported after "complete"');
    }

    public function testHoldBackBeforeTheFirstRunSendsNothing(): void
    {
        $connection = $this->connect();
        $this->jobs()->startAll($connection);
        $this->jobs()->cancel($connection, HistoryJobs::CATALOG);

        $this->tick();

        self::assertSame(['cancelled', 'cancelled', 'cancelled'], array_column($this->jobs()->progress($connection), 'status'));
        self::assertSame([], self::rows(), 'no marker was written');
        self::assertCount(1, $this->engine->requests(), 'the exchange is the only request: no row and no progress call');
    }

    public function testRunAgainStartsANewImportFromTheBeginning(): void
    {
        $connection = $this->connect();
        $this->jobs()->start($connection, HistoryJobs::ORDERS);
        $this->tick();
        $first = $this->jobs()->latest($connection->id(), HistoryJobs::ORDERS);
        self::assertSame('done', $first['status']);

        $this->jobs()->start($connection, HistoryJobs::ORDERS);
        $this->tick();
        $second = $this->jobs()->latest($connection->id(), HistoryJobs::ORDERS);

        self::assertNotSame($first['id'], $second['id']);
        self::assertNotSame($first['import_id'], $second['import_id'], 'a new import_id');
        self::assertSame($first['state']['queued'], $second['state']['queued'], 'every order again');
        self::assertSame([$first['import_id'], $second['import_id']], array_values(array_unique(array_column($this->calls(), 'import_id'))));
    }

    public function testACancelledImportIsReportedOnceWithItsStopReason(): void
    {
        $connection = $this->connect();
        $this->jobs()->start($connection, HistoryJobs::ORDERS);
        $this->import()->run($this->deadline());
        $this->jobs()->cancel($connection, HistoryJobs::ORDERS);

        $this->import()->run($this->deadline());
        $this->import()->run($this->deadline());

        $calls = $this->calls();
        self::assertSame(['started', 'started'], array_column($calls, 'phase'));
        self::assertSame('cancelled', $calls[1]['stop_reason']);
        self::assertSame('cancelled', $this->jobs()->progress($connection)['orders']['status']);
    }

    public function testAnImportThatNothingMovesForAnHourIsStalled(): void
    {
        $connection = $this->connect();
        $this->jobs()->start($connection, HistoryJobs::ORDERS);
        $this->now += HistoryJobs::STALLED_SECONDS + 1;
        self::assertSame('stalled', $this->jobs()->progress($connection)['orders']['status'], 'an import that the background tasks never took up');

        // The engine takes no row, so the markers wait and the import does not move.
        $this->now = time();
        $this->engine->fail('orders', 503);
        $this->tick();
        self::assertSame('running', $this->jobs()->progress($connection)['orders']['status']);
        $this->now += HistoryJobs::STALLED_SECONDS + 1;
        $this->import()->run($this->deadline());

        $job = $this->jobs()->latest($connection->id(), HistoryJobs::ORDERS);
        self::assertSame([JobStore::STATUS_FAILED, HistoryJobs::REASON_STALLED], [$job['status'], $job['last_error']]);
        self::assertSame('stalled', $this->jobs()->progress($connection)['orders']['status']);
        $calls = $this->calls();
        self::assertSame('stalled', end($calls)['stop_reason']);
    }

    /**
     * CON-445: the imports that wait behind a stalled one are stalled too, and name it (Magento's
     * PRO-3923); a finished import tells when it finished, in the shop's time.
     */
    public function testTheImportsBehindAStalledOneNameIt(): void
    {
        $connection = $this->connect();
        $this->jobs()->startAll($connection);
        self::assertSame(['queued', 'queued', 'queued'], array_column($this->jobs()->progress($connection), 'status'));
        self::assertSame(['', '', ''], array_column($this->jobs()->progress($connection), 'blocked_by'));

        $this->now += HistoryJobs::STALLED_SECONDS + 1;

        $progress = $this->jobs()->progress($connection);
        self::assertSame(['stalled', 'stalled', 'stalled'], array_column($progress, 'status'));
        self::assertSame(['', HistoryJobs::CATALOG, HistoryJobs::CATALOG], array_column($progress, 'blocked_by'));
        self::assertSame(['', '', ''], array_column($progress, 'finished'));

        $this->jobs()->cancel($connection, HistoryJobs::CATALOG);
        $catalog = $this->jobs()->progress($connection)[HistoryJobs::CATALOG];
        self::assertSame(date('Y-m-d H:i', (int) strtotime($catalog['finished_at'] . ' UTC')), $catalog['finished']);
    }

    public function testAnImportOfAConnectionThatIsGoneIsCancelledWithoutACall(): void
    {
        $connection = $this->connect();
        $this->jobs()->startAll($connection);
        $this->connections()->delete($connection);

        $this->tick();

        $job = $this->jobs()->latest($connection->id(), HistoryJobs::CATALOG);
        self::assertSame([JobStore::STATUS_CANCELLED, HistoryJobs::REASON_NO_CONNECTION], [$job['status'], $job['last_error']]);
        self::assertSame([], $this->calls());
    }

    private function jobs(): HistoryJobs
    {
        return new HistoryJobs(fn (): int => $this->now);
    }

    private function import(): HistoryImport
    {
        $now = fn (): int => $this->now;
        $queue = $this->services->get(IngestQueue::class);

        return new HistoryImport(
            $this->jobs(),
            $this->connections(),
            $queue,
            $this->services->get(CustomerScope::class),
            new HistoryProgress($this->jobs(), $queue, $this->services->get(Client::class), $now),
            $now
        );
    }

    private function deadline(): Deadline
    {
        return new Deadline(microtime(true) + 60, static fn (): float => microtime(true));
    }

    /** One background run: the import job, the flush, and the import job's count of the flush. */
    private function tick(): void
    {
        $this->import()->run($this->deadline());
        $this->flush();
        $this->import()->run($this->deadline());
    }

    /**
     * @return array<string, mixed>
     */
    private function state(Connection $connection, string $entity): array
    {
        return $this->jobs()->latest($connection->id(), $entity)['state'];
    }

    /**
     * @return list<array<string, mixed>> the bodies of the progress calls, in their order
     */
    private function calls(): array
    {
        return array_column($this->engine->requestsTo(self::PROGRESS), 'body');
    }

    /** The position of the first request to a path among the requests after the exchange. */
    private function position(string $path): int
    {
        $paths = array_column($this->engine->requests(), 'path');

        return (int) array_search($path, array_slice($paths, 1), true);
    }

    /**
     * The number of the shop's orders placed from $from and before $before.
     */
    private function ordersBetween(string $from, string $before): int
    {
        return (int) self::db()->getValue(
            'SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'orders` WHERE `id_shop` = ' . $this->shop
            . ' AND `date_add` >= \'' . pSQL($from) . '\' AND `date_add` < \'' . pSQL($before) . '\'',
            false
        );
    }

    /**
     * Adds $count orders of one age, each with one line, in two statements.
     */
    private function orders(int $idCustomer, string $age, int $count): void
    {
        $placedAt = date('Y-m-d H:i:s', (int) strtotime($age, $this->now));
        $reference = 'T' . substr(md5($age), 0, 8);
        self::db()->insert('orders', array_fill(0, $count, [
            'reference' => $reference,
            'id_shop_group' => (int) \Shop::getGroupFromShop($this->shop),
            'id_shop' => $this->shop,
            'id_carrier' => 0,
            'id_lang' => (int) \Configuration::get('PS_LANG_DEFAULT'),
            'id_customer' => $idCustomer,
            'id_cart' => 0,
            'id_currency' => (int) \Configuration::get('PS_CURRENCY_DEFAULT'),
            'id_address_delivery' => 0,
            'id_address_invoice' => 0,
            'current_state' => (int) \Configuration::get('PS_OS_PAYMENT'),
            'payment' => 'test',
            'total_paid' => 0,
            'total_paid_tax_incl' => 23.9,
            'invoice_date' => $placedAt,
            'delivery_date' => $placedAt,
            'date_add' => $placedAt,
            'date_upd' => $placedAt,
        ]));
        $ids = array_map('intval', array_column(
            self::db()->executeS('SELECT `id_order` FROM `' . _DB_PREFIX_ . 'orders` WHERE `reference` = \'' . pSQL($reference) . '\'', true, false) ?: [],
            'id_order'
        ));
        self::assertCount($count, $ids);
        array_push($this->orders, ...$ids);
        self::db()->insert('order_detail', array_map(fn (int $id): array => [
            'id_order' => $id,
            'id_shop' => $this->shop,
            'product_id' => 1,
            'product_attribute_id' => 1,
            'product_name' => 'Test line',
            'product_quantity' => 1,
            'product_price' => 23.9,
            'unit_price_tax_incl' => 23.9,
            'unit_price_tax_excl' => 23.9,
            'total_price_tax_incl' => 23.9,
            'total_price_tax_excl' => 23.9,
        ], $ids));
    }

    private function order(int $idCustomer, string $age): int
    {
        $placedAt = date('Y-m-d H:i:s', (int) strtotime($age, $this->now));
        self::db()->insert('orders', [
            'reference' => 'TESTORDER',
            'id_shop_group' => (int) \Shop::getGroupFromShop($this->shop),
            'id_shop' => $this->shop,
            'id_carrier' => 0,
            'id_lang' => (int) \Configuration::get('PS_LANG_DEFAULT'),
            'id_customer' => $idCustomer,
            'id_cart' => 0,
            'id_currency' => (int) \Configuration::get('PS_CURRENCY_DEFAULT'),
            'id_address_delivery' => 0,
            'id_address_invoice' => 0,
            'current_state' => (int) \Configuration::get('PS_OS_PAYMENT'),
            'payment' => 'test',
            'total_paid' => 0,
            'total_paid_tax_incl' => 23.9,
            'invoice_date' => $placedAt,
            'delivery_date' => $placedAt,
            'date_add' => $placedAt,
            'date_upd' => $placedAt,
        ]);
        $id = (int) self::db()->Insert_ID();
        $this->orders[] = $id;
        self::db()->insert('order_detail', [
            'id_order' => $id,
            'id_shop' => $this->shop,
            'product_id' => 1,
            'product_attribute_id' => 1,
            'product_name' => 'Test line',
            'product_quantity' => 1,
            'product_price' => 23.9,
            'unit_price_tax_incl' => 23.9,
            'unit_price_tax_excl' => 23.9,
            'total_price_tax_incl' => 23.9,
            'total_price_tax_excl' => 23.9,
        ]);

        return $id;
    }
}
