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

use PrestaShop\Module\SmailyForPrestaShop\Automation\AbandonedCart\CartTracker;
use PrestaShop\Module\SmailyForPrestaShop\Config\GdprPaceSettings;
use PrestaShop\Module\SmailyForPrestaShop\Contacts\Capture\ContactEvents;
use PrestaShop\Module\SmailyForPrestaShop\Discount\IssuedCodes;
use PrestaShop\Module\SmailyForPrestaShop\Engine\Client;
use PrestaShop\Module\SmailyForPrestaShop\Engine\Connections;
use PrestaShop\Module\SmailyForPrestaShop\Engine\Ingest\IngestQueue;
use PrestaShop\Module\SmailyForPrestaShop\Privacy\AccountLink;
use PrestaShop\Module\SmailyForPrestaShop\Privacy\CustomerDeleteHandler;
use PrestaShop\Module\SmailyForPrestaShop\Privacy\GdprHooks;
use PrestaShop\Module\SmailyForPrestaShop\Privacy\GdprPace;
use PrestaShop\Module\SmailyForPrestaShop\Privacy\LocalData;
use PrestaShop\Module\SmailyForPrestaShop\Privacy\PrivacyRequest;
use PrestaShop\Module\SmailyForPrestaShop\Privacy\ProfilingConsent;
use PrestaShop\Module\SmailyForPrestaShop\Privacy\ProfilingConsentHandler;
use PrestaShop\Module\SmailyForPrestaShop\Privacy\ProfilingRecord;
use PrestaShop\Module\SmailyForPrestaShop\Runtime\Services;
use PrestaShop\Module\SmailyForPrestaShop\Smaily\Crypto;

/**
 * Registers the services and hook listeners of privacy export, erasure and the profiling opt-out.
 *
 * - `actionExportGDPRData` and `actionDeleteGDPRCustomer`: the export and the erasure for
 *   PrestaShop's GDPR module (Privacy\GdprHooks). `registerGDPRConsent` has no listener: the hook
 *   only lists the module on that module's consent screen, and the module has no form of its own
 *   that needs a consent box.
 * - `displayCustomerAccount`: the link to the personalization page (Privacy\AccountLink).
 * - The contact events of the capture: leaving marketing stops profiling, and joining it again
 *   lifts that (Privacy\ProfilingConsent). The capture's hooks that can record a consent change
 *   attach the listener to the request's ContactEvents, once.
 * - The handlers of the two queue rows that go to Campaign Intelligence: a shopper's choice
 *   (`engine.profiling_consent`) and the deletion of a shopper (`engine.customer_delete`), paced
 *   with the export to the engine's limit of privacy requests (Privacy\GdprPace).
 */
final class PrivacyProvider implements ProviderInterface
{
    /** The domain of the words of an export: the back office's, which holds their translations. */
    private const DOMAIN = 'Modules.Smailyforprestashop.Admin';

    /** The capture hooks whose events can lose or gain the newsletter consent. */
    private const CONSENT_HOOKS = [
        'actionCustomerAccountAdd',
        'actionObjectCustomerUpdateAfter',
        'actionNewsletterRegistrationAfter',
        'actionNewsletterRegistrationBefore',
    ];

    public function register(Services $services): void
    {
        $services->set(ProfilingRecord::class, fn (): ProfilingRecord => new ProfilingRecord());
        $services->set(GdprPace::class, fn (): GdprPace => new GdprPace(new GdprPaceSettings()));
        $services->set(ProfilingConsent::class, fn (Services $services): ProfilingConsent => new ProfilingConsent(
            $services->get(ProfilingRecord::class),
            $services->get(Connections::class),
            $services->get(QueueProvider::EVENT_QUEUE)
        ));
        $services->set(LocalData::class, fn (Services $services): LocalData => new LocalData(
            $services->get(QueueProvider::EVENT_QUEUE),
            $services->get(CartTracker::class),
            $services->get(IssuedCodes::class),
            $services->get(ProfilingRecord::class),
            $services->get(IngestQueue::class)
        ));
        $services->set(PrivacyRequest::class, fn (Services $services): PrivacyRequest => new PrivacyRequest(
            $services->get(LocalData::class),
            $services->get(Connections::class),
            $services->get(Client::class),
            $services->get(QueueProvider::EVENT_QUEUE),
            $services->get(Crypto::class),
            $services->get(GdprPace::class)
        ));
        $services->set(GdprHooks::class, fn (Services $services): GdprHooks => new GdprHooks(
            $services->get(PrivacyRequest::class),
            static fn (string $id): string => \Context::getContext()->getTranslator()->trans($id, [], self::DOMAIN)
        ));
        $services->set(AccountLink::class, fn (Services $services): AccountLink => new AccountLink(
            $services->get(Connections::class)
        ));
        $services->set(QueueProvider::handlerId(ProfilingConsent::EVENT_TYPE), fn (Services $services): ProfilingConsentHandler => new ProfilingConsentHandler(
            $services->get(Client::class),
            $services->get(Connections::class),
            $services->get(ProfilingRecord::class),
            $services->get(GdprPace::class)
        ));
        $services->set(QueueProvider::handlerId(CustomerDeleteHandler::EVENT_TYPE), fn (Services $services): CustomerDeleteHandler => new CustomerDeleteHandler(
            $services->get(Client::class),
            $services->get(Connections::class),
            $services->get(Crypto::class),
            $services->get(QueueProvider::EVENT_QUEUE),
            $services->get(GdprPace::class)
        ));

        $services->listen('actionExportGDPRData', static fn (array $params): string => $services->get(GdprHooks::class)->export($params));
        $services->listen('actionDeleteGDPRCustomer', static fn (array $params): string => $services->get(GdprHooks::class)->erase(
            $params,
            (int) \Context::getContext()->shop->id
        ));
        $services->listen('displayCustomerAccount', static fn (): ?string => $services->get(AccountLink::class)->render(\Context::getContext()));

        $attached = false;
        $attach = static function () use ($services, &$attached): void {
            if (!$attached) {
                $attached = true;
                $services->get(ContactEvents::class)->listen([$services->get(ProfilingConsent::class), 'onContactEvent']);
            }
        };
        foreach (self::CONSENT_HOOKS as $hook) {
            $services->listen($hook, $attach);
        }
    }
}
