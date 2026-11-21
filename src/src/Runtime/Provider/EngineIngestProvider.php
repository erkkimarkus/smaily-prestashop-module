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
use PrestaShop\Module\SmailyForPrestaShop\Engine\Capture\CatalogCapture;
use PrestaShop\Module\SmailyForPrestaShop\Engine\Capture\CustomerCapture;
use PrestaShop\Module\SmailyForPrestaShop\Engine\Capture\IdentityCapture;
use PrestaShop\Module\SmailyForPrestaShop\Engine\Capture\IngestMarkers;
use PrestaShop\Module\SmailyForPrestaShop\Engine\Capture\NoVisitorToken;
use PrestaShop\Module\SmailyForPrestaShop\Engine\Capture\OrderCapture;
use PrestaShop\Module\SmailyForPrestaShop\Engine\Capture\VisitorToken;
use PrestaShop\Module\SmailyForPrestaShop\Engine\Client;
use PrestaShop\Module\SmailyForPrestaShop\Engine\Connections;
use PrestaShop\Module\SmailyForPrestaShop\Engine\HealthCheck;
use PrestaShop\Module\SmailyForPrestaShop\Engine\Ingest\CatalogManifest;
use PrestaShop\Module\SmailyForPrestaShop\Engine\Ingest\CustomerScope;
use PrestaShop\Module\SmailyForPrestaShop\Engine\Ingest\IdentityMergeHandler;
use PrestaShop\Module\SmailyForPrestaShop\Engine\Ingest\IngestFlusher;
use PrestaShop\Module\SmailyForPrestaShop\Engine\Ingest\IngestQueue;
use PrestaShop\Module\SmailyForPrestaShop\Engine\Payload\CatalogPayloadBuilder;
use PrestaShop\Module\SmailyForPrestaShop\Engine\Payload\CustomerPayloadBuilder;
use PrestaShop\Module\SmailyForPrestaShop\Engine\Payload\OrderPayloadBuilder;
use PrestaShop\Module\SmailyForPrestaShop\Runtime\Services;
use PrestaShop\Module\SmailyForPrestaShop\Scheduler\Deadline;
use PrestaShop\Module\SmailyForPrestaShop\Scheduler\Job;

/**
 * Registers what sends the catalog, the customers and the orders to Campaign Intelligence
 * (ARCHITECTURE section 8): the ingest queue and its flush, the listeners that note what changed,
 * the identity merge and the nightly product list.
 *
 * Nothing is queued and nothing is sent for a shop without a stored connection, and no setting
 * switches the ingest on or off: a connection exists only after the merchant pasted a setup link
 * (Engine\Setup). Every request goes through Engine\Client, which calls the engine's own host
 * only.
 *
 * The storefront supplies the visitor token of the identity merge by registering its own
 * Engine\Capture\VisitorToken after this provider; until then there is none.
 */
final class EngineIngestProvider implements ProviderInterface
{
    public const FLUSH_JOB = 'ingest_flush';

    public const MANIFEST_JOB = 'catalog_manifest';

