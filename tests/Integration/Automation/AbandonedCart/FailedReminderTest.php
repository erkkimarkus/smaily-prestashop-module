<?php
declare(strict_types=1);

namespace Tests\Integration\Automation\AbandonedCart;

use PrestaShop\Module\SmailyForPrestaShop\Automation\AbandonedCart\AbandonedCartSweeper;
use PrestaShop\Module\SmailyForPrestaShop\Automation\AbandonedCart\Capture\CapturedAddress;
use PrestaShop\Module\SmailyForPrestaShop\Automation\AbandonedCart\CartTracker;
use PrestaShop\Module\SmailyForPrestaShop\Log\Resend;
use PrestaShop\Module\SmailyForPrestaShop\Runtime\Provider\QueueProvider;

require_once __DIR__ . '/AbandonedCartTestCase.php';

/**
 * A reminder that failed and was parked leaves its cart's tracker row open. That row holds its
 * address back only while the reminder can still go, 24 hours from the cart's last change, and
 * loses its address then; the Log's retry of the queue row still delivers within that time.
 */
final class FailedReminderTest extends AbandonedCartTestCase
{
    private const TYPED = 'capture7@example.test';

    public function testAParkedReminderHoldsItsAddressBackOnlyWhileItCanStillGo(): void
    {
        $idCustomer = $this->fixture->customer(self::ADDRESS);
        $failed = $this->park($this->fixture->cart($idCustomer, 30));

        $soon = $this->fixture->cart($idCustomer, 20);
        $this->sweep();
        $this->flush();
        self::assertSame([CartTracker::SKIPPED, AbandonedCartSweeper::SKIPPED_RECENTLY_REMINDED], [$this->cartRow($soon)['status'], $this->cartRow($soon)['skip_reason']], 'the parked reminder can still go');
        self::assertSame([], $this->reminders());

        $this->moveLastChange($failed, CartTracker::WINDOW_SECONDS + 1);
        $later = $this->fixture->cart($idCustomer, 20);
        $this->sweep();
        $this->flush();

        self::assertCount(1, $this->reminders());
        self::assertSame(CartTracker::MAILED, $this->cartRow($later)['status']);
        self::assertSame([CartTracker::OPEN, 'failed'], [$this->cartRow($failed)['status'], $this->event($failed)['status']], 'the parked row stays as it is');
    }

    public function testAParkedReminderOfARecordedAddressFreesTheAddressToo(): void
    {
        $failed = $this->park($this->recorded());
        $this->moveLastChange($failed, CartTracker::WINDOW_SECONDS + 1);
        $later = $this->recorded();

        $this->sweep();
        $this->flush();

        self::assertSame([self::TYPED], array_map(static fn (array $reminder): string => $reminder['body']['addresses'][0]['email'], $this->reminders()));
        self::assertSame(CartTracker::MAILED, $this->cartRow($later)['status']);
    }

    public function testAParkedRemindersAddressIsClearedAfter24HoursAndTheRowStays(): void
    {
        $recorded = $this->park($this->recorded());
        $customers = $this->park($this->fixture->cart($this->fixture->customer(self::ADDRESS), 20));

        $this->sweep();
        self::assertSame([self::TYPED, self::ADDRESS], [$this->cartRow($recorded)['email'], $this->cartRow($customers)['email']], 'kept while the reminder can still go');

        $events = [(int) $this->cartRow($recorded)['event_id'], (int) $this->cartRow($customers)['event_id']];
        $this->moveLastChange($recorded, CartTracker::WINDOW_SECONDS + 1);
        $this->moveLastChange($customers, CartTracker::WINDOW_SECONDS + 1);
        $this->sweep();

        foreach ([$recorded, $customers] as $i => $idCart) {
            $row = $this->cartRow($idCart);
            self::assertSame([CartTracker::OPEN, $events[$i], null], [$row['status'], (int) $row['event_id'], $row['email']]);
        }
        self::assertNull($this->services->get(CapturedAddress::class)->forCart($recorded));
        self::assertSame(self::TYPED, json_decode((string) $this->event($recorded)['payload'], true)['email'], 'the queue row keeps what Send again sends');
    }

    public function testARetryFromTheLogDeliversAParkedReminderWhileItCanStillGo(): void
    {
        $idCart = $this->park($this->recorded());
        $later = $this->recorded();

        self::assertSame(1, $this->services->get(QueueProvider::EVENT_QUEUE)->retry([(int) $this->cartRow($idCart)['event_id']]));
        $this->sweep();
        $this->flush();

        self::assertCount(1, $this->reminders());
        self::assertSame([CartTracker::MAILED, 'sent'], [$this->cartRow($idCart)['status'], $this->event($idCart)['status']]);
        self::assertSame([CartTracker::SKIPPED, AbandonedCartSweeper::SKIPPED_RECENTLY_REMINDED], [$this->cartRow($later)['status'], $this->cartRow($later)['skip_reason']]);
    }

    public function testSendAgainAfterTheAddressWasClearedSendsNothingAsBefore(): void
    {
        $idCart = $this->park($this->recorded());
        $this->moveLastChange($idCart, CartTracker::WINDOW_SECONDS + 1);
        $this->sweep();

        $copy = (new Resend($this->services->get(QueueProvider::EVENT_QUEUE), new CartTracker()))->resend($this->event($idCart), 'Testfirst Testlast');
        $this->flush();

        self::assertSame([], $this->reminders());
        self::assertSame([CartTracker::SKIPPED, 'too_old', $copy], [$this->cartRow($idCart)['status'], $this->cartRow($idCart)['skip_reason'], (int) $this->cartRow($idCart)['event_id']]);
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

    /**
     * Queues the cart's reminder and lets it fail for good: Smaily refuses the credentials.
     */
    private function park(int $idCart): int
    {
        $this->mock->acceptCredentials('api-user', 'changed-in-smaily');
        $this->sweep();
        $this->flush();
        $this->mock->acceptCredentials('api-user', 'not-a-real-password');
        self::assertSame([CartTracker::OPEN, 'failed'], [$this->cartRow($idCart)['status'], $this->event($idCart)['status']]);

        return $idCart;
    }

    /**
     * Moves the cart's last change $seconds into the past, in the cart and in its tracker row.
     */
    private function moveLastChange(int $idCart, int $seconds): void
    {
        $at = date('Y-m-d H:i:s', time() - $seconds);
        self::db()->update('cart', ['date_upd' => $at], '`id_cart` = ' . $idCart);
        self::db()->update('smaily_connect_cart', ['last_activity_at' => $at], '`id_cart` = ' . $idCart);
    }

    /**
     * The queue row that the cart's tracker row names.
     *
     * @return array<string, string|null>
     */
    private function event(int $idCart): array
    {
        return self::db()->getRow('SELECT * FROM `' . _DB_PREFIX_ . 'smaily_connect_event_queue` WHERE `id` = ' . (int) $this->cartRow($idCart)['event_id'], false);
    }
}
