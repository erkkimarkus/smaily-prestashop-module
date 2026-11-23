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

namespace PrestaShop\Module\SmailyForPrestaShop\Storefront;

if (!defined('_PS_VERSION_')) {
    exit;
}

use PrestaShop\Module\SmailyForPrestaShop\Engine\Capture\VisitorToken as VisitorTokenSource;

/**
 * The visitor token of a shopper whom the store has just come to know: at a login, at a
 * registration and at the customer step of a guest checkout (contract §5 "Store-created visitor
 * token", §7 "Store-created token at login"). The identity merge and the order carry it to the
 * engine, which binds it to the customer, so the storefront can ask for that shopper's
 * recommendations on a later visit without a login (§15). It is the storefront's answer to the
 * identity merge's seam (Engine\Capture\VisitorToken).
 *
 * - A token that the browser already holds is kept and returned, never replaced: an engine `vt_`
 *   token as it is, a store-created `vs_` token only with marketing consent.
 * - A shopper with marketing consent and no token gets a new one: `vs_` + 22 characters of
 *   `[A-Za-z0-9]`, each from PHP's cryptographically secure random_int() (about 131 random bits),
 *   set in the visitor-token cookie by the server.
 * - Marketing consent is the merchant's confirmation that the store's cookie banner covers Smaily
 *   tracking (Attribution::marketingConsent()). A signed-in customer needs nothing more (owner,
 *   2026-11-23). The caller's answer can only narrow it: a guest-checkout customer who left the
 *   newsletter box empty gets none (Attribution::guestConsents()).
 * - Without consent nothing is created and no store-created token is returned.
 * - Outside a storefront request (the back office, a command, a cron URL) there is no shopper's
 *   browser, and the answer is null.
 *
 * The answer is the same through one request: the cookie that this request sets is not among the
 * cookies that it received.
 */
final class VisitorToken implements VisitorTokenSource
{
    public const PREFIX = 'vs_';

    public const LENGTH = 22;

    private const ALPHABET = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz';

    private ActiveConnection $connections;

    private Attribution $attribution;

    /** @var callable(): ?Cookies */
    private $cookies;

    /** @var array<string, string|null> */
    private array $answered = [];

    /**
     * @param callable(): ?Cookies $cookies the cookies of the storefront request, or null outside one
     */
    public function __construct(ActiveConnection $connections, Attribution $attribution, callable $cookies)
    {
        $this->connections = $connections;
        $this->attribution = $attribution;
        $this->cookies = $cookies;
    }

    /**
     * A new `vs_` token.
     */
    public static function generate(): string
    {
        $last = strlen(self::ALPHABET) - 1;
        $token = self::PREFIX;
        for ($i = 0; $i < self::LENGTH; ++$i) {
            $token .= self::ALPHABET[random_int(0, $last)];
        }

        return $token;
    }

    /**
     * The visitor token to send for the shopper of this request, or null for none.
     *
     * @param int $idShop the shop of the request
     * @param bool $marketingConsent the caller's answer for the shopper; false creates no token and
     *                               returns no store-created one, true leaves it to the merchant's
     *                               confirmation
     */
    public function token(int $idShop, bool $marketingConsent): ?string
    {
        $key = $idShop . ':' . (int) $marketingConsent;
        if (!array_key_exists($key, $this->answered)) {
            $this->answered[$key] = $this->find($idShop, $marketingConsent);
        }

        return $this->answered[$key];
    }

    private function find(int $idShop, bool $marketingConsent): ?string
    {
        $connection = $this->connections->sending($idShop);
        $cookies = $connection === null ? null : ($this->cookies)();
        if ($connection === null || $cookies === null) {
            return null;
        }
        $names = new ClientConfig($connection);
        $held = (string) $cookies->get($names->cookieName(ClientConfig::VISITOR));
        $consents = $marketingConsent && $this->attribution->marketingConsent($connection, null);
        if (Shape::isEngineVisitorToken($held) || (Shape::isStoreVisitorToken($held) && $consents)) {
            return $held;
        }
        if (!$consents) {
            return null;
        }
        $token = self::generate();

        // A token that the browser does not hold is not sent.
        return $cookies->set($names->cookieName(ClientConfig::VISITOR), $token, $names->days(ClientConfig::VISITOR)) ? $token : null;
    }
}
