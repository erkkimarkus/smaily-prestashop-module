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

use PrestaShop\Module\SmailyForPrestaShop\Install\Schema;
use PrestaShop\Module\SmailyForPrestaShop\Runtime\Log;
use PrestaShop\Module\SmailyForPrestaShop\Runtime\Services;
use Symfony\Component\HttpFoundation\Request;

/**
 * The front controller of a storefront endpoint that the storefront scripts call (`relay`, `land`,
 * `recommendations`): it prints the endpoint's status and body and ends the request.
 *
 * - Every answer carries `Cache-Control: no-store, private`: it belongs to one visitor.
 * - While the stored schema version differs from the code's (an upgrade that has not finished),
 *   every endpoint answers 404, as a shop without a connection does.
 * - An endpoint that throws answers 500 with an empty body; the log names the error's class only.
 */
abstract class EndpointController extends \ModuleFrontController
{
    /** No page: PrestaShop adds no header, footer or page media. */
    protected $content_only = true;

    /**
     * @return array{int, string, string} the HTTP status, the content type and the body
     */
    abstract protected function answer(Services $services, Request $request): array;

    public function initContent()
    {
        [$status, $type, $body] = [404, 'text/plain; charset=utf-8', ''];
        if (Schema::isCurrent()) {
            try {
                [$status, $type, $body] = $this->answer(Services::instance(), Request::createFromGlobals());
            } catch (\Throwable $error) {
                Log::failure('the storefront endpoint ' . static::class, $error);
                $status = 500;
            }
        }

        http_response_code($status);
        header('Content-Type: ' . $type);
        header('Cache-Control: no-store, private');
        echo $body;

        exit;
    }
}
