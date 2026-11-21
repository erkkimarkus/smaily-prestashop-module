<?php
declare(strict_types=1);

namespace Tests\Integration\Engine;

use PrestaShop\Module\SmailyForPrestaShop\Automation\AbandonedCart\CartTracker;
use PrestaShop\Module\SmailyForPrestaShop\Discount\IssuedCodes;
use PrestaShop\Module\SmailyForPrestaShop\Engine\Capture\IdentityCapture;
use PrestaShop\Module\SmailyForPrestaShop\Engine\Ingest\IngestQueue;
use PrestaShop\Module\SmailyForPrestaShop\Log\FailureMessage;
use PrestaShop\Module\SmailyForPrestaShop\Log\LogFilter;
use PrestaShop\Module\SmailyForPrestaShop\Log\LogRows;
use PrestaShop\Module\SmailyForPrestaShop\Log\Resend;
use PrestaShop\Module\SmailyForPrestaShop\Log\ResendGuard;
use PrestaShop\Module\SmailyForPrestaShop\Log\Retry;
use PrestaShop\Module\SmailyForPrestaShop\Queue\EventQueue;
use PrestaShop\Module\SmailyForPrestaShop\Scheduler\Deadline;
use PrestaShop\Module\SmailyForPrestaShop\Scheduler\QueueJanitor;

require_once __DIR__ . '/IngestTestCase.php';

/**
 * The ingest rows in the Log and in the janitor's sweep: "Send again" and "Retry" as the Magento
 * connector offers them for an engine row, with its refusals and no other, and the retention
 * periods of the event queue.
 */
final class IngestLogTest extends IngestTestCase
{
    private const START = 1893456000; // 2030-01-01 00:00:00 UTC

    private const DAY = 86400;

    public function testSendAgainQueuesANewRowThatSendsTheProductAsItIsThen(): void
    {
        $connection = $this->connect();
        $product = new \Product(1);
        $product->save();
        $this->commit();
        $this->engine->fail('catalog', 400, 'validation_failed');
        $this->flush();
        $this->engine->set('fail', []);
        $failed = self::logRows()->find('intelligence-' . self::rows()[0]['id']);
        self::assertSame([EventQueue::STATUS_FAILED, 'permanent_http_400: validation_failed'], [$failed['status'], $failed['last_error']]);
        self::assertSame([], (new ResendGuard())->ingestRefusals([(int) $failed['id'] => $failed]));

        $copy = (new Resend($this->services->get(\PrestaShop\Module\SmailyForPrestaShop\Runtime\Provider\QueueProvider::EVENT_QUEUE), new CartTracker(), $this->services->get(IngestQueue::class)))->resend($failed, 'Testfirst Testlast');
        $this->flush();

        [$before, $after] = self::rows();
        self::assertSame(EventQueue::STATUS_FAILED, $before['status'], 'the failed row stays as the history of what went wrong');
        self::assertSame([(string) $copy, (string) $connection->id(), IngestQueue::DOMAIN_CATALOG, '1', EventQueue::STATUS_SENT], [$after['id'], $after['id_connection'], $after['domain'], $after['entity_key'], $after['status']]);
        $record = json_decode((string) $after['payload'], true)[Resend::PAYLOAD_KEY];
        self::assertSame([(int) $before['id'], 'Testfirst Testlast'], [$record['of'], $record['by']]);
        $requests = $this->engine->requestsTo(self::CATALOG);
        self::assertCount(8, end($requests)['body']['products']);
        self::assertStringNotContainsString('_resend', end($requests)['raw'], 'the record of the decision never reaches the engine');
    }

