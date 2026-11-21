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

use PrestaShop\Module\SmailyForPrestaShop\Config\EngineSettings;
use PrestaShop\Module\SmailyForPrestaShop\Engine\Client;
use PrestaShop\Module\SmailyForPrestaShop\Engine\Connections;
use PrestaShop\Module\SmailyForPrestaShop\Engine\HealthCheck;
use PrestaShop\Module\SmailyForPrestaShop\Engine\Setup;
use PrestaShop\Module\SmailyForPrestaShop\Http\Transport;
use PrestaShop\Module\SmailyForPrestaShop\Runtime\Services;
use PrestaShop\Module\SmailyForPrestaShop\Scheduler\Deadline;
use PrestaShop\Module\SmailyForPrestaShop\Scheduler\Job;
use PrestaShop\Module\SmailyForPrestaShop\Scope\StoreLayout;
use PrestaShop\Module\SmailyForPrestaShop\Smaily\AccountStore;
use PrestaShop\Module\SmailyForPrestaShop\Smaily\Crypto;

/**
 * Registers the services and hook listeners of the Campaign Intelligence connections and ingest.
 *
 * Nothing here connects a shop: a connection exists only after Engine\Setup::connect() exchanged a
 * setup link that the merchant pasted. The health check asks the engine about the stored
 * connections every 15 minutes and sends nothing while there is none.
 */
final class EngineProvider implements ProviderInterface
{
    public const HEALTH_JOB = 'engine_health';

    public function register(Services $services): void
    {
        $services->set(EngineSettings::class, fn (): EngineSettings => new EngineSettings());
        $services->set(Client::class, fn (Services $services): Client => new Client(
            $services->get(Transport::class),
            UpdateProvider::moduleVersion()
        ));
        $services->set(Connections::class, fn (Services $services): Connections => new Connections(
            $services->get(Crypto::class),
            $services->get(StoreLayout::class)
        ));
        $services->set(Setup::class, fn (Services $services): Setup => new Setup(
            $services->get(Client::class),
            $services->get(Connections::class),
            $services->get(EngineSettings::class),
            $services->get(StoreLayout::class),
            $services->get(AccountStore::class),
            UpdateProvider::moduleVersion()
        ));
        $services->set(HealthCheck::class, fn (Services $services): HealthCheck => new HealthCheck(
            $services->get(Client::class),
            $services->get(Connections::class),
            'time'
        ));

        $services->listen(SchedulerProvider::JOBS, static fn (): Job => new Job(self::HEALTH_JOB, 900, 5, static function (Deadline $deadline) use ($services): void {
            $services->get(HealthCheck::class)->run($deadline);
        }));
    }
}
