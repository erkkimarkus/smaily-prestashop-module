<?php
declare(strict_types=1);

namespace Tests\Integration\Automation\AbandonedCart\Capture;

use PrestaShop\Module\SmailyForPrestaShop\Automation\AbandonedCart\AbandonedCartSweeper;
use PrestaShop\Module\SmailyForPrestaShop\Automation\AbandonedCart\Capture\CaptureEndpoint;
use PrestaShop\Module\SmailyForPrestaShop\Automation\AbandonedCart\Capture\CapturedAddress;
use PrestaShop\Module\SmailyForPrestaShop\Automation\AbandonedCart\CartTracker;
use PrestaShop\Module\SmailyForPrestaShop\Contacts\ContactSyncMode;
use PrestaShop\Module\SmailyForPrestaShop\Log\FailureMessage;
use Tests\Integration\Automation\AbandonedCart\AbandonedCartTestCase;

require_once __DIR__ . '/../AbandonedCartTestCase.php';

/**
 * An address typed at checkout and recorded through the endpoint serves the cart's reminder under
 * the reminder's consent rule, is withdrawn by an order, and does not outlive its use.
 */
final class CheckoutCaptureFlowTest extends AbandonedCartTestCase
{
    private const TYPED = 'capture3@example.test';

    /**
     * @return array<string, array{string, bool, string|null, bool}>
     */
    public function consents(): array
    {
        return [
            'all customers, box not ticked' => [ContactSyncMode::ALL_CUSTOMERS, false, null, true],
            'subscribers only, box not ticked' => [ContactSyncMode::SUBSCRIBERS, false, null, false],
            'subscribers only, box ticked' => [ContactSyncMode::SUBSCRIBERS, true, null, true],
            'subscribers only, subscribed in Smaily' => [ContactSyncMode::SUBSCRIBERS, false, '0', true],
            'subscribers only, unsubscribed in Smaily, box ticked' => [ContactSyncMode::SUBSCRIBERS, true, '1', false],
        ];
    }

    /**
     * @dataProvider consents
     */
    public function testACapturedAddressIsRemindedUnderTheReminderConsentRule(string $mode, bool $ticked, ?string $smailyStatus, bool $reminded): void
    {
        $this->mode($mode);
        if ($smailyStatus !== null) {
            $this->mock->setContacts([self::TYPED => $smailyStatus]);
        }
        $idCart = $this->capture($ticked);
        $this->fixture->age($idCart, 20);

        self::assertSame(1, $this->sweep());
        $this->flush();

        if ($reminded) {
            self::assertCount(1, $this->reminders());
            self::assertSame(self::TYPED, $this->reminders()[0]['body']['addresses'][0]['email']);
            self::assertSame(CartTracker::MAILED, $this->cartRow($idCart)['status']);
        } else {
            self::assertSame([], $this->reminders());
            self::assertSame(CartTracker::SKIPPED, $this->cartRow($idCart)['status']);
        }
        self::assertSame([], array_filter($this->mock->requests(), static fn (array $request): bool => $request['path'] === '/api/contact.php' && $request['method'] === 'POST'), 'the address is not sent as a contact');
    }

    /**
     * Fresh carts that record one address get one reminder in 24 hours, whoever recorded it; the
     * others are closed, the Log says why, and another address is not held back.
     */
    public function testCartsThatRecordOneAddressGetOneReminderIn24Hours(): void
    {
        $first = $this->capture(true);
        $second = $this->capture(true, strtoupper(self::TYPED));
        $other = $this->capture(true, 'capture6@example.test');
        foreach ([$first, $second, $other] as $i => $idCart) {
            $this->fixture->age($idCart, 30 - $i);
        }
        self::assertSame(3, $this->sweep());
        $this->flush();
        $third = $this->capture(true);
        $this->fixture->age($third, 20);
        $this->sweep();
        $this->flush();

        self::assertSame([self::TYPED, 'capture6@example.test'], array_map(static fn (array $reminder): string => $reminder['body']['addresses'][0]['email'], $this->reminders()));
        foreach ([$second, $third] as $idCart) {
            self::assertSame([CartTracker::SKIPPED, AbandonedCartSweeper::SKIPPED_RECENTLY_REMINDED], [$this->cartRow($idCart)['status'], $this->cartRow($idCart)['skip_reason']]);
        }
        self::assertSame(['sent', 'skipped', 'sent', 'skipped'], array_column($this->events(), 'status'));
        self::assertStringContainsString('already got an abandoned-cart reminder', FailureMessage::forDisplay($this->events()[3]['last_error'], static fn (string $sentence): string => $sentence));
    }

    public function testAnOrderWithTheCapturedAddressWithdrawsTheReminder(): void
    {
        $idCart = $this->capture(false);
        $guest = new \Customer($this->fixture->customer(self::TYPED, true));
        $cart = new \Cart($idCart);
        $cart->id_customer = (int) $guest->id;
        $cart->secure_key = $guest->secure_key;
        $cart->update();
        $this->fixture->order($idCart);
        $this->fixture->age($idCart, 20);

        $this->sweep();
        $this->flush();

        self::assertSame([], $this->reminders());
        self::assertSame([CartTracker::SKIPPED, 'ordered'], [$this->cartRow($idCart)['status'], $this->cartRow($idCart)['skip_reason']]);
    }

    public function testAnUnusedCapturedAddressIsDeletedByTheSweepAfter24Hours(): void
    {
        $stale = $this->capture(false);
        self::db()->update('smaily_connect_cart', ['last_activity_at' => date('Y-m-d H:i:s', time() - 86401)], '`id_cart` = ' . $stale);

        $this->sweep();

        self::assertNull($this->cartRow($stale));
        self::assertNull($this->services->get(CapturedAddress::class)->forCart($stale));
    }

    public function testARemindedCartLosesTheCapturedAddressAfter48Hours(): void
    {
        $idCart = $this->capture(false);
        $this->fixture->age($idCart, 20);
        $this->sweep();
        $this->flush();
        self::db()->update('smaily_connect_cart', ['last_activity_at' => date('Y-m-d H:i:s', time() - 2 * 86400 - 1)], '`id_cart` = ' . $idCart);

        $this->sweep();

        self::assertSame([CartTracker::MAILED, null], [$this->cartRow($idCart)['status'], $this->cartRow($idCart)['email']]);
        self::assertNull($this->services->get(CapturedAddress::class)->forCart($idCart));
    }

    public function testTheCapturedAddressIsReadableForTheVisitorsCart(): void
    {
        $idCart = $this->capture(false);
        $customerCart = $this->fixture->cart($this->fixture->customer('capture4@example.test'), 20);
        $this->sweep();

        $addresses = $this->services->get(CapturedAddress::class);
        self::assertSame(self::TYPED, $addresses->forCart($idCart));
        self::assertNull($addresses->forCart($customerCart), 'a customer\'s own address is not a captured one');
        self::assertNull($addresses->forCart($this->fixture->cart(0, 0)));
    }

    private function capture(bool $ticked, string $address = self::TYPED): int
    {
        $idCart = $this->fixture->cart(0, 0);
        $context = clone \Context::getContext();
        $context->cart = new \Cart($idCart);
        $context->customer = new \Customer();
        $context->cookie = new \Cookie('smaily-capture-test');
        $context->cookie->disallowWriting();
        $recorded = $this->services->get(CaptureEndpoint::class)->handle($context, 'POST', [
            'token' => CaptureEndpoint::token($idCart),
            'email' => $address,
            'newsletter' => $ticked ? '1' : '0',
        ]);
        self::assertTrue($recorded);

        return $idCart;
    }
}
