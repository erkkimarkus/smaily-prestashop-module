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

use PrestaShop\Module\SmailyForPrestaShop\Automation\AbandonedCart\Capture\CaptureEndpoint;
use PrestaShop\Module\SmailyForPrestaShop\Runtime\Services;
use Symfony\Component\HttpFoundation\Request;

/**
 * The checkout capture URL: the checkout's capture script posts the address a guest typed here
 * (Automation\AbandonedCart\Capture\CaptureEndpoint). It reads the request body only, and answers
 * every request with the same empty JSON object, so the answer tells nothing about the address.
 */
class SmailyforprestashopCaptureModuleFrontController extends ModuleFrontController
{
    /** No page: PrestaShop adds no header, footer or page media. */
    protected $content_only = true;

    public function initContent()
    {
        $request = Request::createFromGlobals();
        Services::instance()->get(CaptureEndpoint::class)->handle(
            $this->context,
            (string) $request->server->get('REQUEST_METHOD', ''),
            $request->request->all()
        );

        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo '{}';

        exit;
    }
}
