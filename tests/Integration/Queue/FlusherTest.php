<?php
declare(strict_types=1);

namespace Tests\Integration\Queue;

use PrestaShop\Module\SmailyForPrestaShop\Database\SqlException;
use PrestaShop\Module\SmailyForPrestaShop\Queue\Deferred;
use PrestaShop\Module\SmailyForPrestaShop\Queue\EventQueue;
use PrestaShop\Module\SmailyForPrestaShop\Queue\Failure;
use PrestaShop\Module\SmailyForPrestaShop\Queue\Flusher;
use PrestaShop\Module\SmailyForPrestaShop\Queue\HandlerInterface;
use PrestaShop\Module\SmailyForPrestaShop\Queue\Pending;
use PrestaShop\Module\SmailyForPrestaShop\Queue\Skipped;
use PrestaShop\Module\SmailyForPrestaShop\Runtime\Provider\QueueProvider;
use PrestaShop\Module\SmailyForPrestaShop\Runtime\Services;
use PrestaShop\Module\SmailyForPrestaShop\Smaily\Exception\ApiException;
use Tests\Integration\Fixtures\FailingQueries;
use Tests\Integration\IntegrationTestCase;

require_once __DIR__ . '/../Fixtures/FailingQueries.php';

/**
 * The flush hands the due rows of each event type to that type's handler and records each row's
 * outcome in the event queue table, with the sibling connectors' retry semantics.
 */
final class FlusherTest extends IntegrationTestCase
{
    private const START = 1893456000; // 2030-01-01 00:00:00 UTC

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

    public function testStoresAnEnqueuedEventAsPending(): void
    {
        $id = $this->queue->enqueue('test.sync', 2, ['email' => 'shopper@example.test'], 'shopper@example.test', 'et');

        $row = $this->row($id);
        self::assertSame('test.sync', $row['event_type']);
        self::assertSame(2, (int) $row['id_shop']);
        self::assertSame('shopper@example.test', $row['entity_id']);
        self::assertSame('et', $row['language']);
        self::assertSame(['email' => 'shopper@example.test'], json_decode($row['payload'], true));
        self::assertSame(EventQueue::STATUS_PENDING, $row['status']);
        self::assertSame(0, (int) $row['attempts']);
        self::assertNull($row['next_retry_at']);
        self::assertSame('2030-01-01 00:00:00', $row['created_at']);
    }

    public function testAPayloadUpdateReplacesThePayloadOfTheNamedRowAndNothingElse(): void
    {
        $id = $this->queue->enqueue('test.sync', 2, ['email' => 'shopper@example.test', 'fields' => []], 'shopper@example.test', 'et');
        $other = $this->queue->enqueue('test.sync', 2, ['email' => 'other@example.test'], 'other@example.test', 'et');
        $claimed = $this->queue->claim(1)[0];
        $this->now += 60;

        self::assertTrue($this->queue->updatePayload($claimed, ['email' => 'shopper@example.test', 'fields' => ['code' => 'ÕÄ23']]));
        self::assertTrue($this->queue->updatePayload($claimed, ['email' => 'shopper@example.test', 'fields' => ['code' => 'ÕÄ23']]), 'the same payload again: the claim still holds');

        $row = $this->row($id);
        self::assertSame(['email' => 'shopper@example.test', 'fields' => ['code' => 'ÕÄ23']], json_decode($row['payload'], true));
        self::assertSame(
            [EventQueue::STATUS_PROCESSING, $claimed['claim_token'], $claimed['updated_at'], 0],
            [$row['status'], $row['claim_token'], $row['updated_at'], (int) $row['attempts']],
            'the claim and the times stay'
        );
        self::assertSame(['email' => 'other@example.test'], json_decode($this->row($other)['payload'], true));
    }

    public function testAPayloadUpdateOfARowThatAnotherFlushTookOverWritesNothing(): void
    {
        $id = $this->queue->enqueue('test.sync', 2, ['fields' => []]);
        $slow = $this->queue->claim(1)[0];
        $this->now += EventQueue::CLAIM_TIMEOUT_SECONDS;
        $this->queue->requeueStale();

        self::assertFalse($this->queue->updatePayload($slow, ['fields' => ['code' => 'SLOW']]), 'the stale claim was taken back');
        $second = $this->queue->claim(1)[0];
        self::assertFalse($this->queue->updatePayload($slow, ['fields' => ['code' => 'SLOW']]), 'the row is another flush\'s');
        self::assertSame(['fields' => []], json_decode($this->row($id)['payload'], true));

        self::assertTrue($this->queue->updatePayload($second, ['fields' => ['code' => 'SECOND']]));
        self::assertSame(['fields' => ['code' => 'SECOND']], json_decode($this->row($id)['payload'], true));
        self::assertSame($second['claim_token'], $this->row($id)['claim_token']);
    }

