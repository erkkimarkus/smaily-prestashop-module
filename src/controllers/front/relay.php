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

if (!defined('_PS_VERSION_')) {
    exit;
}

use PrestaShop\Module\SmailyForPrestaShop\Runtime\Services;
use PrestaShop\Module\SmailyForPrestaShop\Storefront\Cookies;
use PrestaShop\Module\SmailyForPrestaShop\Storefront\EndpointController;
use PrestaShop\Module\SmailyForPrestaShop\Storefront\Relay;
use Symfony\Component\HttpFoundation\Request;

/**
 * The browse relay URL: the storefront's tracker posts its browse events here, and the store
 * forwards them to Campaign Intelligence with the connection's key (Storefront\Relay).
 */
class SmailyforprestashopRelayModuleFrontController extends EndpointController
{
    protected function answer(Services $services, Request $request): array
    {
        $customer = $this->context->customer;
        [$status, $answer] = $services->get(Relay::class)->handle(
            (int) $this->context->shop->id,
            (string) $request->server->get('REQUEST_METHOD', ''),
            (string) $request->getContent(),
            Cookies::ofRequest($this->context),
            $customer instanceof Customer && $customer->isLogged() ? $customer : null
        );

        return [$status, 'application/json; charset=utf-8', (string) json_encode($answer)];
    }
}
