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

use PrestaShop\Module\SmailyForPrestaShop\Engine\Connection;

/**
 * Reads the attribution cookies of a request: the recommendation id and its context, the visitor
 * token and the anonymous session id. Each value is shape-checked on its own (Shape); an off-shape
 * cookie reads as absent and the others stay.
 *
 * Every path that sends the visitor token reads it here: the browse relay, a guest's
 * recommendations, the cart's and the order's attribution row and the identity merge.
 *
 * - An engine `vt_` token came with an email link. It is attribution and is read as it is.
 * - A store-created `vs_` token names a shopper, so it reads as absent without marketing consent
 *   (contract §5 and §7: "create and send a `vs_` token only for a shopper who gave marketing
 *   consent"). marketingConsent() is that rule.
 */
final class Attribution
{
    /** @var callable(int): bool */
    private $consentConfirmed;

    /**
     * @param callable(int): bool $consentConfirmed whether the merchant confirmed the cookie banner
     *                                              for a connection's id_shop
     *                                              (Config\EngineSettings::consentConfirmed())
     */
    public function __construct(callable $consentConfirmed)
    {
        $this->consentConfirmed = $consentConfirmed;
    }

    /**
     * @param \Customer|null $customer the shopper, where the request knows one
     *
     * @return array{rec_id: ?string, context: ?string, visitor_token: ?string, session_id: ?string}
     */
    public function read(Cookies $cookies, Connection $connection, ?\Customer $customer = null): array
    {
        $names = new ClientConfig($connection);
        $recId = (string) $cookies->get($names->cookieName(ClientConfig::REC_ID));
        $context = (string) $cookies->get($names->cookieName(ClientConfig::CONTEXT));
        $token = (string) $cookies->get($names->cookieName(ClientConfig::VISITOR));
        $session = (string) $cookies->get($names->cookieName(ClientConfig::SESSION));

        return [
            'rec_id' => Shape::isRecId($recId) ? $recId : null,
            'context' => Shape::isContext($context) ? $context : null,
            'visitor_token' => $this->visitorToken($token, $connection, $customer),
            'session_id' => Shape::isSessionId($session) ? $session : null,
        ];
    }

    /**
     * A visitor token as it may be sent for the shopper, or null: an engine token as it is, a
     * store-created one only with marketing consent, any other value never. The rule of a token
     * from a cookie, and of one that was kept with the cart (CartAttribution).
     */
    public function visitorToken(string $token, Connection $connection, ?\Customer $customer): ?string
    {
        if (!Shape::isVisitorToken($token) || (Shape::isStoreVisitorToken($token) && !$this->marketingConsent($connection, $customer))) {
            return null;
        }

        return $token;
    }

    /**
     * Whether a store-created visitor token may be created for, or sent about, the shopper of this
     * request: the merchant confirmed that the store's cookie banner covers Smaily tracking
     * (Config\EngineSettings::consentConfirmed()). That is what the server can see of the storefront
     * scripts' consent rule, as the accepted cookie notice is for the Magento connector.
     *
     * The confirmation decides alone for a visitor who is not logged in and for a signed-in
     * customer, whatever that customer's `newsletter` (owner, 2026-11-23). A guest-checkout
     * customer also needs the guest's own answer (guestConsents()).
     */
    public function marketingConsent(Connection $connection, ?\Customer $customer): bool
    {
        return ($this->consentConfirmed)($connection->idShop()) && self::guestConsents($customer);
    }

    /**
     * The shopper's own part of the rule, which only a guest-checkout customer has: the newsletter
     * box of the checkout's customer step, `customer.newsletter`. Every other shopper passes.
     */
    public static function guestConsents(?\Customer $customer): bool
    {
        return $customer === null || (int) $customer->id <= 0 || (int) $customer->is_guest !== 1 || (int) $customer->newsletter === 1;
    }
}
