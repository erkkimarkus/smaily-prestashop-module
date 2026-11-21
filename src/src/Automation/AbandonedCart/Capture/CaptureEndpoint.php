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

use PrestaShop\Module\SmailyForPrestaShop\Automation\AbandonedCart\AbandonedCartHandler;
use PrestaShop\Module\SmailyForPrestaShop\Automation\AbandonedCart\CartTracker;
use PrestaShop\Module\SmailyForPrestaShop\Automation\Trigger;
use PrestaShop\Module\SmailyForPrestaShop\Config\AutomationSettings;
use PrestaShop\Module\SmailyForPrestaShop\Database\Sql;
use PrestaShop\Module\SmailyForPrestaShop\Queue\EventQueue;
use PrestaShop\Module\SmailyForPrestaShop\Runtime\Log;
use PrestaShop\Module\SmailyForPrestaShop\Scheduler\Lock;

/**
 * The checkout capture endpoint (front controller `capture`): records the address a guest typed in
 * the checkout's customer form, and whether its newsletter box is ticked, on the tracker row of the
 * visitor's own cart (CartTracker::capture()), for the abandoned-cart reminder.
 *
 * The cart is the one of the visitor's PrestaShop cookie; a cart, shop or customer named in the
 * request is never read. A request is refused, and nothing is written, unless it is a POST with
 * the capture token of that cart (token()), within the visitor's limit of LIMIT captures per
 * minute, while the schema is current; and unless the cart has products, no customer with an
 * address (that address serves the reminder) and the visitor is no customer, the address is valid
 * and fits the tracker column, and the shop's abandoned-cart automation is on. The front
 * controller answers every request the same way.
 *
 * The token is the cart's own: only the checkout page of that cart prints it (CheckoutScript), so
 * a request must have loaded the page of the cart it writes to, and a token read once opens no
 * other cart. PrestaShop's static front token would not do: it is one value for every visitor
 * without an account.
 *
 * The limit is counted in the visitor's PrestaShop cookie (COOKIE_KEY, `<minute>:<count>`), the
 * only server-side state of a visitor: it slows a browser down, and does not hold a caller that
 * drops its cookie. What bounds such a caller is the reminder's own rule, which lives in the
 * tracker rows: one address gets at most one reminder per 24 hours, whatever number of carts
 * carry it (CartTracker::blocking()). Each cart has one tracker row, so captures never add rows
 * beyond one per cart.
 *
 * What bounds the number of addresses such a caller names is the installation's limit, the
 * Magento connector's: HOURLY_LIMIT addresses in a rolling hour, every shop together, counted
 * from the tracker and the queue (CartTracker::recorded()); no visitor's network address is
 * stored. At the limit a capture is refused as any other, and the Log gets one row an hour, a
 * skipped cart reminder with the reason SKIPPED_CAPTURE_LIMIT, in the shop of the refused cart.
 */
final class CaptureEndpoint
{
    /** Captures per visitor per minute. */
    public const LIMIT = 6;

    /** The width of `smaily_connect_cart.email`. */
    public const MAX_EMAIL_LENGTH = 255;

    public const COOKIE_KEY = 'smailyCapture';

    /** Addresses per rolling hour for the installation, every shop together. */
    public const HOURLY_LIMIT = 2000;

    public const SKIPPED_CAPTURE_LIMIT = 'capture_limit';

    private const HOUR_SECONDS = 3600;

    private AutomationSettings $settings;

    private CartTracker $tracker;

    private EventQueue $queue;

    /** @var callable(): bool */
    private $schemaIsCurrent;

    /** @var callable(): int */
    private $clock;

    private int $hourlyLimit;

    /**
     * @param callable(): bool $schemaIsCurrent
     * @param callable(): int $clock the current Unix time
     */
    public function __construct(AutomationSettings $settings, CartTracker $tracker, EventQueue $queue, callable $schemaIsCurrent, callable $clock, int $hourlyLimit = self::HOURLY_LIMIT)
    {
        $this->settings = $settings;
        $this->tracker = $tracker;
        $this->queue = $queue;
        $this->schemaIsCurrent = $schemaIsCurrent;
        $this->clock = $clock;
        $this->hourlyLimit = $hourlyLimit;
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
     * The capture token of a cart: an HMAC of the cart's id under the shop's cookie key
     * (`_COOKIE_KEY_`, the secret of PrestaShop's own front tokens), 64 hexadecimal characters.
     */
    public static function token(int $idCart): string
    {
        return hash_hmac('sha256', 'smaily-connect-capture:' . $idCart, _COOKIE_KEY_);
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
        $cart = $context->cart;
        $token = $post['token'] ?? null;
        if ($method !== 'POST' || !is_string($token) || !$cart instanceof \Cart || (int) $cart->id <= 0
            || !hash_equals(self::token((int) $cart->id), $token)) {
            return false;
        }
        $count = self::count((string) $context->cookie->{self::COOKIE_KEY}, (int) ($this->clock)());
        if ($count === null) {
            return false;
        }
        $context->cookie->{self::COOKIE_KEY} = $count;
        $context->cookie->write();

        if (!($this->schemaIsCurrent)() || (int) $context->customer->id > 0) {
            return false;
        }
        $email = trim(is_string($post['email'] ?? null) ? $post['email'] : '');
        if ($email === '' || strlen($email) > self::MAX_EMAIL_LENGTH || !\Validate::isEmail($email)) {
            return false;
        }
        if (!$this->settings->enabled(Trigger::ABANDONED_CART, (int) $cart->id_shop) || !$this->open((int) $cart->id)) {
            return false;
        }
        if ($this->tracker->recorded(self::HOUR_SECONDS, $this->hourlyLimit) >= $this->hourlyLimit) {
            $this->noteLimit((int) $cart->id_shop);

            return false;
        }
        $this->tracker->capture((int) $cart->id, (int) $cart->id_shop, (int) $cart->id_lang, $email, ($post['newsletter'] ?? null) === '1');

        return true;
    }

    /**
     * Tells the Log that the installation's limit refused a capture: one row an hour. The lock
     * lets one request at a time look for that row and add it.
     */
    private function noteLimit(int $idShop): void
    {
        $lock = new Lock('capture_limit');
        if (!$lock->acquire()) {
            return;
        }
        try {
            $this->queue->note(AbandonedCartHandler::EVENT_TYPE, $idShop, self::SKIPPED_CAPTURE_LIMIT, self::HOUR_SECONDS);
        } finally {
            $lock->release();
        }
    }

    /**
     * Whether the cart has products and no customer with an address.
     */
    private function open(int $idCart): bool
    {
        $row = Sql::row(
            'SELECT EXISTS(SELECT 1 FROM `' . _DB_PREFIX_ . 'cart_product` cp WHERE cp.`id_cart` = c.`id_cart`) AS `has_products`,'
            . ' cu.`email` FROM `' . _DB_PREFIX_ . 'cart` c'
            . ' LEFT JOIN `' . _DB_PREFIX_ . 'customer` cu ON cu.`id_customer` = c.`id_customer` AND cu.`deleted` = 0'
            . ' WHERE c.`id_cart` = ' . $idCart
        );

        return $row !== null && (int) $row['has_products'] === 1 && trim((string) $row['email']) === '';
    }
}
