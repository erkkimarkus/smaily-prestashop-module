<?php
declare(strict_types=1);

namespace Tests\Integration\Automation\AbandonedCart;

use PrestaShop\Module\SmailyForPrestaShop\Automation\AbandonedCart\CartAddress;
use PrestaShop\Module\SmailyForPrestaShop\Automation\AbandonedCart\CartPayloadBuilder;
use PrestaShop\Module\SmailyForPrestaShop\Config\AutomationSettings;
use PrestaShop\Module\SmailyForPrestaShop\Controller\AbandonedCartController;
use PrestaShop\Module\SmailyForPrestaShop\Model\AbandonedCart;
use PrestaShop\Module\SmailyForPrestaShop\Model\AbandonedCartCollection;
use PrestaShop\PrestaShop\Core\ConfigurationInterface;
use Tests\Integration\Migration\LegacyStore;

require_once __DIR__ . '/AbandonedCartTestCase.php';

/**
 * Acceptance 1: the reminder of a cart equals, field by field, the payload that 2.2.0's own code
 * (AbandonedCartController::generatePayload() over AbandonedCartCollection, still in the tree)
 * builds for the same cart, with the same field selection, in the same language and currency.
 */
final class LegacyPayloadTest extends AbandonedCartTestCase
{
    /**
     * @return array<string, array{list<string>, int}>
     */
    public function selections(): array
    {
        return [
            'every field, 11 products' => [AutomationSettings::CART_FIELD_NAMES, 11],
            'every field, 2 products' => [AutomationSettings::CART_FIELD_NAMES, 2],
            'name and price only, 3 products' => [['name', 'price'], 3],
            'nothing selected, 1 product' => [[], 1],
        ];
    }

    /**
     * @dataProvider selections
     *
     * @param list<string> $fields
     */
    public function testTheReminderEqualsThe2xPayload(array $fields, int $products): void
    {
        (new AutomationSettings())->setCartFields($fields, 0);
        LegacyStore::reloadConfiguration();
        $idCart = $this->fixture->cart($this->fixture->customer(self::ADDRESS), 20, $products);

        self::assertSame(1, $this->sweep());
        $this->flush();
        $sent = $this->reminders()[0]['body']['addresses'][0];
        self::assertArrayHasKey('abandoned_cart_automation_at', $sent);
        unset($sent['abandoned_cart_automation_at']);

        $legacy = $this->legacyPayload($idCart, $fields);
        self::assertCount(3 + 60 + 1 + count(array_intersect(['first_name', 'last_name'], $fields)), $legacy);
        self::assertSame($legacy, $sent);
        self::assertSame($products > 10, $sent['over_10_products']);
        if (in_array('price', $fields, true)) {
            self::assertMatchesRegularExpression('/\d/', $sent['product_price_1'], 'a formatted price');
            self::assertSame($products > 1 ? 1 : 0, preg_match('/\d/', $sent['product_price_2']));
        }
        if ($fields === []) {
            self::assertSame([''], array_values(array_unique(array_filter($sent, static fn (string $key): bool => strpos($key, 'product_') === 0, ARRAY_FILTER_USE_KEY))));
        }
    }

    public function testTheBuilderAloneEqualsThe2xPayload(): void
    {
        $idCart = $this->fixture->cart($this->fixture->customer(self::ADDRESS), 20, 4);
        $address = (new CartAddress())->read($idCart);
        self::assertNotNull($address);

        self::assertSame(
            $this->legacyPayload($idCart, AutomationSettings::CART_FIELD_NAMES),
            (new CartPayloadBuilder())->build(new \Cart($idCart), $address, AutomationSettings::CART_FIELD_NAMES)
        );
    }

    /**
     * The payload of 2.2.0's code path for the cart: the cart as AbandonedCartCollection reads it,
     * and generatePayload() with the 2.x field list, while the context holds the cart, its language
     * and its currency (2.x took them from the cron request).
     *
     * @param list<string> $fields
     *
     * @return array<string, mixed>
     */
    private function legacyPayload(int $idCart, array $fields): array
    {
        LegacyStore::createSentCarts(0);
        $selection = [];
        foreach (AutomationSettings::CART_FIELD_NAMES as $field) {
            $selection[$field] = in_array($field, $fields, true);
        }
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
