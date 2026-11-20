<?php
declare(strict_types=1);

namespace Tests\Integration\Queue;

use PrestaShop\Module\SmailyForPrestaShop\Queue\EventQueue;
use Tests\Integration\IntegrationTestCase;

/**
 * The queue deletes rows of a closed status whose last change is older than a given age, so that a
 * retention sweep can keep the table small.
 */
final class DeleteClosedTest extends IntegrationTestCase
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

    public function testDeletesTheRowsOfTheStatusThatChangedBeforeTheAge(): void
    {
        $old = $this->row(EventQueue::STATUS_SENT, 31);
        $young = $this->row(EventQueue::STATUS_SENT, 29);
        $exact = $this->row(EventQueue::STATUS_SENT, 30);
        $otherStatus = $this->row(EventQueue::STATUS_FAILED, 31);

        self::assertSame(1, $this->queue->deleteClosed(EventQueue::STATUS_SENT, 30 * self::DAY, 100));

        self::assertSame([$young, $exact, $otherStatus], $this->ids());
        self::assertNotContains($old, $this->ids());
    }

    public function testDeletesAtMostTheLimit(): void
    {
        $this->row(EventQueue::STATUS_SKIPPED, 40);
        $this->row(EventQueue::STATUS_SKIPPED, 40);
        $third = $this->row(EventQueue::STATUS_SKIPPED, 40);

        self::assertSame(2, $this->queue->deleteClosed(EventQueue::STATUS_SKIPPED, 30 * self::DAY, 2));
        self::assertSame([$third], $this->ids());
        self::assertSame(1, $this->queue->deleteClosed(EventQueue::STATUS_SKIPPED, 30 * self::DAY, 2));
        self::assertSame([], $this->ids());
    }

    public function testRefusesAStatusThatIsNotClosed(): void
    {
        $this->row(EventQueue::STATUS_PENDING, 400);
        $this->row(EventQueue::STATUS_PROCESSING, 400);

        foreach ([EventQueue::STATUS_PENDING, EventQueue::STATUS_PROCESSING] as $status) {
            try {
                $this->queue->deleteClosed($status, self::DAY, 100);
                self::fail('deleteClosed() took the status ' . $status);
            } catch (\InvalidArgumentException $refused) {
            }
        }
        self::assertCount(2, $this->ids());
    }

    /**
     * A row that reached $status $daysAgo days before now.
     */
    private function row(string $status, int $daysAgo): int
    {
        $this->now = self::START - $daysAgo * self::DAY;
        $id = $this->queue->enqueue('test.retention', 1, []);
        $this->now = self::START;
        self::db()->execute(sprintf(
            'UPDATE `%ssmaily_connect_event_queue` SET `status` = \'%s\', `updated_at` = \'%s\' WHERE `id` = %d',
            _DB_PREFIX_,
            pSQL($status),
            gmdate('Y-m-d H:i:s', self::START - $daysAgo * self::DAY),
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
