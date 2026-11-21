<?php
declare(strict_types=1);

namespace Tests\Integration\Scheduler;

use PrestaShop\Module\SmailyForPrestaShop\Automation\AbandonedCart\CartTracker;
use PrestaShop\Module\SmailyForPrestaShop\Contacts\Import\ContactImport;
use PrestaShop\Module\SmailyForPrestaShop\Contacts\Import\JobStore;
use PrestaShop\Module\SmailyForPrestaShop\Discount\IssuedCodes;
use PrestaShop\Module\SmailyForPrestaShop\Queue\EventQueue;
use PrestaShop\Module\SmailyForPrestaShop\Runtime\Services;
use PrestaShop\Module\SmailyForPrestaShop\Scheduler\Deadline;
use PrestaShop\Module\SmailyForPrestaShop\Scheduler\QueueJanitor;
use Tests\Integration\IntegrationTestCase;
use Tests\Integration\Migration\LegacyStore;

require_once __DIR__ . '/../Migration/LegacyStore.php';

/**
 * The janitor deletes sent and skipped event queue rows after 30 days, failed ones after 90 days
 * and the closed carts of the tracker 30 days after the cart's last change, and no other rows: no
 * import job, no open cart, and none of 2.x's `smaily_cart`.
 */
final class QueueJanitorTest extends IntegrationTestCase
{
    private const START = 1893456000; // 2030-01-01 00:00:00 UTC

    private const DAY = 86400;

    private const FIRST_CART = 990001;

    private int $now = self::START;

    private EventQueue $queue;

    protected function setUp(): void
    {
        parent::setUp();
        self::clearQueue();
        self::clearJobs();
        self::clearCarts();
        $this->queue = new EventQueue(fn (): int => $this->now);
    }

    protected function tearDown(): void
    {
        if (defined('_PS_ROOT_DIR_')) {
            self::clearQueue();
            self::clearJobs();
            self::clearCarts();
            self::db()->execute('DROP TABLE IF EXISTS `' . _DB_PREFIX_ . 'smaily_cart`');
        }
        parent::tearDown();
    }

    public function testDeletesTheRowsPastRetentionAndNoOthers(): void
    {
        $kept = [
            $this->row(EventQueue::STATUS_SENT, 30 * self::DAY),
            $this->row(EventQueue::STATUS_SKIPPED, 29 * self::DAY),
            $this->row(EventQueue::STATUS_FAILED, 90 * self::DAY),
            $this->row(EventQueue::STATUS_FAILED, 31 * self::DAY),
            $this->row(EventQueue::STATUS_PENDING, 400 * self::DAY),
            $this->row(EventQueue::STATUS_PROCESSING, 400 * self::DAY),
        ];
        $this->row(EventQueue::STATUS_SENT, 30 * self::DAY + 1);
        $this->row(EventQueue::STATUS_SKIPPED, 31 * self::DAY);
        $this->row(EventQueue::STATUS_FAILED, 90 * self::DAY + 1);

        self::assertSame(3, $this->janitor()->run($this->deadline(false)));

        self::assertSame($kept, $this->ids());
    }

    public function testDeletesInBatchesUntilNoneIsLeft(): void
    {
        for ($i = 0; $i < 5; ++$i) {
            $this->row(EventQueue::STATUS_SENT, 40 * self::DAY);
        }

        self::assertSame(5, $this->janitor(2)->run($this->deadline(false)));
        self::assertSame([], $this->ids());
    }

    public function testStopsAfterABatchOnceItsDeadlinePassed(): void
    {
        for ($i = 0; $i < 5; ++$i) {
            $this->row(EventQueue::STATUS_SENT, 40 * self::DAY);
        }

        self::assertSame(2, $this->janitor(2)->run($this->deadline(true)));
        self::assertCount(3, $this->ids());
    }

    /**
     * The job table is not swept, as on Magento: a finished import stays however old it is, and
     * the card of the first import still shows it.
     */
    public function testKeepsEveryImportJobAndTheImportCardStillShowsTheLastImport(): void
    {
        $jobs = [
            $this->job(JobStore::STATUS_FAILED, 400 * self::DAY),
            $this->job(JobStore::STATUS_CANCELLED, 300 * self::DAY),
            $this->job(JobStore::STATUS_DONE, 91 * self::DAY),
        ];

        self::assertSame(0, $this->janitor()->run($this->deadline(false)));

        self::assertSame($jobs, $this->jobIds());
        $card = Services::instance()->get(ContactImport::class)->progress(1);
        self::assertNotNull($card);
        self::assertSame([$jobs[2], JobStore::STATUS_DONE], [$card['id'], $card['status']]);
    }

