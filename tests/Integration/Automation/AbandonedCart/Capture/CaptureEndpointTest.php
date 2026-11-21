<?php
declare(strict_types=1);

namespace Tests\Integration\Automation\AbandonedCart\Capture;

use PrestaShop\Module\SmailyForPrestaShop\Automation\AbandonedCart\Capture\CaptureEndpoint;
use PrestaShop\Module\SmailyForPrestaShop\Automation\AbandonedCart\CartTracker;
use PrestaShop\Module\SmailyForPrestaShop\Automation\Trigger;
use PrestaShop\Module\SmailyForPrestaShop\Config\AutomationSettings;
use PrestaShop\Module\SmailyForPrestaShop\Log\FailureMessage;
use PrestaShop\Module\SmailyForPrestaShop\Log\Resend;
use PrestaShop\Module\SmailyForPrestaShop\Queue\EventQueue;
use Tests\Integration\Automation\AbandonedCart\AbandonedCartTestCase;

require_once __DIR__ . '/../AbandonedCartTestCase.php';

/**
 * The checkout capture endpoint records the address a guest types on the visitor's own cart, and
 * only there; every other request writes nothing and gets the same answer.
 */
final class CaptureEndpointTest extends AbandonedCartTestCase
{
    private const TYPED = 'capture1@example.test';

    private const OTHER = 'capture2@example.test';

    public function testAGuestsTypedAddressAndBoxAreRecordedOnTheirOwnCart(): void
    {
        $idCart = $this->fixture->cart(0, 0);
        $context = $this->context($idCart);

        self::assertTrue($this->endpoint()->handle($context, 'POST', $this->post($context, self::TYPED, '0')));
        $row = $this->cartRow($idCart);
        self::assertSame(
            [(string) $this->idShop, self::TYPED, (string) \Configuration::get('PS_LANG_DEFAULT'), CartTracker::OPEN, '0', null, null],
            [(string) $row['id_shop'], $row['email'], (string) $row['id_lang'], $row['status'], (string) $row['checkout_optin'], $row['event_id'], $row['id_customer']]
        );

        self::assertTrue($this->endpoint()->handle($context, 'POST', $this->post($context, self::TYPED, '1')));
        self::assertSame('1', (string) $this->cartRow($idCart)['checkout_optin']);
    }

    public function testACartShopOrCustomerNamedInTheRequestIsIgnored(): void
    {
        $own = $this->fixture->cart(0, 0);
        $other = $this->fixture->cart(0, 0);
        $context = $this->context($own);

        $this->endpoint()->handle($context, 'POST', $this->post($context, self::TYPED, '0') + ['id_cart' => (string) $other, 'id_shop' => '99', 'id_customer' => '1']);

        self::assertSame([self::TYPED, (string) $this->idShop, null], [$this->cartRow($own)['email'], (string) $this->cartRow($own)['id_shop'], $this->cartRow($own)['id_customer']]);
        self::assertNull($this->cartRow($other));
    }

    /**
     * @return array<string, array{string}>
     */
    public function refusals(): array
    {
        $cases = ['wrong token', 'the token every guest shares', 'the token of another cart', 'no token', 'GET', 'invalid address', 'no address', 'address too long', 'no cart', 'empty cart', 'automation off', 'schema not current', 'customer with an address', 'signed-in customer'];

        return array_combine($cases, array_map(static fn (string $case): array => [$case], $cases));
    }

    /**
     * @dataProvider refusals
     */
    public function testARefusedRequestWritesNothing(string $case): void
    {
        $idCart = $case === 'empty cart' ? $this->emptyCart() : $this->fixture->cart(0, 0);
        $context = $this->context($case === 'no cart' ? 0 : $idCart);
        $post = $this->post($context, self::TYPED, '1');
        $method = 'POST';
        $endpoint = $this->endpoint();
        switch ($case) {
            case 'wrong token':
                $post['token'] = md5('not-the-token');
                break;
            case 'the token every guest shares':
                $post['token'] = \Tools::getToken(false, $context);
                break;
            case 'the token of another cart':
                $post['token'] = CaptureEndpoint::token($this->fixture->cart(0, 0));
                break;
            case 'no token':
                unset($post['token']);
                break;
            case 'GET':
                $method = 'GET';
                break;
            case 'invalid address':
                $post['email'] = 'capture1@';
                break;
            case 'no address':
                unset($post['email']);
                break;
            case 'address too long':
                $post['email'] = str_repeat('a', 250) . '@example.test';
                break;
            case 'automation off':
                (new AutomationSettings())->setEnabled(Trigger::ABANDONED_CART, false, 0);
                break;
            case 'schema not current':
                $endpoint = $this->endpoint(static fn (): bool => false);
                break;
            case 'customer with an address':
                self::db()->update('cart', ['id_customer' => $this->fixture->customer(self::OTHER, true)], '`id_cart` = ' . $idCart);
                $context->cart = new \Cart($idCart);
                break;
            case 'signed-in customer':
                $context->customer = new \Customer($this->fixture->customer(self::OTHER));
                break;
        }

        self::assertFalse($endpoint->handle($context, $method, $post));
        self::assertNull($this->cartRow($idCart));
    }