    public function testRetryReturnsTheFailedIngestRowsToPendingAndLeavesTheRefusedOnesAlone(): void
    {
        $connection = $this->connect();
        $queue = $this->services->get(IngestQueue::class);
        $failed = self::store($queue->enqueue($connection->id(), IngestQueue::DOMAIN_CATALOG, '1'), ['status' => 'failed', 'attempts' => 5]);
        $other = self::store($queue->enqueue($connection->id(), IngestQueue::DOMAIN_ORDERS, '987654'), ['status' => 'failed', 'attempts' => 1]);
        $sent = self::store($queue->enqueue($connection->id(), IngestQueue::DOMAIN_CUSTOMERS, '987654'), ['status' => 'sent']);
        $erased = self::store($queue->enqueue($connection->id(), IngestQueue::DOMAIN_CUSTOMERS, ResendGuard::ERASED_ENTITY), ['status' => 'failed']);
        $nightly = $queue->record($connection->id(), IngestQueue::DOMAIN_MANIFEST, '', ['count' => 3], null, 'network_error');

        $guard = new ResendGuard();
        self::assertSame(
            [$sent => ResendGuard::REASON_NOT_FAILED, $erased => ResendGuard::REASON_ERASED, $nightly => ResendGuard::REASON_NIGHTLY],
            $guard->ingestRefusals(self::logRows()->ingestRows([$failed, $other, $sent, $erased, $nightly]))
        );
        self::assertSame('The product list is built afresh each night, so this one is not sent again.', ResendGuard::message(ResendGuard::REASON_NIGHTLY));

        $retry = new Retry(self::logRows(), $guard, $this->services->get(\PrestaShop\Module\SmailyForPrestaShop\Runtime\Provider\QueueProvider::EVENT_QUEUE), $queue);
        self::assertSame([1, 0], $retry->retry(new LogFilter(), [], [$failed, $sent]), 'the ticked rows: a row that did not fail is no part of a retry');
        self::assertSame([EventQueue::STATUS_PENDING, '0'], [self::rows()[0]['status'], self::rows()[0]['attempts']]);
        self::assertSame([1, 2], $retry->retry(new LogFilter(), null), 'every failed row of the list: the erased row and the nightly list are left alone');
        self::assertSame(
            [EventQueue::STATUS_PENDING, EventQueue::STATUS_PENDING, EventQueue::STATUS_SENT, EventQueue::STATUS_FAILED, EventQueue::STATUS_FAILED],
            array_column(self::rows(), 'status')
        );
    }

    public function testAFailedIdentityMergeMayGoAgainWhateverLaterMergeOfTheContactWasDelivered(): void
    {
        $queue = new EventQueue();
        $failed = $queue->enqueue(IdentityCapture::EVENT_TYPE, 1, ['id_customer' => 987654], 'shopper31@example.test');
        $later = $queue->enqueue(IdentityCapture::EVENT_TYPE, 1, ['id_customer' => 987654], 'shopper31@example.test');
        self::db()->update(EventQueue::TABLE, ['status' => 'failed'], '`id` = ' . $failed);
        self::db()->update(EventQueue::TABLE, ['status' => 'sent'], '`id` = ' . $later);

        // A merge repeats a state, not a message: Magento's "superseded" is for automations only.
        self::assertSame([], (new ResendGuard())->refusals(self::logRows()->eventRows([$failed])));
    }

    public function testTheJanitorSweepsTheIngestQueueByTheEventQueuesPeriods(): void
    {
        $connection = $this->connect();
        $now = self::START;
        $queue = new IngestQueue(static function () use (&$now): int {
            return $now;
        });
        $ids = [];
        foreach ([['sent', 31], ['sent', 29], ['skipped', 31], ['failed', 89], ['failed', 91], ['pending', 400]] as [$status, $days]) {
            $now = self::START - $days * self::DAY;
            $ids[$status . $days] = self::store($queue->enqueue($connection->id(), IngestQueue::DOMAIN_CATALOG, '1'), ['status' => $status, 'updated_at' => gmdate('Y-m-d H:i:s', $now)]);
        }
        $now = self::START;
        $janitor = new QueueJanitor(new EventQueue(static fn (): int => self::START), new CartTracker(), new IssuedCodes(), QueueJanitor::BATCH_SIZE, $queue);

        self::assertSame(3, $janitor->run(new Deadline(microtime(true) + 60, static fn (): float => microtime(true))));

        self::assertSame(
            [$ids['sent29'], $ids['failed89'], $ids['pending400']],
            array_map('intval', array_column(self::rows(), 'id')),
            'a sent or skipped row goes after 30 days, a failed one after 90, a waiting one never'
        );
    }

    private static function logRows(): LogRows
    {
        return new LogRows(null, static fn (string $term): array => FailureMessage::codesShowing($term, static fn (string $text): string => $text));
    }

    /**
     * Sets columns of an ingest row as the queue would store them.
     *
     * @param array<string, mixed> $columns
     */
    private static function store(int $id, array $columns): int
    {
        self::db()->update(IngestQueue::TABLE, $columns, '`id` = ' . $id);

        return $id;
    }
}
