<?php
declare(strict_types=1);

namespace Tests\Integration\Automation\AbandonedCart\Capture;

use PrestaShop\Module\SmailyForPrestaShop\Automation\AbandonedCart\Capture\CaptureEndpoint;
use PrestaShop\Module\SmailyForPrestaShop\Automation\AbandonedCart\CartTracker;
use PrestaShop\Module\SmailyForPrestaShop\Automation\Trigger;
use PrestaShop\Module\SmailyForPrestaShop\Config\AutomationSettings;
use PrestaShop\Module\SmailyForPrestaShop\Log\FailureMessage;
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
