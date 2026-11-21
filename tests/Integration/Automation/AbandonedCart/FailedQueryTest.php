<?php
declare(strict_types=1);

namespace Tests\Integration\Automation\AbandonedCart;

use PrestaShop\Module\SmailyForPrestaShop\Automation\AbandonedCart\AbandonedCartSweeper;
use PrestaShop\Module\SmailyForPrestaShop\Automation\AbandonedCart\CartTracker;
use PrestaShop\Module\SmailyForPrestaShop\Database\SqlException;
use PrestaShop\Module\SmailyForPrestaShop\Queue\EventQueue;
use Tests\Integration\Fixtures\FailingQueries;

require_once __DIR__ . '/AbandonedCartTestCase.php';
require_once __DIR__ . '/../../Fixtures/FailingQueries.php';

/**
 * A read that fails is not "nothing found". A live PrestaShop 9.2.0 store reports a failed query
 * (a lock wait that ran out, a deadlock, a lost connection) as `false` and throws nothing (facts
 * file 6.11), and each check of the reminder took that for the answer that lets the reminder go:
 * no recent reminder, no order, a customer who still exists. Now the sweep stops and the next one
 * decides, and a queue row fails with nothing sent and takes the retry ladder.
 */
final class FailedQueryTest extends AbandonedCartTestCase
{
    /**
     * An address gets one reminder in 24 hours. The read of the recent reminders fails: the sweep
     * queues nothing, and the next sweep closes the second cart as recently reminded.
     */
    public function testASweepThatCouldNotReadTheRecentRemindersQueuesNothing(): void
    {
        $first = $this->fixture->cart($this->fixture->customer(self::ADDRESS), 30);
        self::assertSame(1, $this->sweep());
        $this->flush();
        self::assertSame(CartTracker::MAILED, $this->cartRow($first)['status']);
        $second = $this->fixture->cart($this->fixture->customer(self::ADDRESS, true), 16);

        try {
            FailingQueries::during('/SELECT `email`, `id_customer`, `status`, `mailed_at`, `event_id` FROM/', function (): void {
                $this->sweep();
            });
            self::fail('The sweep went on without the recent reminders.');
        } catch (SqlException $error) {
            self::assertNull($this->cartRow($second), 'the cart is not taken');
            self::assertCount(1, $this->events());
        }

        self::assertSame(1, $this->sweep());
        $this->flush();
        self::assertSame([CartTracker::SKIPPED, AbandonedCartSweeper::SKIPPED_RECENTLY_REMINDED], [$this->cartRow($second)['status'], $this->cartRow($second)['skip_reason']]);
        self::assertCount(1, $this->reminders(), 'the address got one reminder');
    }

    /**
     * @return array<string, array{string, string, string, string|null, int}>
     */
    public function checks(): array
    {
        return [
            'whether the address ordered' => ['ordered', '/SELECT 1 FROM `[^`]+orders` WHERE `id_cart` = /', 'skipped', 'ordered', 0],
            'whether the customer is gone' => ['deleted', '/^SELECT 1 FROM `[^`]+cart` c LEFT JOIN/', 'skipped', 'customer_deleted', 0],
            'the cart\'s tracker row' => ['ordered', '/^SELECT \* FROM `[^`]+smaily_connect_cart` WHERE `id_cart`/', 'skipped', 'ordered', 0],
            'the Smaily account of the shop' => ['left', '/FROM `[^`]+smaily_connect_account`/', 'sent', null, 1],
            'the workflow of the shop' => ['left', '/FROM `[^`]+smaily_connect_automation_mapping`/', 'sent', null, 1],
        ];
    }

