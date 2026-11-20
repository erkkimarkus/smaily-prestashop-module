<?php
/**
 * 2024 Smaily
 *
 * NOTICE OF LICENSE
 *
 * Smaily for PrestaShop is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * any later version.
 *
 * Smaily for PrestaShop is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with Smaily for PrestaShop. If not, see <http://www.gnu.org/licenses/>.
 *
 * @author    Smaily <info@smaily.com>
 * @copyright 2024 Smaily
 * @license   GPL3
 */
declare(strict_types=1);

namespace PrestaShop\Module\SmailyForPrestaShop\Automation\AbandonedCart;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * The address fields of a cart's reminder, field by field the payload that 2.x sent
 * (AbandonedCartController::generatePayload() of 2.2.0), in its order and value types:
 *   `email`; `is_abandoned_cart` 'true'; `force_opt_in` false; `first_name` and `last_name` when
 *   selected; `product_<field>_<n>` for the fields base_price, description, name, price, quantity
 *   and sku and n = 1..10, '' where the slot holds no product or the field is not selected (an
 *   empty value clears the previous cart's value in Smaily); `over_10_products` (bool).
 * A product's name and description are its short texts in the cart's language, `sku` its
 * reference, `quantity` its quantity in the cart, `price` and `base_price` its unit price with and
 * without reductions, formatted in the cart's currency with the number format of the cart's
 * language.
 *
 * 2.x read the language and the currency from the cron request; here they are the cart's, so the
 * reminder is written in the language of the shopper's last change of the cart. PrestaShop computes
 * the prices for the context's cart and currency, so the build puts the cart, its language and its
 * currency into the context and takes them back afterwards.
 */
class CartPayloadBuilder
{
    /** The product fields of 2.x, in the order 2.x wrote their slots. */
    public const PRODUCT_FIELDS = ['base_price', 'description', 'name', 'price', 'quantity', 'sku'];

    public const SLOTS = 10;

    /**
     * @param array{email: string, first_name: string, last_name: string} $address
     * @param list<string> $fields the selected fields (AutomationSettings::cartFields())
     *
     * @return array<string, mixed>
     */
    public function build(\Cart $cart, array $address, array $fields): array
    {
        $payload = [
            'email' => $address['email'],
            'is_abandoned_cart' => 'true',
            'force_opt_in' => false,
        ];
        if (in_array('first_name', $fields, true)) {
            $payload['first_name'] = $address['first_name'];
        }
        if (in_array('last_name', $fields, true)) {
            $payload['last_name'] = $address['last_name'];
        }
        foreach (self::PRODUCT_FIELDS as $field) {
            for ($slot = 1; $slot <= self::SLOTS; ++$slot) {
                $payload['product_' . $field . '_' . $slot] = '';
            }
        }
        $selected = array_values(array_intersect(self::PRODUCT_FIELDS, $fields));

        $context = \Context::getContext();
        $saved = [$context->cart, $context->language, $context->currency, $context->currentLocale];
        try {
            $context->cart = $cart;
            $context->language = new \Language((int) $cart->id_lang);
            $context->currency = new \Currency((int) $cart->id_currency);
            $context->currentLocale = null;
            $locale = \Tools::getContextLocale($context);
            $currency = $context->currency->iso_code;
            $products = $cart->getProducts(true);
        } finally {
            [$context->cart, $context->language, $context->currency, $context->currentLocale] = $saved;
        }

        $payload['over_10_products'] = count($products) > self::SLOTS;
        foreach (array_slice(array_values($products), 0, self::SLOTS) as $i => $product) {
            $slot = $i + 1;
            foreach ($selected as $field) {
                switch ($field) {
                    case 'base_price':
                        $value = $locale->formatPrice($product['price_without_reduction'], $currency);
                        break;
                    case 'description':
                        $value = $product['description_short'];
                        break;
                    case 'name':
                        $value = $product['name'];
                        break;
                    case 'price':
                        $value = $locale->formatPrice($product['price_with_reduction'], $currency);
                        break;
                    case 'quantity':
                        $value = $product['quantity'];
                        break;
                    default:
                        $value = $product['reference'];
                }
                $payload['product_' . $field . '_' . $slot] = $value;
            }
        }

        return $payload;
    }
}
