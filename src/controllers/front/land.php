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
use PrestaShop\Module\SmailyForPrestaShop\Storefront\Landing;
use Symfony\Component\HttpFoundation\Request;

/**
 * The landing URL: the landing script posts the parameters of a Smaily link here, and the store's
 * server sets the attribution cookies (Storefront\Landing). The answer has no body.
 */
class SmailyforprestashopLandModuleFrontController extends EndpointController
{
    protected function answer(Services $services, Request $request): array
    {
        $status = $services->get(Landing::class)->handle(
            (int) $this->context->shop->id,
            (string) $request->server->get('REQUEST_METHOD', ''),
            $request->request->all(),
            Cookies::ofRequest($this->context)
        );

        return [$status, 'text/plain; charset=utf-8', ''];
    }
}
