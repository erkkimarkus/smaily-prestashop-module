<?php
declare(strict_types=1);

namespace Tests\Integration\Scheduler;

use PrestaShop\Module\SmailyForPrestaShop\Queue\EventQueue;
use PrestaShop\Module\SmailyForPrestaShop\Scheduler\Deadline;
use PrestaShop\Module\SmailyForPrestaShop\Scheduler\QueueJanitor;
use Tests\Integration\IntegrationTestCase;

/**
 * The janitor deletes sent and skipped event queue rows after 30 days and failed ones after 90
 * days, and no other rows.
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
        $this->queue = new EventQueue(fn (): int => $this->now);
    }

    protected function tearDown(): void
    {
        if (defined('_PS_ROOT_DIR_')) {
            self::clearQueue();
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

    private function janitor(int $batch = QueueJanitor::BATCH_SIZE): QueueJanitor
    {
        return new QueueJanitor($this->queue, $batch);
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

    private static function clearQueue(): void
    {
        self::db()->execute('DELETE FROM `' . _DB_PREFIX_ . 'smaily_connect_event_queue`');
    }
}
