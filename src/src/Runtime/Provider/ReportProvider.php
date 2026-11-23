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

use PrestaShop\Module\SmailyForPrestaShop\Automation\WorkflowResolverInterface;
use PrestaShop\Module\SmailyForPrestaShop\Config\ContactsSettings;
use PrestaShop\Module\SmailyForPrestaShop\Config\EngineSettings;
use PrestaShop\Module\SmailyForPrestaShop\Config\ReportSettings;
use PrestaShop\Module\SmailyForPrestaShop\Engine\Connection;
use PrestaShop\Module\SmailyForPrestaShop\Engine\Connections;
use PrestaShop\Module\SmailyForPrestaShop\Http\Transport;
use PrestaShop\Module\SmailyForPrestaShop\Install\Uninstaller;
use PrestaShop\Module\SmailyForPrestaShop\Report\ConnectionHealth;
use PrestaShop\Module\SmailyForPrestaShop\Report\EventReport;
use PrestaShop\Module\SmailyForPrestaShop\Report\InstallationReport;
use PrestaShop\Module\SmailyForPrestaShop\Report\ReportBuilder;
use PrestaShop\Module\SmailyForPrestaShop\Report\ReportSender;
use PrestaShop\Module\SmailyForPrestaShop\Report\SetupChange;
use PrestaShop\Module\SmailyForPrestaShop\Report\StoreFacts;
use PrestaShop\Module\SmailyForPrestaShop\Runtime\Services;
use PrestaShop\Module\SmailyForPrestaShop\Scheduler\Job;
use PrestaShop\Module\SmailyForPrestaShop\Scope\AccountResolver;
use PrestaShop\Module\SmailyForPrestaShop\Scope\StoreLayout;
use PrestaShop\Module\SmailyForPrestaShop\Smaily\Client;

/**
 * Registers the services and hook listeners of the installation report (ARCHITECTURE 11.1).
 *
 * - The tick's job asks on every tick whether a snapshot is due and sends at most one.
 * - An uninstall sends `deactivated` first, before any other area's listener ends what the report
 *   describes: the engine is told to disconnect after it.
 * - Every Smaily API client tells the connection health how its request went.
 *
 * The report has no setting and shows nothing: a report that the service does not take in leaves
 * one line in the shop's log.
 */
final class ReportProvider implements ProviderInterface
{
    public const JOB = 'installation_report';

    public function register(Services $services): void
    {
        $services->set(ReportSettings::class, fn (): ReportSettings => new ReportSettings());
        $services->set(ConnectionHealth::class, fn (Services $services): ConnectionHealth => new ConnectionHealth(
            $services->get(ReportSettings::class),
            'time'
        ));
        $services->set(ReportBuilder::class, fn (Services $services): ReportBuilder => new ReportBuilder(
            new StoreFacts(),
            $services->get(StoreLayout::class),
            $services->get(AccountResolver::class),
            $services->get(ContactsSettings::class),
            $services->get(WorkflowResolverInterface::class),
            static fn (int $idShop): ?Connection => $services->get(Connections::class)->forShop($idShop),
            static fn (int $idShop): bool => $services->get(EngineSettings::class)->consentConfirmed($idShop),
            $services->get(ConnectionHealth::class),
            UpdateProvider::moduleVersion(),
            'time'
        ));
        $services->set(ReportSender::class, fn (Services $services): ReportSender => new ReportSender(
            $services->get(Transport::class),
            UpdateProvider::moduleVersion()
        ));
        $services->set(InstallationReport::class, fn (Services $services): InstallationReport => new InstallationReport(
            $services->get(ReportBuilder::class),
            $services->get(ReportSender::class),
            $services->get(ReportSettings::class),
            'time',
            'random_int',
            [self::class, 'log']
        ));
        $services->set(EventReport::class, fn (Services $services): EventReport => new EventReport(
            $services->get(ReportBuilder::class),
            $services->get(ReportSender::class),
            [self::class, 'log']
        ));
        $services->set(SetupChange::class, fn (Services $services): SetupChange => new SetupChange(
            $services->get(ReportBuilder::class),
            $services->get(EventReport::class),
            $services->get(InstallationReport::class)
        ));

        Client::observe(static function (string $subdomain, string $errorCode, bool $wrote) use ($services): void {
            $services->get(ConnectionHealth::class)->recordSmaily($subdomain, $errorCode, $wrote);
        });

        $services->listen(SchedulerProvider::JOBS, static fn (): Job => new Job(self::JOB, 0, ReportSender::TIMEOUT_SECONDS, static function () use ($services): void {
            $services->get(InstallationReport::class)->run();
        }));
        $services->listenFirst(Uninstaller::BEFORE_REMOVAL, static function () use ($services): void {
            $services->get(EventReport::class)->send(ReportBuilder::EVENT_DEACTIVATED);
        });
    }

    /**
     * Writes one line of the installation report to the shop's log (Advanced Parameters > Logs).
     * A line that says the same as an earlier one is written again: each report that is given up
     * leaves its own.
     */
    public static function log(string $line): void
    {
        \PrestaShopLogger::addLog('Smaily Connect: ' . $line, 2, null, null, null, true);
    }
}