    public function register(Services $services): void
    {
        $services->set(IngestQueue::class, fn (): IngestQueue => new IngestQueue());
        $services->set(CatalogPayloadBuilder::class, fn (): CatalogPayloadBuilder => new CatalogPayloadBuilder());
        $services->set(CustomerPayloadBuilder::class, fn (): CustomerPayloadBuilder => new CustomerPayloadBuilder());
        $services->set(OrderPayloadBuilder::class, fn (): OrderPayloadBuilder => new OrderPayloadBuilder());
        $services->set(CustomerScope::class, fn (Services $services): CustomerScope => new CustomerScope(
            $services->get(ContactsSettings::class),
            'time'
        ));
        $services->set(IngestFlusher::class, fn (Services $services): IngestFlusher => new IngestFlusher(
            $services->get(IngestQueue::class),
            $services->get(Connections::class),
            $services->get(Client::class),
            $services->get(HealthCheck::class),
            $services->get(CatalogPayloadBuilder::class),
            $services->get(CustomerPayloadBuilder::class),
            $services->get(OrderPayloadBuilder::class),
            $services->get(CustomerScope::class)
        ));
        $services->set(CatalogManifest::class, fn (Services $services): CatalogManifest => new CatalogManifest(
            $services->get(Connections::class),
            $services->get(IngestQueue::class),
            $services->get(Client::class),
            $services->get(CatalogPayloadBuilder::class),
            'time'
        ));
        $services->set(QueueProvider::handlerId(IdentityCapture::EVENT_TYPE), fn (Services $services): IdentityMergeHandler => new IdentityMergeHandler(
            $services->get(Connections::class),
            $services->get(Client::class),
            $services->get(HealthCheck::class),
            $services->get(CustomerScope::class),
            $services->get(CustomerPayloadBuilder::class),
            $services->get(IngestQueue::class),
            $services->get(QueueProvider::EVENT_QUEUE)
        ));

        $services->set(IngestMarkers::class, fn (Services $services): IngestMarkers => new IngestMarkers(
            $services->get(IngestQueue::class),
            'register_shutdown_function'
        ));
        $services->set(VisitorToken::class, fn (): VisitorToken => new NoVisitorToken());
        $services->set(CatalogCapture::class, fn (Services $services): CatalogCapture => new CatalogCapture(
            $services->get(Connections::class),
            $services->get(IngestMarkers::class)
        ));
        $services->set(CustomerCapture::class, fn (Services $services): CustomerCapture => new CustomerCapture(
            $services->get(Connections::class),
            $services->get(CustomerScope::class),
            $services->get(IngestMarkers::class)
        ));
        $services->set(OrderCapture::class, fn (Services $services): OrderCapture => new OrderCapture(
            $services->get(Connections::class),
            $services->get(IngestMarkers::class)
        ));
        $services->set(IdentityCapture::class, fn (Services $services): IdentityCapture => new IdentityCapture(
            $services->get(Connections::class),
            $services->get(CustomerScope::class),
            $services->get(VisitorToken::class),
            $services->get(QueueProvider::EVENT_QUEUE),
            'time'
        ));

        $catalog = static fn (): CatalogCapture => $services->get(CatalogCapture::class);
        foreach (['actionObjectProductAddAfter', 'actionObjectProductUpdateAfter'] as $hook) {
            $services->listen($hook, static function (array $params) use ($catalog): void {
                $catalog()->productSaved($params);
            });
        }
        $services->listen('actionUpdateQuantity', static function (array $params) use ($catalog): void {
            $catalog()->quantityUpdated($params);
        });
        $services->listen('actionObjectCombinationUpdateAfter', static function (array $params) use ($catalog): void {
            $catalog()->combinationSaved($params);
        });
        $services->listen('actionObjectCombinationDeleteAfter', static function (array $params) use ($catalog): void {
            $catalog()->combinationDeleted($params);
        });
        foreach (['actionObjectSpecificPriceAddAfter', 'actionObjectSpecificPriceUpdateAfter', 'actionObjectSpecificPriceDeleteAfter'] as $hook) {
            $services->listen($hook, static function (array $params) use ($catalog): void {
                $catalog()->specificPriceChanged($params);
            });
        }
        $services->listen('actionObjectProductDeleteAfter', static function (array $params) use ($catalog): void {
            $catalog()->productDeleted($params);
        });

        $customers = static fn (): CustomerCapture => $services->get(CustomerCapture::class);
        $orders = static fn (): OrderCapture => $services->get(OrderCapture::class);
        $identity = static fn (): IdentityCapture => $services->get(IdentityCapture::class);
        // One listener each: a listener that fails does not hold the next one back (Hook\Dispatcher).
        $services->listen('actionCustomerAccountAdd', static function (array $params) use ($customers): void {
            $customers()->accountAdded($params);
        });
        $services->listen('actionCustomerAccountAdd', static function (array $params) use ($identity): void {
            $identity()->accountAdded($params);
        });
        $services->listen('actionObjectCustomerUpdateAfter', static function (array $params) use ($customers): void {
            $customers()->customerSaved($params);
        });
        $services->listen('actionNewsletterRegistrationAfter', static function (array $params) use ($customers): void {
            $customers()->newsletterSubscribed($params);
        });
        $services->listen('actionAuthentication', static function (array $params) use ($identity): void {
            $identity()->signedIn($params);
        });
        $services->listen('actionValidateOrder', static function (array $params) use ($customers): void {
            $customers()->orderValidated($params);
        });
        $services->listen('actionValidateOrder', static function (array $params) use ($orders): void {
            $orders()->orderChanged($params);
        });
        $services->listen('actionOrderStatusPostUpdate', static function (array $params) use ($orders): void {
            $orders()->statusUpdated($params);
        });
        foreach (['actionOrderEdited', 'actionOrderSlipAdd'] as $hook) {
            $services->listen($hook, static function (array $params) use ($orders): void {
                $orders()->orderChanged($params);
            });
        }

        $services->listen(SchedulerProvider::JOBS, static fn (): Job => new Job(self::FLUSH_JOB, 60, 25, static function (Deadline $deadline) use ($services): void {
            $services->get(IngestFlusher::class)->flush($deadline);
        }));
        $services->listen(SchedulerProvider::JOBS, static fn (): Job => new Job(self::MANIFEST_JOB, 900, 20, static function (Deadline $deadline) use ($services): void {
            $services->get(CatalogManifest::class)->run($deadline);
        }));
    }
}
