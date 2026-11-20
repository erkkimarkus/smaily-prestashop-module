<?php
declare(strict_types=1);

namespace Tests\Unit\Automation;

use PHPUnit\Framework\TestCase;
use PrestaShop\Module\SmailyForPrestaShop\Automation\AbandonedCart\CartAddress;

/**
 * Where a cart's reminder address and the store's consent come from.
 */
final class CartAddressTest extends TestCase
{
    private const CART = ['id_customer' => 7, 'date_add' => '2026-11-20 10:00:00'];

    public function testTheCustomersAddressComesFirst(): void
    {
        self::assertSame(
            ['email' => 'shopper1@example.test', 'id_customer' => 7, 'first_name' => 'Testfirst', 'last_name' => 'Testlast', 'newsletter' => false, 'box_ticked' => false],
            CartAddress::of(self::CART + self::customer('0', null) + ['tracked_email' => 'shopper2@example.test', 'checkout_optin' => '0'])
        );
    }

    public function testARecordedAddressServesACartWithoutACustomer(): void
    {
        self::assertSame(
            ['email' => 'shopper2@example.test', 'id_customer' => null, 'first_name' => '', 'last_name' => '', 'newsletter' => false, 'box_ticked' => true],
            CartAddress::of(['id_customer' => 0, 'date_add' => '2026-11-20 10:00:00', 'customer_email' => null, 'tracked_email' => ' shopper2@example.test ', 'checkout_optin' => '1'])
        );
    }

    public function testACartWithoutAnyAddressHasNone(): void
    {
        self::assertNull(CartAddress::of(['id_customer' => 0, 'date_add' => '2026-11-20 10:00:00', 'customer_email' => null, 'tracked_email' => '', 'checkout_optin' => '0']));
    }

    /**
     * @return array<string, array{string, string|null, bool}>
     */
    public function ticks(): array
    {
        return [
            'subscribed while the cart existed' => ['1', '2026-11-20 10:05:00', true],
            'subscribed when the cart was made' => ['1', '2026-11-20 10:00:00', true],
            'subscribed before the cart' => ['1', '2026-01-01 00:00:00', false],
            'not subscribed' => ['0', '2026-11-20 10:05:00', false],
            'no date' => ['1', null, false],
        ];
    }

    /**
     * @dataProvider ticks
     */
    public function testTheBoxCountsAsTickedInThisCheckoutOnlyWhenTheConsentCameWithTheCart(string $newsletter, ?string $since, bool $ticked): void
    {
        $address = CartAddress::of(self::CART + self::customer($newsletter, $since) + ['tracked_email' => null, 'checkout_optin' => '0']);

        self::assertNotNull($address);
        self::assertSame([$newsletter === '1', $ticked], [$address['newsletter'], $address['box_ticked']]);
    }

    /**
     * @return array<string, string|null>
     */
    private static function customer(string $newsletter, ?string $since): array
    {
        return ['customer_email' => 'shopper1@example.test', 'firstname' => 'Testfirst', 'lastname' => 'Testlast', 'newsletter' => $newsletter, 'newsletter_date_add' => $since];
    }
}