    public function testMarksTheRowsAHandlerSentAsSent(): void
    {
        $first = $this->queue->enqueue('test.sync', 1, ['n' => 1]);
        $second = $this->queue->enqueue('test.sync', 1, ['n' => 2]);
        $handler = new FakeHandler(fn (array $row) => true);

        self::assertSame(2, $this->flusher(['test.sync' => $handler])->flush());

        self::assertSame([[$first, $second]], $handler->batches);
        foreach ([$first, $second] as $id) {
            $row = $this->row($id);
            self::assertSame(EventQueue::STATUS_SENT, $row['status']);
            self::assertSame(0, (int) $row['attempts'], 'attempts count the failed ones');
            self::assertSame('2030-01-01 00:00:00', $row['sent_at']);
            self::assertNull($row['claim_token']);
            self::assertNull($row['last_error']);
        }
    }

    public function testHandsEachEventTypeToItsOwnHandler(): void
    {
        $sync = $this->queue->enqueue('test.sync', 1, []);
        $trigger = $this->queue->enqueue('test.trigger', 1, []);
        $syncHandler = new FakeHandler(fn (array $row) => true);
        $triggerHandler = new FakeHandler(fn (array $row) => true);

        $this->flusher(['test.sync' => $syncHandler, 'test.trigger' => $triggerHandler])->flush();

        self::assertSame([[$sync]], $syncHandler->batches);
        self::assertSame([[$trigger]], $triggerHandler->batches);
    }

    /**
     * Acceptance 1 of Story 1.5.
     */
    public function testAFailingRowFollowsTheLadderAndParksAfterFiveAttempts(): void
    {
        $id = $this->queue->enqueue('test.sync', 1, []);
        $flusher = $this->flusher(['test.sync' => new FakeHandler(fn (array $row) => Failure::temporary('Smaily answered HTTP 503'))]);

        $waits = [];
        for ($attempt = 1; $attempt <= 4; ++$attempt) {
            self::assertSame(1, $flusher->flush());
            $row = $this->row($id);
            self::assertSame(EventQueue::STATUS_PENDING, $row['status']);
            self::assertSame($attempt, (int) $row['attempts']);
            self::assertSame('Smaily answered HTTP 503', $row['last_error']);
            $waits[] = strtotime($row['next_retry_at'] . ' UTC') - $this->now;

            $this->now = strtotime($row['next_retry_at'] . ' UTC') - 1;
            self::assertSame(0, $flusher->flush(), 'the row waits until its next attempt is due');
            ++$this->now;
        }
        self::assertSame([60, 300, 900, 3600], $waits);

        self::assertSame(1, $flusher->flush());
        $row = $this->row($id);
        self::assertSame(EventQueue::STATUS_FAILED, $row['status']);
        self::assertSame(5, (int) $row['attempts']);
        self::assertNull($row['next_retry_at']);
        self::assertSame('Smaily answered HTTP 503', $row['last_error']);

        $this->now += 86400;
        self::assertSame(0, $flusher->flush(), 'a parked row is not claimed again');
    }

    /**
     * Acceptance 2 of Story 1.5.
     */
    public function testA4xxOtherThan429AndSmailyCode203ParkOnTheFirstAttempt(): void
    {
        $refused = $this->queue->enqueue('test.sync', 1, ['n' => 1]);
        $invalid = $this->queue->enqueue('test.sync', 1, ['n' => 2]);
        $limited = $this->queue->enqueue('test.sync', 1, ['n' => 3]);
        $outcomes = [
            $refused => Failure::http(401, 'Smaily answered HTTP 401'),
            $invalid => Failure::of(new ApiException(203)),
            $limited => Failure::http(429, 'Smaily answered HTTP 429', 120),
        ];

        $this->flusher(['test.sync' => new FakeHandler(fn (array $row) => $outcomes[(int) $row['id']])])->flush();

        $row = $this->row($refused);
        self::assertSame(EventQueue::STATUS_FAILED, $row['status']);
        self::assertSame(1, (int) $row['attempts']);
        self::assertSame('permanent_http_401: Smaily answered HTTP 401', $row['last_error']);

        $row = $this->row($invalid);
        self::assertSame(EventQueue::STATUS_FAILED, $row['status']);
        self::assertSame(1, (int) $row['attempts']);
        self::assertSame('permanent_envelope_203: Smaily API returned code 203', $row['last_error']);

        $row = $this->row($limited);
        self::assertSame(EventQueue::STATUS_PENDING, $row['status']);
        self::assertSame('2030-01-01 00:02:00', $row['next_retry_at'], 'a 429 waits the delay it asked for');
    }

