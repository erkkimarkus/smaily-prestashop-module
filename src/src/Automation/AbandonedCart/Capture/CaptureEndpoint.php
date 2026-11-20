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

namespace PrestaShop\Module\SmailyForPrestaShop\Automation\AbandonedCart\Capture;

if (!defined('_PS_VERSION_')) {
    exit;
}

use PrestaShop\Module\SmailyForPrestaShop\Automation\AbandonedCart\CartTracker;
use PrestaShop\Module\SmailyForPrestaShop\Automation\Trigger;
use PrestaShop\Module\SmailyForPrestaShop\Config\AutomationSettings;
use PrestaShop\Module\SmailyForPrestaShop\Runtime\Log;

/**
 * The checkout capture endpoint (front controller `capture`): records the address a guest typed in
 * the checkout's customer form, and whether its newsletter box is ticked, on the tracker row of the
 * visitor's own cart (CartTracker::capture()), for the abandoned-cart reminder.
 *
 * The cart is the one of the visitor's PrestaShop cookie; a cart, shop or customer named in the
 * request is never read. A request is refused, and nothing is written, unless it is a POST with
 * PrestaShop's static front token (Tools::getToken(false), `prestashop.static_token`), within the
 * visitor's limit of LIMIT captures per minute, while the schema is current; and unless the cart
 * has products, no customer with an address (that address serves the reminder) and the visitor is
 * no customer, the address is valid and fits the tracker column, and the shop's abandoned-cart
 * automation is on. The front controller answers every request the same way.
 *
 * The limit is counted in the visitor's PrestaShop cookie (COOKIE_KEY, `<minute>:<count>`), the
 * only server-side state of a visitor. Each cart has one tracker row, so captures never add rows
 * beyond one per cart.
 */
final class CaptureEndpoint
{
    /** Captures per visitor per minute. */
    public const LIMIT = 6;

    /** The width of `smaily_connect_cart.email`. */
    public const MAX_EMAIL_LENGTH = 255;

    public const COOKIE_KEY = 'smailyCapture';

    private AutomationSettings $settings;

    private CartTracker $tracker;

    /** @var callable(): bool */
    private $schemaIsCurrent;

    /** @var callable(): int */
    private $clock;

    /**
     * @param callable(): bool $schemaIsCurrent
     * @param callable(): int $clock the current Unix time
     */
    public function __construct(AutomationSettings $settings, CartTracker $tracker, callable $schemaIsCurrent, callable $clock)
    {
        $this->settings = $settings;
        $this->tracker = $tracker;
        $this->schemaIsCurrent = $schemaIsCurrent;
        $this->clock = $clock;
    }

    /**
     * Records the posted address on the visitor's cart when the request may; a failure is logged
     * by its error class only.
     *
     * @param array<string, mixed> $post the request body (`token`, `email`, `newsletter` `1`)
     *
     * @return bool whether the address was recorded
     */
    public function handle(\Context $context, string $method, array $post): bool
    {
        try {
            return $this->record($context, $method, $post);
        } catch (\Throwable $error) {
            Log::failure('the checkout address capture', $error);

            return false;
        }
    }

    /**
     * The visitor's capture count after one more capture now, from the stored `<minute>:<count>`;
     * null when the visitor has had LIMIT captures in this minute.
     */
    public static function count(string $stored, int $now): ?string
    {
        $minute = intdiv($now, 60);
        $parts = explode(':', $stored);
        $count = count($parts) === 2 && $parts[0] === (string) $minute ? (int) $parts[1] : 0;

        return $count < self::LIMIT ? $minute . ':' . ($count + 1) : null;
    }

    /**
     * @param array<string, mixed> $post
     */
    private function record(\Context $context, string $method, array $post): bool
    {
        $token = $post['token'] ?? null;
        if ($method !== 'POST' || !is_string($token) || !hash_equals(\Tools::getToken(false, $context), $token)) {
            return false;
        }
        $count = self::count((string) $context->cookie->{self::COOKIE_KEY}, (int) ($this->clock)());
        if ($count === null) {
            return false;
        }
        $context->cookie->{self::COOKIE_KEY} = $count;
        $context->cookie->write();

        $cart = $context->cart;
        if (!($this->schemaIsCurrent)() || !$cart instanceof \Cart || (int) $cart->id <= 0 || (int) $context->customer->id > 0) {
            return false;
        }
        $email = trim(is_string($post['email'] ?? null) ? $post['email'] : '');
        if ($email === '' || strlen($email) > self::MAX_EMAIL_LENGTH || !\Validate::isEmail($email)) {
            return false;
        }
        if (!$this->settings->enabled(Trigger::ABANDONED_CART, (int) $cart->id_shop) || !$this->open((int) $cart->id)) {
            return false;
        }
        $this->tracker->capture((int) $cart->id, (int) $cart->id_shop, (int) $cart->id_lang, $email, ($post['newsletter'] ?? null) === '1');

        return true;
    }

    /**
     * Whether the cart has products and no customer with an address.
     */
    private function open(int $idCart): bool
    {
        $row = \Db::getInstance()->getRow(
            'SELECT EXISTS(SELECT 1 FROM `' . _DB_PREFIX_ . 'cart_product` cp WHERE cp.`id_cart` = c.`id_cart`) AS `has_products`,'
            . ' cu.`email` FROM `' . _DB_PREFIX_ . 'cart` c'
            . ' LEFT JOIN `' . _DB_PREFIX_ . 'customer` cu ON cu.`id_customer` = c.`id_customer` AND cu.`deleted` = 0'
            . ' WHERE c.`id_cart` = ' . $idCart,
            false
        );

        return is_array($row) && (int) $row['has_products'] === 1 && trim((string) $row['email']) === '';
    }
}