    public function testAVisitorGetsAtMostTheLimitOfCapturesInAMinute(): void
    {
        $now = 1795000000 - 1795000000 % 60;
        $endpoint = $this->endpoint(null, static function () use (&$now): int {
            return $now;
        });
        $idCart = $this->fixture->cart(0, 0);
        $context = $this->context($idCart);
        for ($i = 1; $i <= CaptureEndpoint::LIMIT; ++$i) {
            self::assertTrue($endpoint->handle($context, 'POST', $this->post($context, self::TYPED, (string) ($i % 2))));
        }

        self::assertFalse($endpoint->handle($context, 'POST', $this->post($context, self::OTHER, '0')));
        self::assertFalse($endpoint->handle($context, 'POST', ['token' => 'wrong'] + $this->post($context, self::OTHER, '0')));
        self::assertSame(self::TYPED, $this->cartRow($idCart)['email']);

        $now += 60;
        self::assertTrue($endpoint->handle($context, 'POST', $this->post($context, self::OTHER, '0')));
        self::assertSame(self::OTHER, $this->cartRow($idCart)['email']);
    }

    /**
     * The installation takes the hourly limit of addresses and no more, whatever cart or visitor
     * they come from; the Log says so once an hour; the next hour starts a fresh count.
     */
    public function testTheInstallationRecordsAtMostTheHourlyLimitOfAddresses(): void
    {
        $now = time();
        $clock = static function () use (&$now): int {
            return $now;
        };
        $endpoint = new CaptureEndpoint(new AutomationSettings(), new CartTracker($clock), new EventQueue($clock), static fn (): bool => true, $clock, 3);
        $capture = function (string $address, ?\Context $context = null) use ($endpoint): array {
            $context ??= $this->context($this->fixture->cart(0, 0));

            return [$endpoint->handle($context, 'POST', $this->post($context, $address, '1')), $context];
        };
        $notes = fn (): array => array_values(array_filter($this->events(), static fn (array $event): bool => $event['last_error'] === CaptureEndpoint::SKIPPED_CAPTURE_LIMIT));

        [$recorded, $first] = $capture(self::TYPED);
        self::assertTrue($recorded);
        self::assertTrue($capture('capture3@example.test')[0]);
        self::assertTrue($capture('capture4@example.test')[0]);
        self::assertSame([], $notes());

        [$recorded, $refused] = $capture('capture5@example.test');
        self::assertFalse($recorded);
        self::assertNull($this->cartRow((int) $refused->cart->id));
        self::assertFalse($capture(self::OTHER, $first)[0], 'a cart that has an address takes no other one at the limit');
        self::assertSame(self::TYPED, $this->cartRow((int) $first->cart->id)['email']);
        self::assertSame(
            [[(string) $this->idShop, 'skipped', null, '[]', '0']],
            array_map(static fn (array $note): array => [(string) $note['id_shop'], $note['status'], $note['entity_id'], $note['payload'], (string) $note['attempts']], $notes()),
            'one Log row for the refused captures of the hour, without a contact'
        );
        self::assertStringContainsString((string) CaptureEndpoint::HOURLY_LIMIT, FailureMessage::forDisplay($notes()[0]['last_error'], static fn (string $sentence): string => $sentence));

        $now += 3601;
        self::assertTrue($capture('capture5@example.test', $refused)[0]);
        self::assertTrue($capture('capture6@example.test')[0]);
        self::assertTrue($capture('capture7@example.test')[0]);
        self::assertFalse($capture('capture8@example.test')[0]);
        self::assertFalse($capture('capture9@example.test')[0]);
        self::assertCount(2, $notes(), 'the next hour has a Log row of its own');
        self::assertSame(0, $this->flush(), 'a Log row of the limit is no event to send');
    }

