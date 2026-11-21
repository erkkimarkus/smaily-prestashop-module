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

use PrestaShop\Module\SmailyForPrestaShop\Automation\Trigger;
use PrestaShop\Module\SmailyForPrestaShop\Config\AutomationSettings;

/**
 * Puts the checkout capture script (`views/js/front/checkout-capture.js`) on the checkout page
 * (front controller `order`) of a visitor who is no customer yet and has a cart, while the shop's
 * abandoned-cart automation is on.
 *
 * The script is a static file. The endpoint URL and the capture token of the visitor's cart
 * (CaptureEndpoint::token()) reach it as the JavaScript definition JS_DEF (Media::addJsDef()). The
 * token differs from cart to cart; the checkout page shows the visitor's own cart, so no cache
 * serves it to another visitor.
 */
final class CheckoutScript
{
    public const SCRIPT_ID = 'modules-smailyforprestashop-checkout-capture';

    public const JS_DEF = 'smailyConnectCapture';

    private AutomationSettings $settings;

    public function __construct(AutomationSettings $settings)
    {
        $this->settings = $settings;
    }

    /**
     * actionFrontControllerSetMedia: adds the script and its definition, or nothing.
     */
    public function add(\Context $context): void
    {
        $controller = $context->controller;
        if (!$controller instanceof \FrontController || $controller->php_self !== 'order' || (int) $context->customer->id > 0
            || !$context->cart instanceof \Cart || (int) $context->cart->id <= 0
            || !$this->settings->enabled(Trigger::ABANDONED_CART, (int) $context->shop->id)) {
            return;
        }

        \Media::addJsDef([self::JS_DEF => [
            'url' => $context->link->getModuleLink('smailyforprestashop', 'capture'),
            'token' => CaptureEndpoint::token((int) $context->cart->id),
        ]]);
        $controller->registerJavascript(self::SCRIPT_ID, 'modules/smailyforprestashop/views/js/front/checkout-capture.js', ['position' => 'bottom', 'priority' => 200]);
    }
}
