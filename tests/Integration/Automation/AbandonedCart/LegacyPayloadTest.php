<?php
declare(strict_types=1);

namespace Tests\Integration\Automation\AbandonedCart;

use PrestaShop\Module\SmailyForPrestaShop\Automation\AbandonedCart\CartAddress;
use PrestaShop\Module\SmailyForPrestaShop\Automation\AbandonedCart\CartPayloadBuilder;
use PrestaShop\Module\SmailyForPrestaShop\Controller\AbandonedCartController;
use PrestaShop\Module\SmailyForPrestaShop\Model\AbandonedCart;
use PrestaShop\Module\SmailyForPrestaShop\Model\AbandonedCartCollection;
use PrestaShop\PrestaShop\Core\ConfigurationInterface;
use Tests\Integration\Migration\LegacyStore;

require_once __DIR__ . '/AbandonedCartTestCase.php';

/**
 * Acceptance 1: the reminder of a cart equals, field by field, the payload that 2.2.0's own code
 * (AbandonedCartController::generatePayload() over AbandonedCartCollection, still in the tree)
 * builds for the same cart with every box of its field picker ticked, in the same language and
 * currency. The store holds no field selection: every field is always sent.
 */
final class LegacyPayloadTest extends AbandonedCartTestCase
{
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
        $idCart = $this->fixture->cart($this->fixture->customer(self::ADDRESS), 20, $products);

        self::assertSame(1, $this->sweep());
        $this->flush();
        $sent = $this->reminders()[0]['body']['addresses'][0];
        self::assertArrayHasKey('abandoned_cart_automation_at', $sent);
        unset($sent['abandoned_cart_automation_at']);

        $legacy = $this->legacyPayload($idCart);
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

        self::assertSame($this->legacyPayload($idCart), (new CartPayloadBuilder())->build(new \Cart($idCart), $address));
    }

    /**
     * The payload of 2.2.0's code path for the cart: the cart as AbandonedCartCollection reads it,
     * and generatePayload() with every field of the 2.x list ticked, while the context holds the
     * cart, its language and its currency (2.x took them from the cron request).
     *
     * @return array<string, mixed>
     */
    private function legacyPayload(int $idCart): array
    {
        LegacyStore::createSentCarts(0);
        $selection = array_fill_keys(['first_name', 'last_name', 'name', 'description', 'sku', 'price', 'base_price', 'quantity'], true);
        $configuration = new class(['SMAILY_CART_SYNCRONIZE_ADDITIONAL' => json_encode($selection)]) implements ConfigurationInterface {
            /** @var array<string, string> */
            private array $values;

            /**
             * @param array<string, string> $values
             */
            public function __construct(array $values)
            {
                $this->values = $values;
            }

            public function get($key)
            {
                return $this->values[$key] ?? null;
            }

            public function set($key, $value)
            {
                $this->values[$key] = $value;

                return $this;
            }
        };

        $cart = new \Cart($idCart);
        $context = \Context::getContext();
        $saved = [$context->cart, $context->language, $context->currency, $context->currentLocale];
        try {
            $context->cart = $cart;
            $context->language = new \Language((int) $cart->id_lang);
            $context->currency = new \Currency((int) $cart->id_currency);
            $context->currentLocale = null;
            $context->currentLocale = \Tools::getContextLocale($context);
            $legacyCarts = array_values(array_filter(
                (new AbandonedCartCollection())->carts,
                static fn (AbandonedCart $legacy): bool => $legacy->cartID === $idCart
            ));
            self::assertCount(1, $legacyCarts, '2.x finds the cart');
            $generate = new \ReflectionMethod(AbandonedCartController::class, 'generatePayload');
            $generate->setAccessible(true);

            return $generate->invoke(new AbandonedCartController($configuration), $legacyCarts[0]);
        } finally {
            [$context->cart, $context->language, $context->currency, $context->currentLocale] = $saved;
        }
    }
}
