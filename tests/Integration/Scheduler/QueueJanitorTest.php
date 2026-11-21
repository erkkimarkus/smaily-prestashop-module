<?php
declare(strict_types=1);

namespace Tests\Integration\Scheduler;

use PrestaShop\Module\SmailyForPrestaShop\Automation\AbandonedCart\CartTracker;
use PrestaShop\Module\SmailyForPrestaShop\Contacts\Import\JobStore;
use PrestaShop\Module\SmailyForPrestaShop\Discount\IssuedCodes;
use PrestaShop\Module\SmailyForPrestaShop\Queue\EventQueue;
use PrestaShop\Module\SmailyForPrestaShop\Scheduler\Deadline;
use PrestaShop\Module\SmailyForPrestaShop\Scheduler\QueueJanitor;
use Tests\Integration\IntegrationTestCase;
use Tests\Integration\Migration\LegacyStore;

/**
 * The janitor deletes sent and skipped event queue rows after 30 days, failed ones after 90 days
 * and finished jobs after 90 days, and no other rows: no cart of the tracker, and none of 2.x's
 * `smaily_cart`.
 */
final class QueueJanitorTest extends IntegrationTestCase
{
    private const START = 1893456000; // 2030-01-01 00:00:00 UTC

    private const DAY = 86400;

    private int $now = self::START;

    private EventQueue $queue;

    protected function setUp(): void
    {
        parent::setUp();
        self::clearQueue();
        self::clearJobs();
        $this->queue = new EventQueue(fn (): int => $this->now);
    }

    protected function tearDown(): void
    {
        if (defined('_PS_ROOT_DIR_')) {
            self::clearQueue();
            self::clearJobs();
            self::db()->execute('DELETE FROM `' . _DB_PREFIX_ . CartTracker::TABLE . '` WHERE `id_cart` BETWEEN 990001 AND 990003');
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

    public function testDeletesTheFinishedJobsPastRetentionAndNoOthers(): void
    {
        $kept = [
            $this->job(JobStore::STATUS_DONE, 90 * self::DAY - 60),
            $this->job(JobStore::STATUS_FAILED, 31 * self::DAY),
            $this->job(JobStore::STATUS_CANCELLED, 89 * self::DAY),
            $this->job(JobStore::STATUS_QUEUED, 400 * self::DAY),
            $this->job(JobStore::STATUS_RUNNING, 400 * self::DAY),
        ];
        $this->job(JobStore::STATUS_DONE, 90 * self::DAY + 60);
        $this->job(JobStore::STATUS_FAILED, 91 * self::DAY);
        $this->job(JobStore::STATUS_CANCELLED, 400 * self::DAY);

        self::assertSame(3, $this->janitor()->run($this->deadline(false)));

        self::assertSame($kept, $this->jobIds());
    }

    public function testDeletesTheFinishedJobsInBatchesUntilNoneIsLeft(): void
    {
        for ($i = 0; $i < 5; ++$i) {
            $this->job(JobStore::STATUS_DONE, 100 * self::DAY);
        }

        self::assertSame(5, $this->janitor(2)->run($this->deadline(false)));
        self::assertSame([], $this->jobIds());
    }

    public function testStopsTheJobsAfterABatchOnceItsDeadlinePassed(): void
    {
        for ($i = 0; $i < 5; ++$i) {
            $this->job(JobStore::STATUS_DONE, 100 * self::DAY);
        }
        // The deadline passes once the first batch of jobs is gone.
        $deadline = new Deadline(10.0, fn (): float => count($this->jobIds()) < 5 ? 10.0 : 0.0);

        self::assertSame(2, $this->janitor(2)->run($deadline));
        self::assertCount(3, $this->jobIds());
    }

    public function testLeavesTheCartTrackerAndTheLegacyCartTableAlone(): void
    {
        $longAgo = date('Y-m-d H:i:s', time() - 400 * self::DAY);
        foreach ([CartTracker::MAILED, CartTracker::SKIPPED, CartTracker::OPEN] as $index => $status) {
            self::db()->insert(CartTracker::TABLE, [
                'id_cart' => 990001 + $index,
                'id_shop' => 1,
                'id_lang' => 1,
                'status' => $status,
                'last_activity_at' => $longAgo,
            ]);
        }
        LegacyStore::createSentCarts(3);

        self::assertSame(0, $this->janitor()->run($this->deadline(false)));

        self::assertSame('3', (string) self::db()->getValue('SELECT COUNT(*) FROM `' . _DB_PREFIX_ . CartTracker::TABLE . '`', false));
        self::assertSame('3', (string) self::db()->getValue('SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'smaily_cart`', false));
    }

    private function janitor(int $batch = QueueJanitor::BATCH_SIZE): QueueJanitor
    {
        return new QueueJanitor($this->queue, new JobStore(), new IssuedCodes(), $batch);
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

    private static function clearQueue(): void
    {
        self::db()->execute('DELETE FROM `' . _DB_PREFIX_ . 'smaily_connect_event_queue`');
    }

    private static function clearJobs(): void
    {
        self::db()->execute('DELETE FROM `' . _DB_PREFIX_ . JobStore::TABLE . '`');
    }
}
