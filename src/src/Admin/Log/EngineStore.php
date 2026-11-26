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

namespace PrestaShop\Module\SmailyForPrestaShop\Admin\Log;

if (!defined('_PS_VERSION_')) {
    exit;
}

use PrestaShop\Module\SmailyForPrestaShop\Engine\Connection;
use PrestaShop\Module\SmailyForPrestaShop\Engine\Connections;
use PrestaShop\Module\SmailyForPrestaShop\Log\LogRows;
use PrestaShop\Module\SmailyForPrestaShop\Queue\EventQueue;

/**
 * The Campaign Intelligence connection that a queue row of the Log goes to, for its Details, as
 * the Magento connector's Model/Log/EngineStore and AccountWait: an ingest row by its
 * `id_connection`, a row of the event queue that goes to the engine (`engine.`) by the connection
 * that serves its shop. An import job and a row to Smaily belong to none.
 *
 * - label(): the engine store's name and the shop it serves, or Magento's sentence that no
 *   connection serves the row; only while the installation holds more than one connection
 *   (separate stores), else null and Details show what they always showed.
 * - waitsForAccount(): a pending ingest row while the engine refuses its connection
 *   (Connection::isTaken()): the flush leaves such rows as they are until the account is active
 *   again (Engine\Ingest\IngestFlusher).
 */
final class EngineStore
{
    private Connections $connections;

    /** @var list<Connection>|null */
    private ?array $all = null;

    public function __construct(Connections $connections)
    {
        $this->connections = $connections;
    }

    /**
     * @param array<string, mixed> $row a row of LogRows::find()
     * @param callable(string, array<string, string|int>=): string $translate
     */
    public function label(array $row, callable $translate): ?string
    {
        if (!self::isEngineRow($row) || count($this->all()) < 2) {
            return null;
        }
        $connection = $this->connectionOf($row);
        if ($connection === null) {
            return $translate('None: no Campaign Intelligence store is connected for this row\'s shop.');
        }
        $settings = $connection->settings();
        $name = is_string($settings['tenant_name'] ?? null) && $settings['tenant_name'] !== '' ? $settings['tenant_name'] : $connection->tenantId();
        $shop = $connection->idShop() > 0 ? \Shop::getShop($connection->idShop()) : false;
        $shopName = is_array($shop) ? (string) $shop['name'] : '';

        return $shopName === '' ? $name : sprintf('%s (%s)', $name, $shopName);
    }

    /**
     * @param array<string, mixed> $row a row of LogRows::find()
     */
    public function waitsForAccount(array $row): bool
    {
        if ($row['kind'] !== LogRows::INTELLIGENCE || (string) $row['status'] !== EventQueue::STATUS_PENDING) {
            return false;
        }
        $connection = $this->connectionOf($row);

        return $connection !== null && !$connection->isTaken();
    }

    /**
     * @param array<string, mixed> $row
     */
    private function connectionOf(array $row): ?Connection
    {
        if ($row['kind'] === LogRows::INTELLIGENCE) {
            foreach ($this->all() as $connection) {
                if ($connection->id() === (int) $row['id_connection']) {
                    return $connection;
                }
            }

            return null;
        }

        return $this->connections->forShop((int) $row['id_shop']);
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function isEngineRow(array $row): bool
    {
        return $row['kind'] !== LogRows::JOB && $row['source'] === LogRows::INTELLIGENCE;
    }

    /**
     * @return list<Connection>
     */
    private function all(): array
    {
        return $this->all ??= $this->connections->all();
    }
}
