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
use PrestaShop\Module\SmailyForPrestaShop\Contacts\Import\ContactImport;
use PrestaShop\Module\SmailyForPrestaShop\Contacts\Import\ContactSource;
use PrestaShop\Module\SmailyForPrestaShop\Contacts\Import\JobStore;
use PrestaShop\Module\SmailyForPrestaShop\Contacts\Reconciler;
use PrestaShop\Module\SmailyForPrestaShop\Contacts\SubscriberPayloadBuilder;
use PrestaShop\Module\SmailyForPrestaShop\Http\Transport;
use PrestaShop\Module\SmailyForPrestaShop\Privacy\ProfilingConsent;
use PrestaShop\Module\SmailyForPrestaShop\Runtime\Services;
use PrestaShop\Module\SmailyForPrestaShop\Scheduler\Deadline;
use PrestaShop\Module\SmailyForPrestaShop\Scheduler\Job;
use PrestaShop\Module\SmailyForPrestaShop\Scope\ScopeResolver;

/**
 * Registers the contact reconcile, the contact imports and their background jobs (ARCHITECTURE
 * 4.1, the intervals and budgets of the sibling connectors):
 *  - `contacts_reconcile`, every 15 minutes for up to 15 s: Smaily's consent changes into the store
 *    (Magento's ContactReconcile);
 *  - `contacts_refresh`, daily for up to 5 s: queues the daily refresh, which the import job sends;
 *  - `contacts_import`, every minute for up to 15 s: pages of the first import and of the daily
 *    refresh (Magento's BackfillTick).
 *
 * The admin screens reach the import through `Services::instance()->get(ContactImport::class)`.
 */
final class ContactReconcileProvider implements ProviderInterface
{
    public function register(Services $services): void
    {
        $services->set(Reconciler::class, static function (Services $services): Reconciler {
            $reconciler = new Reconciler(
                $services->get(ScopeResolver::class),
                $services->get(ContactsSettings::class),
                $services->get(Transport::class)
            );
            // Leaving marketing also stops profiling, and joining again lifts that (section 11.2).
            $reconciler->onConsentChange(static fn (int $idShop, string $email, bool $subscribed): int => $services->get(ProfilingConsent::class)->onContactEvent(
                $idShop,
                null,
                ['email' => $email, 'subscribed' => $subscribed, 'consent_changed' => true]
            ));

            return $reconciler;
        });
        $services->set(ContactImport::class, fn (Services $services): ContactImport => new ContactImport(
            new JobStore(),
            new ContactSource(),
            $services->get(ScopeResolver::class),
            $services->get(ContactsSettings::class),
            $services->get(SubscriberPayloadBuilder::class),
            $services->get(Transport::class)
        ));

        $services->listen(SchedulerProvider::JOBS, static fn (): Job => new Job('contacts_reconcile', 900, 15, static function (Deadline $deadline) use ($services): void {
            $services->get(Reconciler::class)->run($deadline);
        }));
        $services->listen(SchedulerProvider::JOBS, static fn (): Job => new Job('contacts_refresh', 86400, 5, static function () use ($services): void {
            $services->get(ContactImport::class)->scheduleRefresh();
        }));
        $services->listen(SchedulerProvider::JOBS, static fn (): Job => new Job('contacts_import', 60, 15, static function (Deadline $deadline) use ($services): void {
            $services->get(ContactImport::class)->run($deadline);
        }));
    }
}
