<?php
declare(strict_types=1);

namespace Tests\Integration\Queue;

use PrestaShop\Module\SmailyForPrestaShop\Queue\EventQueue;
use Tests\Integration\IntegrationTestCase;

/**
 * Two flushes that start at once (the cron URL and a visitor's tick) run in two PHP processes with
 * two database connections, and claim from the same rows of the real event queue table.
 *
 * Acceptance 3 of Story 1.5.
 */
final class ConcurrentFlushTest extends IntegrationTestCase
{
    private const EVENT_TYPE = 'test.concurrent';

    private const ROWS = 400;

    private string $output = '';

    protected function setUp(): void
    {
        parent::setUp();
        self::clearQueue();
        $this->output = (string) tempnam(sys_get_temp_dir(), 'smaily-flush-');
    }

    protected function tearDown(): void
    {
        if (defined('_PS_ROOT_DIR_')) {
            self::clearQueue();
            @unlink($this->output);
        }
        parent::tearDown();
    }

    public function testTwoConcurrentFlushesNeverSendOneRowTwice(): void
    {
        $queue = new EventQueue();
        $ids = [];
        for ($i = 0; $i < self::ROWS; ++$i) {
            $ids[] = $queue->enqueue(self::EVENT_TYPE, 1, ['n' => $i]);
        }

        $start = sprintf('%.3f', microtime(true) + 3);
        $workers = [];
        for ($i = 0; $i < 2; ++$i) {
            $workers[] = $this->startWorker($start);
        }
        $claimed = array_map([$this, 'finish'], $workers);

        $sent = array_map('intval', file($this->output, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES));
        $twice = array_keys(array_filter(array_count_values($sent), fn (int $count): bool => $count > 1));
        self::assertSame([], $twice, 'rows sent more than once');
        sort($sent);
        self::assertSame($ids, $sent, 'every row is sent');
        self::assertSame(self::ROWS, array_sum($claimed));
        self::assertGreaterThan(0, min($claimed), sprintf('both flushes claimed rows (%s)', implode(' + ', $claimed)));
        self::assertSame(
            (string) self::ROWS,
            (string) self::db()->getValue(sprintf(
                'SELECT COUNT(*) FROM `%ssmaily_connect_event_queue` WHERE `status` = \'%s\'',
                _DB_PREFIX_,
                EventQueue::STATUS_SENT
            ), false)
        );
    }

    /**
     * @return array{0: resource, 1: array<int, resource>}
     */
    private function startWorker(string $start): array
    {
        $process = proc_open(
            [PHP_BINARY, __DIR__ . '/flush-worker.php', $start, $this->output, self::EVENT_TYPE],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );
        self::assertIsResource($process);

        return [$process, $pipes];
    }

    /**
     * @param array{0: resource, 1: array<int, resource>} $worker
     *
     * @return int the rows the worker claimed
     */
    private function finish(array $worker): int
    {
        [$process, $pipes] = $worker;
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertSame(0, proc_close($process), 'the worker ended without an error: ' . $stdout . $stderr);
        self::assertMatchesRegularExpression('/^\d+$/', $stdout, $stderr);

        return (int) $stdout;
    }

    private static function clearQueue(): void
    {
        self::db()->execute('DELETE FROM `' . _DB_PREFIX_ . 'smaily_connect_event_queue`');
    }
}
