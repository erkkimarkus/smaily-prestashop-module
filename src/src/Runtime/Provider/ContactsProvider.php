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

namespace PrestaShop\Module\SmailyForPrestaShop\Runtime\Provider;

if (!defined('_PS_VERSION_')) {
    exit;
}

use PrestaShop\Module\SmailyForPrestaShop\Config\ContactsSettings;
use PrestaShop\Module\SmailyForPrestaShop\Contacts\Capture\ContactEvents;
use PrestaShop\Module\SmailyForPrestaShop\Contacts\Capture\CustomerCapture;
use PrestaShop\Module\SmailyForPrestaShop\Contacts\Capture\NewsletterBlockCapture;
use PrestaShop\Module\SmailyForPrestaShop\Runtime\Services;
use PrestaShop\Module\SmailyForPrestaShop\Scheduler\Lock;
use PrestaShop\Module\SmailyForPrestaShop\Scheduler\TrafficTick;

/**
 * Registers the capture of store changes as `contact.sync` rows (ARCHITECTURE 6.3).
 */
final class ContactsProvider implements ProviderInterface
{
    public function register(Services $services): void
    {
        $services->set(ContactEvents::class, fn (Services $services): ContactEvents => new ContactEvents(
            $services->get(QueueProvider::EVENT_QUEUE),
            $services->get(ContactsSettings::class),
            new Lock(),
            static fn () => $services->get(QueueProvider::FLUSHER),
            TrafficTick::finishFunction(),
            'register_shutdown_function'
        ));
        $services->set(CustomerCapture::class, fn (Services $services): CustomerCapture => new CustomerCapture(
            $services->get(ContactEvents::class)
        ));
        $services->set(NewsletterBlockCapture::class, fn (Services $services): NewsletterBlockCapture => new NewsletterBlockCapture(
            $services->get(ContactEvents::class)
        ));

        $customer = static fn () => $services->get(CustomerCapture::class);
        $services->listen('actionCustomerAccountAdd', static function (array $params) use ($customer): void {
            $customer()->accountAdded($params);
        });
        $services->listen('actionObjectCustomerUpdateBefore', static function (array $params) use ($customer): void {
            $customer()->beforeUpdate($params);
        });
        $services->listen('actionObjectCustomerUpdateAfter', static function (array $params) use ($customer): void {
            $customer()->afterUpdate($params);
        });
        $services->listen('actionCustomerAccountUpdate', static function (array $params) use ($customer): void {
            $customer()->accountUpdated($params);
        });
        $services->listen('actionValidateOrder', static function (array $params) use ($customer): void {
            $customer()->orderValidated($params);
        });

        $block = static fn () => $services->get(NewsletterBlockCapture::class);
        $services->listen('actionNewsletterRegistrationAfter', static function (array $params) use ($block): void {
            $block()->subscribed($params);
        });
        $services->listen('actionNewsletterRegistrationBefore', static function (array $params) use ($block): void {
            $block()->unsubscribing($params);
        });
    }
}
