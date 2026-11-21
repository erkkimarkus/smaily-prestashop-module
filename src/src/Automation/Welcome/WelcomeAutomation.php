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

namespace PrestaShop\Module\SmailyForPrestaShop\Automation\Welcome;

if (!defined('_PS_VERSION_')) {
    exit;
}

use PrestaShop\Module\SmailyForPrestaShop\Automation\Trigger;
use PrestaShop\Module\SmailyForPrestaShop\Automation\TriggerSender;
use PrestaShop\Module\SmailyForPrestaShop\Config\AutomationSettings;
use PrestaShop\Module\SmailyForPrestaShop\Config\ContactsSettings;
use PrestaShop\Module\SmailyForPrestaShop\Contacts\Capture\ContactEvents;
use PrestaShop\Module\SmailyForPrestaShop\Contacts\SubscriberPayloadBuilder;
use PrestaShop\Module\SmailyForPrestaShop\Discount\AutomationDiscount;
use PrestaShop\Module\SmailyForPrestaShop\Queue\Handler\AutomationHandler;
use PrestaShop\Module\SmailyForPrestaShop\Runtime\Provider\ProviderInterface;
use PrestaShop\Module\SmailyForPrestaShop\Runtime\Provider\QueueProvider;
use PrestaShop\Module\SmailyForPrestaShop\Runtime\Services;

/**
 * Registers the services, the queue handler and the hook listeners of the welcome automation.
 * Runtime\Provider\AutomationProvider calls it.
 *
 * The welcome listens to the contact events of the capture (WelcomeTrigger). The capture's hooks
 * that can record a consent given in the storefront attach it to the request's ContactEvents, once.
 */
final class WelcomeAutomation implements ProviderInterface
{
    /** The capture hooks whose events can gain the newsletter consent. */
    private const CONSENT_HOOKS = ['actionCustomerAccountAdd', 'actionObjectCustomerUpdateAfter', 'actionNewsletterRegistrationAfter'];

    public function register(Services $services): void
    {
        $services->set(WelcomeTrigger::class, fn (Services $services): WelcomeTrigger => new WelcomeTrigger(
            $services->get(QueueProvider::EVENT_QUEUE),
            $services->get(AutomationSettings::class),
            $services->get(ContactsSettings::class),
            $services->get(SubscriberPayloadBuilder::class),
            static fn (): bool => \Context::getContext()->controller instanceof \FrontController
        ));
        $services->set(QueueProvider::handlerId(WelcomeTrigger::EVENT_TYPE), fn (Services $services): AutomationHandler => new AutomationHandler(
            Trigger::WELCOME,
            $services->get(TriggerSender::class),
            $services->get(AutomationDiscount::class)
        ));

        $attached = false;
        $attach = static function () use ($services, &$attached): void {
            if (!$attached) {
                $attached = true;
                $services->get(ContactEvents::class)->listen([$services->get(WelcomeTrigger::class), 'onContactEvent']);
            }
        };
        foreach (self::CONSENT_HOOKS as $hook) {
            $services->listen($hook, $attach);
        }
    }
}
