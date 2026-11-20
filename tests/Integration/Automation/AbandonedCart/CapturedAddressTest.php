<?php
declare(strict_types=1);

namespace Tests\Integration\Automation\AbandonedCart;

use PrestaShop\Module\SmailyForPrestaShop\Automation\AbandonedCart\CartTracker;

require_once __DIR__ . '/AbandonedCartTestCase.php';

/**
 * A cart without a customer whose address a checkout recorded on its tracker row
 * (CartTracker::capture()) is a cart like any other: it is swept, reminded, and withdrawn by an
 * order of that address; a recorded address that served no reminder does not outlive a day.
 */
final class CapturedAddressTest extends AbandonedCartTestCase
{
    public function testACapturedAddressIsReminded(): void
    {
        $idCart = $this->captured(20);

        self::assertSame(1, $this->sweep());
        $this->flush();

        self::assertCount(1, $this->reminders());
        $address = $this->reminders()[0]['body']['addresses'][0];
        self::assertSame([self::ADDRESS, '', ''], [$address['email'], $address['first_name'], $address['last_name']]);
        self::assertSame(CartTracker::MAILED, $this->cartRow($idCart)['status']);
    }

    public function testAnOrderOfTheAddressWithdrawsTheCapturedCartsReminder(): void
    {
        $captured = $this->captured(40);
        self::assertSame(1, $this->sweep());
        $this->fixture->order($this->fixture->cart($this->fixture->customer(self::ADDRESS, true), 0));
        $this->flush();

        self::assertSame([], $this->reminders());
        self::assertSame([CartTracker::SKIPPED, 'ordered'], [$this->cartRow($captured)['status'], $this->cartRow($captured)['skip_reason']]);
    }

    public function testACaptureLeavesAQueuedOrSentCartAsItIs(): void
    {
        $idCart = $this->captured(20);
        $this->sweep();
        (new CartTracker())->capture($idCart, $this->idShop, 1, 'shopper2@example.test', true);

        self::assertSame([self::ADDRESS, '0'], [$this->cartRow($idCart)['email'], (string) $this->cartRow($idCart)['checkout_optin']]);
    }

    /**
     * Retention: a recorded address whose cart got no reminder is deleted once the cart did not
     * change for a day; a closed cart keeps its row but not its address once no rule reads it.
     */
    public function testARecordedAddressDoesNotOutliveItsUse(): void
    {
        $stale = $this->captured(20);
        self::db()->update('smaily_connect_cart', ['last_activity_at' => date('Y-m-d H:i:s', time() - 86401)], '`id_cart` = ' . $stale);
        $fresh = $this->fixture->cart(0, 5);
        (new CartTracker())->capture($fresh, $this->idShop, 1, 'shopper2@example.test', false);
        $sent = $this->fixture->cart($this->fixture->customer('shopper3@example.test'), 20);
        $this->sweep();
        $this->flush();
        self::db()->update('smaily_connect_cart', ['last_activity_at' => date('Y-m-d H:i:s', time() - 2 * 86400 - 1)], '`id_cart` = ' . $sent);

        $this->sweep();

        self::assertNull($this->cartRow($stale));
        self::assertSame('shopper2@example.test', $this->cartRow($fresh)['email']);
        self::assertSame([CartTracker::MAILED, null], [$this->cartRow($sent)['status'], $this->cartRow($sent)['email']]);
    }

    private function captured(int $minutesAgo): int
    {
        $idCart = $this->fixture->cart(0, $minutesAgo);
        (new CartTracker())->capture($idCart, $this->idShop, (int) \Configuration::get('PS_LANG_DEFAULT'), self::ADDRESS, false);

        return $idCart;
    }
}
