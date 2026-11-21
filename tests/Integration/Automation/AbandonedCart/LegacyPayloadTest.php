<?php
declare(strict_types=1);

namespace Tests\Integration\Automation\AbandonedCart;

use PrestaShop\Module\SmailyForPrestaShop\Automation\AbandonedCart\CartAddress;
use PrestaShop\Module\SmailyForPrestaShop\Automation\AbandonedCart\CartPayloadBuilder;

require_once __DIR__ . '/AbandonedCartTestCase.php';

/**
 * Acceptance 1: the reminder of a cart equals, field by field, the payload that 2.2.0's own code
 * (AbandonedCartController::generatePayload() over AbandonedCartCollection) builds for the same
 * cart with every box of its field picker ticked, in the same language and currency. The store
 * holds no field selection: every field is always sent.
 *
 * 3.0.0 does not ship that code. Its payloads for the carts of this test were recorded once from
 * the 2.2.0 tag, in tests/fixtures/legacy/payloads/2.2.0-all-ticked.json; the README of
 * tests/fixtures/legacy/ says how to record them again.
 */
final class LegacyPayloadTest extends AbandonedCartTestCase
{
    private const RECORDED = __DIR__ . '/../../../fixtures/legacy/payloads/2.2.0-all-ticked.json';

    /**
     * @return array<string, array{int}>
     */
    public function cartSizes(): array
    {
        return ['11 products' => [11], '10 products' => [10], '2 products' => [2], '1 product' => [1]];
    }

    /**
     * @dataProvider cartSizes
     */
    public function testTheReminderEqualsThe2xPayloadWithEveryBoxTicked(int $products): void
    {
        $this->fixture->cart($this->fixture->customer(self::ADDRESS), 20, $products);

        self::assertSame(1, $this->sweep());
        $this->flush();
        $sent = $this->reminders()[0]['body']['addresses'][0];
        self::assertArrayHasKey('abandoned_cart_automation_at', $sent);
        unset($sent['abandoned_cart_automation_at']);

        $legacy = self::legacyPayload($products);
        self::assertCount(3 + 2 + 60 + 1, $legacy);
        self::assertSame($legacy, $sent);
        self::assertSame(['Testfirst', 'Testlast'], [$sent['first_name'], $sent['last_name']]);
        self::assertSame($products > 10, $sent['over_10_products']);
        foreach (CartPayloadBuilder::PRODUCT_FIELDS as $field) {
            for ($slot = 1; $slot <= CartPayloadBuilder::SLOTS; ++$slot) {
                self::assertSame($slot <= $products, $sent['product_' . $field . '_' . $slot] !== '', $field . ' of slot ' . $slot);
            }
        }
        self::assertMatchesRegularExpression('/\d/', $sent['product_price_1'], 'a formatted price');
    }

    public function testTheBuilderAloneEqualsThe2xPayload(): void
    {
        $idCart = $this->fixture->cart($this->fixture->customer(self::ADDRESS), 20, 4);
        $address = (new CartAddress())->read($idCart);
        self::assertNotNull($address);

        self::assertSame(self::legacyPayload(4), (new CartPayloadBuilder())->build(new \Cart($idCart), $address));
    }

    /**
     * The payload that 2.2.0 built, with every field of its list ticked, for the fixture's cart of
     * $products demo products of the customer self::ADDRESS, in the shop's default language and
     * currency.
     *
     * @return array<string, mixed>
     */
    private static function legacyPayload(int $products): array
    {
        $recorded = json_decode((string) file_get_contents(self::RECORDED), true, 512, JSON_THROW_ON_ERROR);
        self::assertArrayHasKey((string) $products, $recorded, 'a recorded payload for a cart of this size');

        return $recorded[(string) $products];
    }
}
