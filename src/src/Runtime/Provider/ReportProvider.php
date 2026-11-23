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

use PrestaShop\Module\SmailyForPrestaShop\Config\ReportSettings;
use PrestaShop\Module\SmailyForPrestaShop\Report\ConnectionHealth;
use PrestaShop\Module\SmailyForPrestaShop\Runtime\Services;
use PrestaShop\Module\SmailyForPrestaShop\Smaily\Client;

/**
 * Registers the services and hook listeners of the installation report (ARCHITECTURE 11.1).
 *
 * Every Smaily API client tells the connection health how its request went.
 */
final class ReportProvider implements ProviderInterface
{
    public function register(Services $services): void
    {
        $services->set(ReportSettings::class, fn (): ReportSettings => new ReportSettings());
        $services->set(ConnectionHealth::class, fn (Services $services): ConnectionHealth => new ConnectionHealth(
            $services->get(ReportSettings::class),
            'time'
        ));

        Client::observe(static function (string $subdomain, string $errorCode, bool $wrote) use ($services): void {
            $services->get(ConnectionHealth::class)->recordSmaily($subdomain, $errorCode, $wrote);
        });
    }
}