    /**
     * The sweep puts the cart's last change in place of the time of the capture: an address that
     * it took still counts, by its queued reminder, also when the cart is older than the hour.
     */
    public function testAnAddressThatTheSweepTookStillCountsInItsHour(): void
    {
        $endpoint = new CaptureEndpoint(new AutomationSettings(), new CartTracker(), new EventQueue(), static fn (): bool => true, 'time', 2);
        $capture = function (string $address) use ($endpoint): int {
            $context = $this->context($this->fixture->cart(0, 0));

            return $endpoint->handle($context, 'POST', $this->post($context, $address, '1')) ? (int) $context->cart->id : 0;
        };

        $old = $capture(self::TYPED);
        $this->fixture->age($old, 120);
        self::assertSame(1, $this->sweep());
        self::assertLessThan(date('Y-m-d H:i:s', time() - 3600), $this->cartRow($old)['last_activity_at']);

        self::assertGreaterThan(0, $capture(self::OTHER));
        self::assertSame(0, $capture('capture3@example.test'));
    }

    /**
     * The count is of recorded addresses. A busy store's queue of the last hour (the reminders
     * of customers' carts, the rows of their skips, a "Send again", the limit's own Log row) does
     * not use it up.
     */
    public function testTheRemindersOfCustomersCartsAndTheLogsOwnRowsDoNotCount(): void
    {
        $endpoint = new CaptureEndpoint(new AutomationSettings(), new CartTracker(), new EventQueue(), static fn (): bool => true, 'time', 3);
        $capture = function (string $address) use ($endpoint): int {
            $context = $this->context($this->fixture->cart(0, 20));

            return $endpoint->handle($context, 'POST', $this->post($context, $address, '1')) ? (int) $context->cart->id : 0;
        };
        // Customers' carts: two reminders, a second cart of one customer (`recently_reminded`), a
        // cart whose customer is deleted before the send (`customer_deleted`), a failed reminder
        // and its "Send again", and Log rows that name no cart.
        $first = $this->fixture->customer('shopper1@example.test');
        $failed = $this->fixture->cart($first, 20);
        $this->fixture->cart($first, 21);
        $this->fixture->cart($this->fixture->customer('shopper2@example.test'), 20);
        $gone = $this->fixture->customer('shopper3@example.test');
        $this->fixture->cart($gone, 20);
        self::assertSame(4, $this->sweep());
        self::db()->delete('customer', '`id_customer` = ' . $gone);
        $this->mock->failAutomationPosts(true);
        $this->flush();
        $this->mock->failAutomationPosts(false);
        $row = (int) $this->cartRow($failed)['event_id'];
        self::db()->update(EventQueue::TABLE, ['status' => 'failed', 'attempts' => 5, 'next_retry_at' => null], '`id` = ' . $row, 0, true);
        (new Resend(new EventQueue(), new CartTracker()))->resend(self::db()->getRow('SELECT * FROM `' . _DB_PREFIX_ . EventQueue::TABLE . '` WHERE `id` = ' . $row, false), 'Testfirst Testlast');
        (new EventQueue())->note('automation.abandoned_cart', $this->idShop, CaptureEndpoint::SKIPPED_CAPTURE_LIMIT, 3600);
        (new EventQueue())->note('automation.abandoned_cart', $this->idShop, 'another_note', 3600);
        self::assertCount(7, $this->events(), 'seven cart rows of the last hour, none of a recorded address');
        self::assertSame(0, (new CartTracker())->recorded(3600, 3));

        // The recorded addresses still have the whole limit: one waits, one was taken and sent,
        // one was taken and closed as a second cart of its address.
        $sent = $capture(self::TYPED);
        self::assertSame(1, $this->sweep());
        $this->flush();
        self::assertSame(CartTracker::MAILED, $this->cartRow($sent)['status']);
        $again = $capture(self::TYPED);
        self::assertSame(1, $this->sweep());
        self::assertSame([CartTracker::SKIPPED, 'recently_reminded'], [$this->cartRow($again)['status'], $this->cartRow($again)['skip_reason']]);
        self::assertGreaterThan(0, $capture(self::OTHER));
        self::assertSame(3, (new CartTracker())->recorded(3600, 3));

        self::assertSame(0, $capture('capture3@example.test'), 'the fourth address of the hour is refused, as before');
    }

