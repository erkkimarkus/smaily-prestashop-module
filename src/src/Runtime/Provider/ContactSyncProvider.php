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
use PrestaShop\Module\SmailyForPrestaShop\Contacts\SubscriberPayloadBuilder;
use PrestaShop\Module\SmailyForPrestaShop\Http\Transport;
use PrestaShop\Module\SmailyForPrestaShop\Queue\Handler\ContactSyncHandler;
use PrestaShop\Module\SmailyForPrestaShop\Runtime\Services;
use PrestaShop\Module\SmailyForPrestaShop\Scope\ScopeResolver;

/**
 * Registers the Contacts settings, the contact payload builder and the handler of `contact.sync`
 * rows.
 */
final class ContactSyncProvider implements ProviderInterface
{
    public function register(Services $services): void
    {
        $services->set(ContactsSettings::class, fn (): ContactsSettings => new ContactsSettings());
        $services->set(SubscriberPayloadBuilder::class, fn (): SubscriberPayloadBuilder => new SubscriberPayloadBuilder(
            [\ShopUrl::class, 'getMainShopDomain']
        ));
        $services->set(QueueProvider::handlerId(ContactSyncHandler::EVENT_TYPE), fn (Services $services): ContactSyncHandler => new ContactSyncHandler(
            $services->get(ScopeResolver::class),
            $services->get(ContactsSettings::class),
            $services->get(SubscriberPayloadBuilder::class),
            $services->get(Transport::class)
        ));
    }
}
