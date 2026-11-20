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
use PrestaShop\Module\SmailyForPrestaShop\Scheduler\Door;

/**
 * The abandoned-cart cron URL of 2.x, kept for merchants' crontabs: it runs the tick
 * (Scheduler\Door) when its token is the cart cron token.
 */
class SmailyforprestashopSmailyCartCronModuleFrontController extends ModuleFrontController
{
    public function initContent()
    {
        $token = Tools::getValue('token');
        [$status, $answer] = Services::instance()->get(Door::class)->open(
            Door::CART_CRON,
            is_string($token) ? $token : '',
            (int) $this->context->shop->id,
            (int) ini_get('max_execution_time')
        );

        http_response_code($status);
        header('Content-Type: text/plain; charset=utf-8');
        echo $answer . "\n";

        exit;
    }
}
