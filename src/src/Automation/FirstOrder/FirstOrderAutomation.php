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

namespace PrestaShop\Module\SmailyForPrestaShop\Automation\FirstOrder;

if (!defined('_PS_VERSION_')) {
    exit;
}

use PrestaShop\Module\SmailyForPrestaShop\Automation\AbandonedCart\CartAddress;
use PrestaShop\Module\SmailyForPrestaShop\Automation\MarketingConsent;
use PrestaShop\Module\SmailyForPrestaShop\Automation\Router;
use PrestaShop\Module\SmailyForPrestaShop\Automation\Trigger;
use PrestaShop\Module\SmailyForPrestaShop\Automation\TriggerSender;
use PrestaShop\Module\SmailyForPrestaShop\Config\AutomationSettings;
use PrestaShop\Module\SmailyForPrestaShop\Config\ContactsSettings;
use PrestaShop\Module\SmailyForPrestaShop\Contacts\SubscriberPayloadBuilder;
use PrestaShop\Module\SmailyForPrestaShop\Http\Transport;
use PrestaShop\Module\SmailyForPrestaShop\Queue\Handler\AutomationHandler;
use PrestaShop\Module\SmailyForPrestaShop\Runtime\Provider\ProviderInterface;
use PrestaShop\Module\SmailyForPrestaShop\Runtime\Provider\QueueProvider;
use PrestaShop\Module\SmailyForPrestaShop\Runtime\Services;
use PrestaShop\Module\SmailyForPrestaShop\Scope\ScopeResolver;

/**
 * Registers the services, the queue handler and the hook listeners of the first-order automation.
 * Runtime\Provider\AutomationProvider calls it.
 */
final class FirstOrderAutomation implements ProviderInterface
{
    public function register(Services $services): void
    {
        $services->set(FirstOrderTrigger::class, fn (Services $services): FirstOrderTrigger => new FirstOrderTrigger(
            $services->get(QueueProvider::EVENT_QUEUE),
            $services->get(AutomationSettings::class),
            $services->get(ContactsSettings::class),
            $services->get(SubscriberPayloadBuilder::class),
            $services->get(ScopeResolver::class)
        ));
        $services->set(QueueProvider::handlerId(FirstOrderTrigger::EVENT_TYPE), fn (Services $services): FirstOrderHandler => new FirstOrderHandler(
            new AutomationHandler(Trigger::FIRST_ORDER, $services->get(TriggerSender::class)),
            $services->get(Router::class),
            new CartAddress(),
            new MarketingConsent($services->get(ContactsSettings::class), $services->get(Transport::class))
        ));
        $services->listen('actionValidateOrder', static function (array $params) use ($services): void {
            $services->get(FirstOrderTrigger::class)->orderValidated($params);
        });
    }
}
