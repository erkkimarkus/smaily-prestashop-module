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

namespace PrestaShop\Module\SmailyForPrestaShop\Admin\Dashboard;

if (!defined('_PS_VERSION_')) {
    exit;
}

use PrestaShop\Module\SmailyForPrestaShop\Config\EngineSettings;
use PrestaShop\Module\SmailyForPrestaShop\Engine\Connection;
use PrestaShop\Module\SmailyForPrestaShop\Engine\Connections;
use PrestaShop\Module\SmailyForPrestaShop\Engine\HealthCheck;
use PrestaShop\Module\SmailyForPrestaShop\Engine\Ingest\CatalogManifest;

/**
 * Reads what the Dashboard says of Campaign Intelligence, from stored state only: the connection
 * of the selected setup with what its last health check found, the merchant's cookie-banner
 * confirmation, the catalog rows delivered, and the nightly product list. It asks the engine
 * nothing and writes nothing.
 *
 * The setup is the Intelligence tab's (Admin\Setup\SetupScope::targetShop()): 0 in one store,
 * the selected shop in separate stores, and null there while "All shops" is selected. Then no one
 * connection is shown, and the counts cover every connection.
 */
final class EngineFacts
{
    private Connections $connections;

    private EngineSettings $settings;

    private CatalogManifest $manifest;

    private DashboardQueries $queries;

    public function __construct(Connections $connections, EngineSettings $settings, CatalogManifest $manifest, DashboardQueries $queries)
    {
        $this->connections = $connections;
        $this->settings = $settings;
        $this->manifest = $manifest;
        $this->queries = $queries;
    }

    /**
     * @param int|null $shop the setup whose connection shows; null for every shop of separate stores
     *
     * @return array<string, mixed> `per_shop` (no one connection shows), `connected`, `name`, `state`
     *                              (Connection::state(), '' without a connection),
     *                              `unreachable`, `consent`, `catalog_items` (null without a
     *                              connection), `shared_store` and `unsent_nights`
     */
    public function collect(?int $shop, int $now): array
    {
        $all = $this->connections->all();
        $connection = null;
        foreach ($all as $stored) {
            if ($stored->idShop() === $shop) {
                $connection = $stored;
            }
        }
        $shown = $shop === null ? $all : array_filter([$connection]);
        $state = $connection === null ? '' : $connection->state();
        $settings = $connection === null ? [] : $connection->settings();
        $nights = array_map(fn (Connection $one): int => $this->manifest->nightsUnsent($one, $all), $shown);

        return [
            'per_shop' => $shop === null,
            'connected' => $connection !== null,
            'name' => is_string($settings['tenant_name'] ?? null) ? $settings['tenant_name'] : '',
            'state' => $state,
            'unreachable' => $state === Connection::STATE_CONNECTED && $connection->health()['status'] === HealthCheck::UNREACHABLE,
            'consent' => $shop !== null && $this->settings->consentConfirmed($shop),
            'catalog_items' => $shown === [] ? null : $this->queries->catalogItems(
                array_map(static fn (Connection $one): int => $one->id(), array_values($shown)),
                $now
            ),
            'shared_store' => array_filter($shown, static fn (Connection $one): bool => CatalogManifest::suppressed($one, $all)) !== [],
            'unsent_nights' => $nights === [] ? 0 : max($nights),
        ];
    }
}
