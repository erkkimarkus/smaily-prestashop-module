<?php
declare(strict_types=1);

namespace Tests\Integration\Automation\AbandonedCart;

use PrestaShop\Module\SmailyForPrestaShop\Automation\AbandonedCart\AbandonedCartHandler;
use PrestaShop\Module\SmailyForPrestaShop\Automation\AbandonedCart\AbandonedCartSweeper;
use PrestaShop\Module\SmailyForPrestaShop\Automation\AbandonedCart\CartTracker;
use PrestaShop\Module\SmailyForPrestaShop\Config\TickSettings;
use PrestaShop\Module\SmailyForPrestaShop\Scheduler\Tick;
use Tests\Integration\Migration\LegacyStore;

require_once __DIR__ . '/AbandonedCartTestCase.php';

/**
 * The reminder of a left cart, from the sweep through the queue to the Smaily mock: once per cart,
 * once per address and day, never for a cart older than a day, and never after the address
 * ordered.
 */
final class AbandonedCartFlowTest extends AbandonedCartTestCase
{
    /**
     * Proof 1: a logged-in customer's cart, left past the delay, gets one reminder through the
     * account and the workflow; a second sweep sends nothing.
     */
    public function testALeftCartGetsOneReminderThroughTheTick(): void
    {
        $idCart = $this->fixture->cart($this->fixture->customer(self::ADDRESS), 20);
        $tick = $this->services->get(Tick::class);

        $tick->run('tick', 50);
        self::assertSame(AbandonedCartHandler::EVENT_TYPE, $this->events()[0]['event_type'] ?? null, 'the sweep queued the reminder');
        \Configuration::deleteByName(TickSettings::TICK_STATE);
        LegacyStore::reloadConfiguration();
        $tick->run('tick', 50);

        $reminders = $this->reminders();
        self::assertCount(1, $reminders);
        self::assertSame(['api-user', self::WORKFLOW, false], [$reminders[0]['username'], $reminders[0]['body']['autoresponder'], $reminders[0]['body']['force_opt_in']]);
        $address = $reminders[0]['body']['addresses'][0];
        self::assertSame(self::ADDRESS, $address['email']);
        self::assertSame(['true', false, 'Testfirst', 'Testlast'], [$address['is_abandoned_cart'], $address['force_opt_in'], $address['first_name'], $address['last_name']]);
        self::assertNotSame('', $address['product_name_1']);
        self::assertSame('', $address['product_name_3']);
        self::assertFalse($address['over_10_products']);
        self::assertMatchesRegularExpression('/^\d{4}-\d\d-\d\d \d\d:\d\d:\d\d$/', $address['abandoned_cart_automation_at']);
        self::assertSame([CartTracker::MAILED, self::ADDRESS], [$this->cartRow($idCart)['status'], $this->cartRow($idCart)['email']]);
        self::assertNotNull($this->cartRow($idCart)['mailed_at']);
        self::assertSame('sent', $this->events()[0]['status']);

        self::assertSame(0, $this->sweep());
        self::assertSame(0, $this->flush());
        self::assertCount(1, $this->reminders());
    }

    public function testACartBeforeItsDelayOrWithoutProductsOrAddressWaits(): void
    {
        $idCustomer = $this->fixture->customer(self::ADDRESS);
        $this->fixture->cart($idCustomer, 10);
        $empty = $this->fixture->cart($this->fixture->customer('shopper2@example.test'), 20, 0);
        $anonymous = $this->fixture->cart(0, 20);

        self::assertSame(0, $this->sweep());
        self::assertNull($this->cartRow($empty));
        self::assertNull($this->cartRow($anonymous));
    }

    /**
     * Proof 3: a cart whose last change is more than 24 hours old is never reminded, at the sweep
     * or when its queued reminder is sent later.
     */
    public function testACartOlderThan24HoursIsNeverReminded(): void
    {
        $old = $this->fixture->cart($this->fixture->customer(self::ADDRESS), 24 * 60 + 1);
        self::assertSame(0, $this->sweep());
        self::assertNull($this->cartRow($old));

        $idCart = $this->fixture->cart($this->fixture->customer('shopper2@example.test'), 20);
        self::assertSame(1, $this->sweep());
        self::db()->update('smaily_connect_cart', ['last_activity_at' => date('Y-m-d H:i:s', time() - 86401)], '`id_cart` = ' . $idCart);
        $this->flush();

        self::assertSame([], $this->reminders());
        self::assertSame([CartTracker::SKIPPED, 'too_old'], [$this->cartRow($idCart)['status'], $this->cartRow($idCart)['skip_reason']]);
        self::assertSame(['skipped', 'too_old'], [$this->events()[0]['status'], $this->events()[0]['last_error']]);
    }