    /**
     * Acceptance 4 of Story 1.5.
     */
    public function testAHandlerThatThrowsFailsItsRowOnly(): void
    {
        $healthy = $this->queue->enqueue('test.sync', 1, ['poison' => false]);
        $poison = $this->queue->enqueue('test.sync', 1, ['poison' => true]);
        $alsoHealthy = $this->queue->enqueue('test.sync', 1, ['poison' => false]);
        $other = $this->queue->enqueue('test.trigger', 1, []);
        $handler = new FakeHandler(function (array $row) {
            if (json_decode($row['payload'], true)['poison']) {
                throw new \TypeError('Cannot access offset of type string on string');
            }

            return true;
        });

        self::assertSame(4, $this->flusher([
            'test.sync' => $handler,
            'test.trigger' => new FakeHandler(fn (array $row) => true),
        ])->flush());

        self::assertSame(EventQueue::STATUS_SENT, $this->row($healthy)['status']);
        self::assertSame(EventQueue::STATUS_SENT, $this->row($alsoHealthy)['status']);
        self::assertSame(EventQueue::STATUS_SENT, $this->row($other)['status']);
        $row = $this->row($poison);
        self::assertSame(EventQueue::STATUS_PENDING, $row['status']);
        self::assertSame(1, (int) $row['attempts']);
        self::assertMatchesRegularExpression('/^TypeError at FlusherTest\.php:\d+ \(code 0\)$/', $row['last_error']);
        self::assertSame(
            [[$healthy, $poison, $alsoHealthy], [$healthy], [$poison], [$alsoHealthy]],
            $handler->batches,
            'a batch that throws is handed again row by row'
        );
    }

    public function testAThrowableHandedBackAsAnOutcomeFailsItsRow(): void
    {
        $id = $this->queue->enqueue('test.sync', 1, []);

        $this->flusher(['test.sync' => new FakeHandler(fn (array $row) => new \RuntimeException('secret text'))])->flush();

        $row = $this->row($id);
        self::assertSame(EventQueue::STATUS_PENDING, $row['status']);
        self::assertStringStartsWith('RuntimeException at ', $row['last_error']);
        self::assertStringNotContainsString('secret text', $row['last_error']);
    }

    public function testParksTheRowsOfAnEventTypeThatNoHandlerTakes(): void
    {
        $id = $this->queue->enqueue('test.unknown', 1, []);

        $this->flusher([])->flush();

        $row = $this->row($id);
        self::assertSame(EventQueue::STATUS_FAILED, $row['status']);
        self::assertSame('No handler takes the event type "test.unknown"', $row['last_error']);
    }

    public function testClosesASkippedRowWithItsReason(): void
    {
        $id = $this->queue->enqueue('test.trigger', 1, []);

        $this->flusher(['test.trigger' => new FakeHandler(fn (array $row) => new Skipped('No workflow is mapped'))])->flush();

        $row = $this->row($id);
        self::assertSame(EventQueue::STATUS_SKIPPED, $row['status']);
        self::assertSame('No workflow is mapped', $row['last_error']);
        self::assertNull($row['claim_token']);
    }

    public function testGivesAPendingRowBackUnchanged(): void
    {
        $id = $this->queue->enqueue('test.trigger', 1, []);

        $this->flusher(['test.trigger' => new FakeHandler(fn (array $row) => new Pending())])->flush();

        $row = $this->row($id);
        self::assertSame(EventQueue::STATUS_PENDING, $row['status']);
        self::assertSame(0, (int) $row['attempts']);
        self::assertNull($row['claim_token']);
        self::assertNull($row['last_error']);
    }