    /**
     * Magento's rule for its tracker (Cron\QueueJanitor, StateManager::pruneTerminal()): a closed
     * row goes 30 days after its last touch, and an open one stays. Here the age counts from the
     * cart's last change, which the row records. A row that the upgrade carried over from 2.x
     * (no address, only a customer) follows the same rule, and 2.x's own table is never touched.
     */
    public function testDeletesTheClosedCartsPast30DaysAndNoOthers(): void
    {
        $kept = [
            $this->cart(CartTracker::MAILED, 30 * self::DAY - 60),
            $this->cart(CartTracker::SKIPPED, 29 * self::DAY),
            $this->cart(CartTracker::MAILED, self::DAY, ['id_customer' => 7]),
            $this->cart(CartTracker::OPEN, 400 * self::DAY, ['event_id' => 12345]),
            $this->cart(CartTracker::OPEN, 400 * self::DAY),
        ];
        $this->cart(CartTracker::MAILED, 30 * self::DAY + 60);
        $this->cart(CartTracker::SKIPPED, 31 * self::DAY, ['skip_reason' => 'ordered']);
        $this->cart(CartTracker::MAILED, 400 * self::DAY, ['id_customer' => 7]);
        LegacyStore::createSentCarts(3);
        $legacy = self::db()->executeS('SELECT * FROM `' . _DB_PREFIX_ . 'smaily_cart` ORDER BY `id_smaily_cart`');

        self::assertSame(3, $this->janitor()->run($this->deadline(false)));

        self::assertSame($kept, $this->cartIds());
        self::assertSame($legacy, self::db()->executeS('SELECT * FROM `' . _DB_PREFIX_ . 'smaily_cart` ORDER BY `id_smaily_cart`'));
    }

    public function testDeletesTheClosedCartsInBatchesUntilNoneIsLeft(): void
    {
        for ($i = 0; $i < 5; ++$i) {
            $this->cart(CartTracker::MAILED, 40 * self::DAY);
        }

        self::assertSame(5, $this->janitor(2)->run($this->deadline(false)));
        self::assertSame([], $this->cartIds());
    }

    public function testStopsTheCartsAfterABatchOnceItsDeadlinePassed(): void
    {
        for ($i = 0; $i < 5; ++$i) {
            $this->cart(CartTracker::SKIPPED, 40 * self::DAY);
        }
        // The deadline passes once the first batch of carts is gone.
        $deadline = new Deadline(10.0, fn (): float => count($this->cartIds()) < 5 ? 10.0 : 0.0);

        self::assertSame(2, $this->janitor(2)->run($deadline));
        self::assertCount(3, $this->cartIds());
    }

    private function janitor(int $batch = QueueJanitor::BATCH_SIZE): QueueJanitor
    {
        return new QueueJanitor($this->queue, new CartTracker(), new IssuedCodes(), $batch);
    }

    private function deadline(bool $passed): Deadline
    {
        return new Deadline(10.0, fn (): float => $passed ? 10.0 : 0.0);
    }

    /**
     * A row that reached $status $secondsAgo seconds before now.
     */
    private function row(string $status, int $secondsAgo): int
    {
        $id = $this->queue->enqueue('test.retention', 1, []);
        self::db()->execute(sprintf(
            'UPDATE `%ssmaily_connect_event_queue` SET `status` = \'%s\', `updated_at` = \'%s\' WHERE `id` = %d',
            _DB_PREFIX_,
            pSQL($status),
            gmdate('Y-m-d H:i:s', self::START - $secondsAgo),
            $id
        ));

        return $id;
    }

    /**
     * @return list<int>
     */
    private function ids(): array
    {
        $rows = self::db()->executeS('SELECT `id` FROM `' . _DB_PREFIX_ . 'smaily_connect_event_queue` ORDER BY `id`', true, false);

        return array_map(static fn (array $row): int => (int) $row['id'], is_array($rows) ? $rows : []);
    }

    /**
     * A job that reached $status $secondsAgo seconds ago.
     */
    private function job(string $status, int $secondsAgo): int
    {
        $id = (new JobStore())->create('contacts_import', 1, $status, []);
        self::db()->execute(sprintf(
            'UPDATE `%s%s` SET `updated_at` = \'%s\' WHERE `id` = %d',
            _DB_PREFIX_,
            JobStore::TABLE,
            gmdate('Y-m-d H:i:s', time() - $secondsAgo),
            $id
        ));

        return $id;
    }

    /**
     * @return list<int>
     */
    private function jobIds(): array
    {
        $rows = self::db()->executeS('SELECT `id` FROM `' . _DB_PREFIX_ . JobStore::TABLE . '` ORDER BY `id`', true, false);

        return array_map(static fn (array $row): int => (int) $row['id'], is_array($rows) ? $rows : []);
    }

    /**
     * A tracker row in $status whose cart last changed $secondsAgo seconds ago.
     *
     * @param array<string, int|string> $columns
     */
    private function cart(string $status, int $secondsAgo, array $columns = []): int
    {
        $idCart = self::FIRST_CART + count($this->cartIds());
        self::db()->insert(CartTracker::TABLE, $columns + [
            'id_cart' => $idCart,
            'id_shop' => 1,
            'id_lang' => 1,
            'status' => $status,
            'last_activity_at' => date('Y-m-d H:i:s', time() - $secondsAgo),
        ]);

        return $idCart;
    }

    /**
     * @return list<int>
     */
    private function cartIds(): array
    {
        $rows = self::db()->executeS('SELECT `id_cart` FROM `' . _DB_PREFIX_ . CartTracker::TABLE . '` ORDER BY `id_cart`', true, false);

        return array_map(static fn (array $row): int => (int) $row['id_cart'], is_array($rows) ? $rows : []);
    }

    private static function clearCarts(): void
    {
        self::db()->execute('DELETE FROM `' . _DB_PREFIX_ . CartTracker::TABLE . '`');
    }

    private static function clearQueue(): void
    {
        self::db()->execute('DELETE FROM `' . _DB_PREFIX_ . 'smaily_connect_event_queue`');
    }

    private static function clearJobs(): void
    {
        self::db()->execute('DELETE FROM `' . _DB_PREFIX_ . JobStore::TABLE . '`');
    }
}