    /**
     * Proof 6: two carts of one address within 24 hours get one reminder; the other is closed as
     * recently reminded, and the Log shows it.
     */
    public function testTwoCartsOfOneAddressGetOneReminder(): void
    {
        $first = $this->fixture->cart($this->fixture->customer(self::ADDRESS), 30);
        $second = $this->fixture->cart($this->fixture->customer(self::ADDRESS, true), 20);

        self::assertSame(2, $this->sweep());
        $this->flush();
        $later = $this->fixture->cart($this->fixture->customer(strtoupper(self::ADDRESS), true), 16);
        $this->sweep();
        $this->flush();

        self::assertCount(1, $this->reminders());
        self::assertSame(CartTracker::MAILED, $this->cartRow($first)['status']);
        foreach ([$second, $later] as $idCart) {
            self::assertSame([CartTracker::SKIPPED, AbandonedCartSweeper::SKIPPED_RECENTLY_REMINDED], [$this->cartRow($idCart)['status'], $this->cartRow($idCart)['skip_reason']]);
        }
        self::assertSame(['sent', 'skipped', 'skipped'], array_column($this->events(), 'status'));
        self::assertSame([null, 'recently_reminded', 'recently_reminded'], array_column($this->events(), 'last_error'));
    }

    /**
     * Proof 2, the owner's client's case: cart A is left after a failed payment, and the shopper
     * orders with cart B before cart A's reminder goes. Here the order comes before the sweep.
     */
    public function testAnOrderBeforeTheSweepWithdrawsTheReminder(): void
    {
        $idCustomer = $this->fixture->customer(self::ADDRESS);
        $cartA = $this->fixture->cart($idCustomer, 40);
        $cartB = $this->fixture->cart($idCustomer, 30);
        $this->fixture->order($cartB);
        $this->fixture->age($cartA, 40);

        $this->sweep();
        $this->flush();

        self::assertSame([], $this->reminders());
        self::assertSame([CartTracker::SKIPPED, 'ordered'], [$this->cartRow($cartA)['status'], $this->cartRow($cartA)['skip_reason']]);
        self::assertNull($this->cartRow($cartB), 'an ordered cart is no candidate');
        self::assertSame([['skipped', 'ordered']], array_map(static fn (array $row): array => [$row['status'], $row['last_error']], $this->events()));
    }

    /**
     * Proof 2: the order comes after the sweep queued cart A's reminder, before the flush.
     */
    public function testAnOrderAfterTheSweepWithdrawsTheQueuedReminder(): void
    {
        $idCustomer = $this->fixture->customer(self::ADDRESS);
        $cartA = $this->fixture->cart($idCustomer, 40);
        self::assertSame(1, $this->sweep());
        self::assertSame(CartTracker::OPEN, $this->cartRow($cartA)['status']);

        $cartB = $this->fixture->cart($idCustomer, 0);
        $this->fixture->order($cartB);
        self::assertSame([CartTracker::SKIPPED, 'ordered'], [$this->cartRow($cartA)['status'], $this->cartRow($cartA)['skip_reason']], 'the order hook withdrew it');
        $this->flush();

        self::assertSame([], $this->reminders());
        self::assertSame([['skipped', 'ordered']], array_map(static fn (array $row): array => [$row['status'], $row['last_error']], $this->events()));
    }

    /**
     * A reminder already sent is not recalled by a later order.
     */
    public function testAnOrderLeavesASentReminderAsItIs(): void
    {
        $idCustomer = $this->fixture->customer(self::ADDRESS);
        $cartA = $this->fixture->cart($idCustomer, 40);
        $this->sweep();
        $this->flush();
        $this->fixture->order($this->fixture->cart($idCustomer, 0));

        self::assertSame(CartTracker::MAILED, $this->cartRow($cartA)['status']);
        self::assertCount(1, $this->reminders());
    }
}