    public function testADeferredRowWaitsUntilItsTimeWithNoAttemptSpent(): void
    {
        $id = $this->queue->enqueue('test.trigger', 1, []);
        $flusher = $this->flusher(['test.trigger' => new FakeHandler(fn (array $row) => (int) $row['attempts'] === 0 && $row['last_error'] === null
            ? new Deferred($this->now + 42, 'Campaign Intelligence answered HTTP 429 (rate_limit_exceeded).')
            : new Deferred($this->now + 60))]);

        self::assertSame(1, $flusher->flush());
        $row = $this->row($id);
        self::assertSame(
            [EventQueue::STATUS_PENDING, 0, gmdate('Y-m-d H:i:s', self::START + 42), 'Campaign Intelligence answered HTTP 429 (rate_limit_exceeded).', null],
            [$row['status'], (int) $row['attempts'], $row['next_retry_at'], $row['last_error'], $row['claim_token']]
        );
        $this->now = self::START + 41;
        self::assertSame(0, $flusher->flush(), 'a flush does not claim it before its time');

        $this->now = self::START + 42;
        self::assertSame(1, $flusher->flush());
        $row = $this->row($id);
        self::assertSame(
            [EventQueue::STATUS_PENDING, 0, gmdate('Y-m-d H:i:s', self::START + 102), 'Campaign Intelligence answered HTTP 429 (rate_limit_exceeded).'],
            [$row['status'], (int) $row['attempts'], $row['next_retry_at'], $row['last_error']],
            'a deferral without a reason keeps the row\'s reason'
        );
    }

    public function testFailsARowTheHandlerGaveNoOutcomeFor(): void
    {
        $id = $this->queue->enqueue('test.sync', 1, []);
        $handler = new class() implements HandlerInterface {
            public function handle(array $events): array
            {
                return [];
            }
        };

        $this->flusher(['test.sync' => $handler])->flush();

        $row = $this->row($id);
        self::assertSame(EventQueue::STATUS_PENDING, $row['status']);
        self::assertSame(1, (int) $row['attempts']);
        self::assertSame('The handler returned no outcome for the row', $row['last_error']);
    }

    public function testReturnsAClaimThatADeadFlushLeftAfterTenMinutes(): void
    {
        $id = $this->queue->enqueue('test.sync', 1, []);
        self::assertCount(1, $this->queue->claim(10));

        $this->now += 599;
        self::assertSame(0, $this->flusher([])->flush(), 'a recent claim stays with its flush');

        $this->now += 1;
        $handler = new FakeHandler(fn (array $row) => true);
        self::assertSame(1, $this->flusher(['test.sync' => $handler])->flush());
        self::assertSame([[$id]], $handler->batches);
        self::assertSame(EventQueue::STATUS_SENT, $this->row($id)['status']);
    }

    public function testRecordsNoOutcomeOnARowThatAnotherFlushClaimedSince(): void
    {
        $id = $this->queue->enqueue('test.sync', 1, []);
        $rows = $this->queue->claim(10);
        self::db()->execute('UPDATE `' . _DB_PREFIX_ . 'smaily_connect_event_queue` SET `claim_token` = \'' . str_repeat('b', 32) . '\'');

        $this->queue->markSent($rows);
        $this->queue->markFailed($rows[0], Failure::permanent('late'));

        self::assertSame(EventQueue::STATUS_PROCESSING, $this->row($id)['status']);
    }

    /**
     * A statement of the queue that fails throws (facts file 6.11: a live PrestaShop 9.2.0 store
     * answers `false` and throws nothing). A read of the due rows that fails claims nothing and
     * hands nothing to a handler; the rows wait for the next flush.
     */
    public function testAFlushThatCouldNotReadTheDueRowsSendsNothing(): void
    {
        $id = $this->queue->enqueue('test.sync', 1, []);
        $handler = new FakeHandler(fn (array $row) => true);
        $flusher = $this->flusher(['test.sync' => $handler]);

        try {
            FailingQueries::during('/^SELECT `id` FROM .* WHERE `status` = \'pending\'/', static function () use ($flusher): void {
                $flusher->flush();
            });
            self::fail('The flush went on without its rows.');
        } catch (SqlException $error) {
            self::assertSame([], $handler->batches);
            self::assertSame(EventQueue::STATUS_PENDING, $this->row($id)['status']);
        }

        self::assertSame(1, $flusher->flush());
        self::assertSame(EventQueue::STATUS_SENT, $this->row($id)['status']);
    }

    /**
     * The write that closes rows is tried twice: a row that went out and stays claimed is sent
     * again once its claim is stale.
     */
    public function testAStatusWriteThatFailsOnceIsTriedAgain(): void
    {
        $first = $this->queue->enqueue('test.sync', 1, ['n' => 1]);
        $second = $this->queue->enqueue('test.sync', 1, ['n' => 2]);
        $flusher = $this->flusher(['test.sync' => new FakeHandler(fn (array $row) => true)]);

        $failed = FailingQueries::during('/^UPDATE .* SET `status` = \'sent\'/', static function () use ($flusher): void {
            $flusher->flush();
        }, 1);

        self::assertSame(1, $failed);
        self::assertSame([EventQueue::STATUS_SENT, EventQueue::STATUS_SENT], [$this->row($first)['status'], $this->row($second)['status']]);
    }

