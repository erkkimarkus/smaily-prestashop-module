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

namespace PrestaShop\Module\SmailyForPrestaShop\Engine\Capture;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * The one seam between the identity merge (contract §7, IdentityCapture) and the storefront's
 * visitor token, which the storefront Story owns with its cookie (ARCHITECTURE 8.5).
 *
 * The identity capture asks it once when a shopper signs in, registers or checks out as a guest,
 * in that shopper's own storefront request. The module's default answers nothing
 * (NoVisitorToken), so no merge is sent. The storefront registers its own under this interface's
 * name in the services (`$services->set(VisitorToken::class, …)`).
 */
interface VisitorToken
{
    /**
     * The visitor token of the shopper of this request, or null when there is none: an engine
     * token (`vt_…`) that the visitor's browser holds, or a token of the store (`vs_…`).
     *
     * The contract lets the store create a `vs_` token only for a shopper who has given marketing
     * consent (§7 "Store-created token at login"). $marketingConsent is the identity capture's
     * answer for this shopper (the merchant's confirmation of the cookie banner,
     * CustomerScope::consents()); an implementation may ask for more before it creates one, never
     * for less.
     *
     * @param int $idShop the shop the shopper is on
     */
    public function token(int $idShop, bool $marketingConsent): ?string;
}
