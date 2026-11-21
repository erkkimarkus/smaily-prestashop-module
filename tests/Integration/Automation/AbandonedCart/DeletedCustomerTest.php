<?php
declare(strict_types=1);

namespace Tests\Integration\Automation\AbandonedCart;

use PrestaShop\Module\SmailyForPrestaShop\Automation\AbandonedCart\AbandonedCartHandler;
use PrestaShop\Module\SmailyForPrestaShop\Automation\AbandonedCart\CartTracker;
use PrestaShop\Module\SmailyForPrestaShop\Log\FailureMessage;

require_once __DIR__ . '/AbandonedCartTestCase.php';

/**
 * A cart whose customer was deleted gets no reminder, also not at the address its checkout
 * recorded before the customer existed, and the recorded address is removed. A guest's cart that
 * never had a customer is reminded as before.
 */
final class DeletedCustomerTest extends AbandonedCartTestCase
{
    private const TYPED = 'capture8@example.test';

    /**
     * @return array<string, array{string}>
     */
    public function deletions(): array
    {
        return ['the customer is flagged deleted' => ['flag'], 'the customer row is removed' => ['row']];
    }

    /**
     * @dataProvider deletions
     */
    public function testACartOfADeletedCustomerGetsNoReminderAtItsRecordedAddress(string $deletion): void
    {
        $idCart = $this->recorded();
        $idCustomer = $this->fixture->customer(self::TYPED, true);
        self::db()->update('cart', ['id_customer' => $idCustomer], '`id_cart` = ' . $idCart);
        $this->delete($idCustomer, $deletion);

        self::assertSame(1, $this->sweep());
        $this->flush();

        self::assertSame([], $this->reminders());
        $row = $this->cartRow($idCart);
        self::assertSame([CartTracker::SKIPPED, AbandonedCartHandler::SKIPPED_CUSTOMER_DELETED, null], [$row['status'], $row['skip_reason'], $row['email']]);
        $event = $this->events()[0];
        self::assertSame(['skipped', 'customer_deleted', null], [$event['status'], $event['last_error'], $event['entity_id']]);
        self::assertStringNotContainsString(self::TYPED, (string) json_encode($event), 'the Log row holds no address');
        self::assertSame(
            'Skipped: this shopper\'s customer account was deleted in the store. Nothing was sent.',
            FailureMessage::forDisplay($event['last_error'], static fn (string $sentence): string => $sentence)
        );
    }

    /**
     * @dataProvider deletions
     */
    public function testACustomerDeletedAfterTheReminderWasQueuedGetsNoReminder(string $deletion): void
    {
        $recorded = $this->recorded();
        $guest = $this->fixture->customer(self::TYPED, true);
        self::db()->update('cart', ['id_customer' => $guest], '`id_cart` = ' . $recorded);
        $registered = $this->fixture->customer(self::ADDRESS);
        $customers = $this->fixture->cart($registered, 20);
        self::assertSame(2, $this->sweep());

        $this->delete($guest, $deletion);
        $this->delete($registered, $deletion);
        $this->flush();

        self::assertSame([], $this->reminders());
        foreach ([$recorded, $customers] as $idCart) {
            $row = $this->cartRow($idCart);
            self::assertSame([CartTracker::SKIPPED, 'customer_deleted', null], [$row['status'], $row['skip_reason'], $row['email']]);
        }
        self::assertSame([['skipped', 'customer_deleted'], ['skipped', 'customer_deleted']], array_map(static fn (array $event): array => [$event['status'], $event['last_error']], $this->events()));
        foreach ($this->events() as $event) {
            self::assertSame([null, null], [$event['entity_id'], $event['last_request']]);
            self::assertSame(['id_cart'], array_keys(json_decode((string) $event['payload'], true)), 'the queue row keeps the cart alone');
            self::assertStringNotContainsString('@', (string) json_encode($event), 'the Log row holds no address');
        }
    }

    /**
     * @dataProvider deletions
     */
    public function testACustomerDeletedAfterAnAttemptFailedLeavesNoAddressInTheQueueRow(string $deletion): void
    {
        $registered = $this->fixture->customer(self::ADDRESS);
        $this->fixture->cart($registered, 20);
        self::assertSame(1, $this->sweep());
        $this->mock->failAutomationPosts(true);
        $this->flush();
        $this->mock->failAutomationPosts(false);
        $attempted = $this->events()[0];
        self::assertSame(['pending', '1'], [$attempted['status'], (string) $attempted['attempts']]);
        self::assertStringContainsString(self::ADDRESS, (string) $attempted['last_request'], 'the failed attempt left what it sent');

        $this->delete($registered, $deletion);
        self::db()->update('smaily_connect_event_queue', ['next_retry_at' => null], '`id` = ' . (int) $attempted['id'], 0, true);
        $this->flush();

        $event = $this->events()[0];
        self::assertSame(['skipped', 'customer_deleted', null, null, null], [$event['status'], $event['last_error'], $event['entity_id'], $event['last_request'], $event['last_response']]);
        self::assertStringNotContainsString('@', (string) json_encode($event), 'the Log row holds no address');
        self::assertCount(1, $this->reminders(), 'nothing more was sent');
    }

    public function testAGuestsCartThatNeverHadACustomerIsRemindedAsBefore(): void
    {
        $idCart = $this->recorded();

        self::assertSame(1, $this->sweep());
        $this->flush();

        self::assertSame([self::TYPED], array_map(static fn (array $reminder): string => $reminder['body']['addresses'][0]['email'], $this->reminders()));
        self::assertSame([CartTracker::MAILED, self::TYPED], [$this->cartRow($idCart)['status'], $this->cartRow($idCart)['email']]);
    }

    /**
     * A guest's cart, last changed 20 minutes ago, whose checkout recorded TYPED with the box ticked.
     */
    private function recorded(): int
    {
        $idCart = $this->fixture->cart(0, 20);
        (new CartTracker())->capture($idCart, $this->idShop, (int) \Configuration::get('PS_LANG_DEFAULT'), self::TYPED, true);

        return $idCart;
    }

    private function delete(int $idCustomer, string $deletion): void
    {
        if ($deletion === 'flag') {
            self::db()->update('customer', ['deleted' => 1], '`id_customer` = ' . $idCustomer);
        } else {
            self::db()->delete('customer', '`id_customer` = ' . $idCustomer);
        }
    }
}