    /**
     * One row went out and another failed, and the failed row's outcome cannot be written. The
     * flush ends with the error, and the row that went out is still recorded as sent, so it is
     * not sent again. The other row stays claimed and returns to the queue with its claim.
     */
    public function testARowThatWentOutIsRecordedWhenAnotherRowsOutcomeCouldNotBeWritten(): void
    {
        $failing = $this->queue->enqueue('test.sync', 1, ['fails' => true]);
        $sent = $this->queue->enqueue('test.sync', 1, ['fails' => false]);
        $handler = new FakeHandler(fn (array $row) => json_decode($row['payload'], true)['fails'] ? Failure::temporary('Smaily answered HTTP 503') : true);
        $flusher = $this->flusher(['test.sync' => $handler]);

        try {
            FailingQueries::during('/^UPDATE .* SET `status` = \'pending\', `attempts` = 1/', static function () use ($flusher): void {
                $flusher->flush();
            });
            self::fail('The flush hid a write that failed.');
        } catch (SqlException $error) {
            self::assertSame(EventQueue::STATUS_SENT, $this->row($sent)['status']);
            self::assertSame([EventQueue::STATUS_PROCESSING, 0], [$this->row($failing)['status'], (int) $this->row($failing)['attempts']]);
        }

        $this->now += EventQueue::CLAIM_TIMEOUT_SECONDS;
        self::assertSame(1, $flusher->flush());
        self::assertSame([[$failing, $sent], [$failing]], $handler->batches, 'only the row that was not recorded is handled again');
        self::assertSame([EventQueue::STATUS_PENDING, 1], [$this->row($failing)['status'], (int) $this->row($failing)['attempts']]);
    }

    /**
     * An event that the queue could not store is an error for the code that queued it.
     */
    public function testAnEventThatCouldNotBeStoredThrows(): void
    {
        $this->expectException(SqlException::class);

        FailingQueries::during('/^INSERT INTO/', function (): void {
            $this->queue->enqueue('test.sync', 1, []);
        });
    }

    public function testTheProviderBuildsAFlusherThatFindsARegisteredHandler(): void
    {
        $services = Services::fromProviders([QueueProvider::class]);
        $handler = new FakeHandler(fn (array $row) => true);
        $services->set(QueueProvider::handlerId('test.sync'), fn (): HandlerInterface => $handler);

        $id = $services->get(QueueProvider::EVENT_QUEUE)->enqueue('test.sync', 1, []);
        $services->get(QueueProvider::FLUSHER)->flush();

        self::assertSame([[$id]], $handler->batches);
        self::assertSame(EventQueue::STATUS_SENT, $this->row($id)['status']);
    }

    /**
     * @param array<string, HandlerInterface> $handlers
     */
    private function flusher(array $handlers): Flusher
    {
        return new Flusher($this->queue, fn (string $eventType): ?HandlerInterface => $handlers[$eventType] ?? null);
    }

    /**
     * @return array<string, string|null>
     */
    private function row(int $id): array
    {
        $row = self::db()->getRow('SELECT * FROM `' . _DB_PREFIX_ . 'smaily_connect_event_queue` WHERE `id` = ' . $id);
        self::assertIsArray($row, 'row ' . $id . ' exists');

        return $row;
    }

    private static function clearQueue(): void
    {
        self::db()->execute('DELETE FROM `' . _DB_PREFIX_ . 'smaily_connect_event_queue`');
    }
}

/**
 * A handler that decides each row's outcome with a callback and remembers the row ids of each
 * batch it was handed.
 */
final class FakeHandler implements HandlerInterface
{
    /** @var list<list<int>> */
    public array $batches = [];

    /** @var callable(array<string, mixed>): mixed */
    private $outcome;

    /**
     * @param callable(array<string, mixed>): mixed $outcome
     */
    public function __construct(callable $outcome)
    {
        $this->outcome = $outcome;
    }

    public function handle(array $events): array
    {
        $this->batches[] = array_map(fn (array $row): int => (int) $row['id'], $events);
        $outcomes = [];
        foreach ($events as $row) {
            $outcomes[(int) $row['id']] = ($this->outcome)($row);
        }

        return $outcomes;
    }
}
