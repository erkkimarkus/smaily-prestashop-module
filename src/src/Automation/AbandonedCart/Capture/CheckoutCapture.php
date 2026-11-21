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

use PrestaShop\Module\SmailyForPrestaShop\Automation\AbandonedCart\CartTracker;
use PrestaShop\Module\SmailyForPrestaShop\Config\AutomationSettings;
use PrestaShop\Module\SmailyForPrestaShop\Install\Schema;
use PrestaShop\Module\SmailyForPrestaShop\Runtime\Provider\ProviderInterface;
use PrestaShop\Module\SmailyForPrestaShop\Runtime\Provider\QueueProvider;
use PrestaShop\Module\SmailyForPrestaShop\Runtime\Services;

/**
 * Registers the checkout address capture of the abandoned-cart automation: the script on the
 * checkout page (CheckoutScript), the endpoint that records the typed address (CaptureEndpoint, the
 * front controller `capture`) and the read of the recorded address (CapturedAddress).
 * AbandonedCartAutomation calls it.
 *
 * The recorded address serves the cart's reminder only, under the reminder's consent rule; it is
 * not sent to Smaily as a contact and not written to a customer.
 */
final class CheckoutCapture implements ProviderInterface
{
    public function register(Services $services): void
    {
        $services->set(CaptureEndpoint::class, fn (Services $services): CaptureEndpoint => new CaptureEndpoint(
            $services->get(AutomationSettings::class),
            $services->get(CartTracker::class),
            $services->get(QueueProvider::EVENT_QUEUE),
            [Schema::class, 'isCurrent'],
            'time'
        ));
        $services->set(CheckoutScript::class, fn (Services $services): CheckoutScript => new CheckoutScript($services->get(AutomationSettings::class)));
        $services->set(CapturedAddress::class, fn (Services $services): CapturedAddress => new CapturedAddress($services->get(CartTracker::class)));

        $services->listen('actionFrontControllerSetMedia', static function () use ($services): void {
            $services->get(CheckoutScript::class)->add(\Context::getContext());
        });
    }
}
