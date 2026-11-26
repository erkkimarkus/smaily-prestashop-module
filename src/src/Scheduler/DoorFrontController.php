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

namespace PrestaShop\Module\SmailyForPrestaShop\Scheduler;

if (!defined('_PS_VERSION_')) {
    exit;
}

use PrestaShop\Module\SmailyForPrestaShop\Runtime\Services;

/**
 * The front controller of a URL that runs the tick through one door (Scheduler\Door): it answers
 * with the door's HTTP status and plain-text answer, and ends the request.
 */
abstract class DoorFrontController extends \ModuleFrontController
{
    /**
     * The door this URL opens, one of the constants of Door.
     */
    abstract protected function door(): string;

    public function initContent()
    {
        $token = \Tools::getValue('token');
        Services::instance()->get(Door::class)->open(
            $this->door(),
            is_string($token) ? $token : '',
            (int) $this->context->shop->id,
            (int) ini_get('max_execution_time'),
            static function (int $status, string $answer): void {
                http_response_code($status);
                header('Content-Type: text/plain; charset=utf-8');
                echo $answer . "\n";
            }
        );

        exit;
    }
}