    /**
     * The reminder is queued; then the shopper orders with another cart, or the customer is
     * deleted, or the cart is simply left. A check of the reminder cannot be read when the queue
     * sends: the row fails with nothing sent, and its retry ends as the row would have without
     * the failure.
     *
     * @dataProvider checks
     */
    public function testACheckThatCouldNotBeReadSendsNothingAndTheRetryDecides(string $story, string $failing, string $status, ?string $reason, int $reminders): void
    {
        $idCustomer = $this->fixture->customer(self::ADDRESS);
        $idCart = $this->fixture->cart($idCustomer, 40);
        if ($story === 'ordered') {
            // The order of another cart came before the sweep: only the queue's own check sees it.
            $this->fixture->order($this->fixture->cart($idCustomer, 30));
            $this->fixture->age($idCart, 40);
        }
        self::assertSame(1, $this->sweep());
        if ($story === 'deleted') {
            self::db()->update('customer', ['deleted' => 1], '`id_customer` = ' . $idCustomer);
        }
        $id = (int) $this->events()[0]['id'];

        $failed = FailingQueries::during($failing, function (): void {
            self::assertSame(1, $this->flush());
        });

        self::assertGreaterThan(0, $failed);
        $event = $this->events()[0];
        self::assertSame([EventQueue::STATUS_PENDING, 1], [$event['status'], (int) $event['attempts']], 'the row is retried');
        self::assertStringContainsString('SqlException', (string) $event['last_error']);
        self::assertSame([], $this->reminders(), 'nothing was sent');
        self::assertSame(CartTracker::OPEN, $this->cartRow($idCart)['status']);

        self::db()->update(EventQueue::TABLE, ['next_retry_at' => null], '`id` = ' . $id, 0, true);
        self::assertSame(1, $this->flush());
        self::assertCount($reminders, $this->reminders());
        self::assertSame([[$status, $reason]], array_map(static fn (array $row): array => [$row['status'], $row['last_error']], $this->events()));
    }

    /**
     * Two reminders in one flush. The second row's read fails after the first row was sent: the
     * first row keeps its outcome and is not handled a second time.
     */
    public function testARowWhoseReadFailsLeavesTheRowsBeforeItAsTheyWere(): void
    {
        $first = $this->fixture->cart($this->fixture->customer(self::ADDRESS), 30);
        $second = $this->fixture->cart($this->fixture->customer('shopper2@example.test'), 20);
        self::assertSame(2, $this->sweep());

        $failed = FailingQueries::during('/^SELECT 1 FROM `[^`]+cart` c LEFT JOIN.*`id_cart` = ' . $second . ' /', function (): void {
            self::assertSame(2, $this->flush());
        });

        self::assertSame(1, $failed);
        self::assertSame([['sent', 0], ['pending', 1]], array_map(static fn (array $row): array => [$row['status'], (int) $row['attempts']], $this->events()));
        self::assertSame([CartTracker::MAILED, CartTracker::OPEN], [$this->cartRow($first)['status'], $this->cartRow($second)['status']]);
        self::assertCount(1, $this->reminders());
    }

    /**
     * The reminder went out, and the write that records it on the cart fails twice. The email is
     * out: the queue row is closed as sent and never sent again.
     */
    public function testAReminderThatWentOutIsClosedAsSentWhenItsCartCouldNotBeMarked(): void
    {
        $idCart = $this->fixture->cart($this->fixture->customer(self::ADDRESS), 30);
        self::assertSame(1, $this->sweep());

        $failed = FailingQueries::during('/^UPDATE `[^`]+smaily_connect_cart` SET `status` = \'mailed\'/', function (): void {
            self::assertSame(1, $this->flush());
        });

        self::assertSame(2, $failed, 'the write is tried twice');
        self::assertSame('sent', $this->events()[0]['status']);
        self::assertCount(1, $this->reminders());
        self::assertSame(0, $this->flush());
        self::assertSame(0, $this->sweep(), 'the cart still names its queue row, so no sweep takes it again');
        self::assertCount(1, $this->reminders());
        self::assertSame(CartTracker::OPEN, $this->cartRow($idCart)['status']);
    }
}