    /**
     * The limit of the release, with as many recorded addresses as it takes: half of them wait for
     * the sweep, half were taken by it.
     */
    public function testTwoThousandRecordedAddressesAreTheLimit(): void
    {
        $queue = new EventQueue();
        $first = $queue->enqueue('automation.abandoned_cart', $this->idShop, ['id_cart' => 9000001]);
        for ($number = 2; $number <= 999; ++$number) {
            $queue->enqueue('automation.abandoned_cart', $this->idShop, ['id_cart' => 9000000 + $number]);
        }
        $rows = [];
        for ($number = 1; $number <= 1999; ++$number) {
            $rows[] = sprintf(
                '(%d, %d, \'capture%d@example.test\', 1, \'%s\', \'%s\', %s)',
                9000000 + $number,
                $this->idShop,
                $number,
                $number <= 999 && $number % 2 === 0 ? CartTracker::MAILED : CartTracker::OPEN,
                // A taken address carries its cart's last change, which can be a day old.
                date('Y-m-d H:i:s', time() - ($number <= 999 ? 86000 : 60)),
                $number <= 999 ? (string) ($first + $number - 1) : 'NULL'
            );
        }
        self::db()->execute('INSERT INTO `' . _DB_PREFIX_ . CartTracker::TABLE . '` (`id_cart`, `id_shop`, `email`, `id_lang`, `status`, `last_activity_at`, `event_id`) VALUES ' . implode(', ', $rows));
        $context = $this->context($this->fixture->cart(0, 0));
        $next = $this->context($this->fixture->cart(0, 0));

        self::assertSame(1999, (new CartTracker())->recorded(3600, CaptureEndpoint::HOURLY_LIMIT));
        self::assertTrue($this->endpoint()->handle($context, 'POST', $this->post($context, self::TYPED, '1')), 'the 2000th address of the hour is recorded');
        self::assertFalse($this->endpoint()->handle($next, 'POST', $this->post($next, self::OTHER, '1')), 'the next one is refused');
        self::assertNull($this->cartRow((int) $next->cart->id));
    }

    public function testAFailedWriteIsLoggedWithoutTheAddress(): void
    {
        $logs = (int) self::db()->getValue('SELECT MAX(`id_log`) FROM `' . _DB_PREFIX_ . 'log`', false);
        $idCart = $this->fixture->cart(0, 0);
        $context = $this->context($idCart);
        $endpoint = new CaptureEndpoint(new AutomationSettings(), new class() extends CartTracker {
            public function capture(int $idCart, int $idShop, int $idLang, string $email, bool $newsletterTicked): void
            {
                throw new \RuntimeException('Could not record ' . $email);
            }
        }, new EventQueue(), static fn (): bool => true, 'time');

        self::assertFalse($endpoint->handle($context, 'POST', $this->post($context, self::TYPED, '0')));

        $messages = array_column(self::db()->executeS('SELECT `message` FROM `' . _DB_PREFIX_ . 'log` WHERE `id_log` > ' . $logs) ?: [], 'message');
        self::assertCount(1, $messages);
        self::assertMatchesRegularExpression('/^Smaily Connect: the checkout address capture failed with RuntimeException at CaptureEndpointTest\.php:\d+$/', $messages[0]);
    }

    private function endpoint(?callable $schemaIsCurrent = null, ?callable $clock = null): CaptureEndpoint
    {
        return new CaptureEndpoint(new AutomationSettings(), new CartTracker(), new EventQueue(), $schemaIsCurrent ?? static fn (): bool => true, $clock ?? 'time');
    }

    /**
     * The visitor's own context: a guest with $idCart in the cookie (no cart for 0).
     */
    private function context(int $idCart): \Context
    {
        $context = clone \Context::getContext();
        $context->cart = $idCart > 0 ? new \Cart($idCart) : new \Cart();
        $context->customer = new \Customer();
        $context->cookie = new \Cookie('smaily-capture-test');
        $context->cookie->disallowWriting();

        return $context;
    }

    /**
     * @return array<string, string>
     */
    private function post(\Context $context, string $email, string $newsletter): array
    {
        return ['token' => CaptureEndpoint::token((int) $context->cart->id), 'email' => $email, 'newsletter' => $newsletter];
    }

    private function emptyCart(): int
    {
        $idCart = $this->fixture->cart(0, 0, 1);
        self::db()->delete('cart_product', '`id_cart` = ' . $idCart);

        return $idCart;
    }
}
