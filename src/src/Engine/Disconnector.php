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

namespace PrestaShop\Module\SmailyForPrestaShop\Engine;

if (!defined('_PS_VERSION_')) {
    exit;
}

use PrestaShop\Module\SmailyForPrestaShop\Config\EngineSettings;
use PrestaShop\Module\SmailyForPrestaShop\Engine\Exception\EngineException;
use PrestaShop\Module\SmailyForPrestaShop\Report\EventReport;
use PrestaShop\Module\SmailyForPrestaShop\Report\ReportBuilder;
use PrestaShop\Module\SmailyForPrestaShop\Runtime\Log;

/**
 * Ends a Campaign Intelligence connection (contract §16): tells the engine, which revokes the
 * connection's key, and removes the stored connection with its key.
 *
 * The engine is told once, with a limit of 3 seconds and no retry, as the Magento and WooCommerce
 * connectors tell it: a merchant waits for it. The stored connection goes whatever the engine
 * answers: a refused key or an engine that does not answer never blocks a disconnect or an
 * uninstall, and a key the engine was not told about just stays unused. The engine keeps the
 * store's data; a new setup link connects the shop again.
 *
 * A disconnect on request first sends the installation report's `disconnected` event, while the
 * connection is still stored: the report leaves that connection's engine store out. An uninstall
 * sends `deactivated` instead, once, before any connection is ended (ARCHITECTURE 11.4).
 */
final class Disconnector
{
    /** The engine answered 200: the store has no connection left and is paused. */
    public const PAUSED = 'store_paused';

    /** The engine answered 200: another connection of the store keeps working. */
    public const CONNECTION_REMOVED = 'connection_removed';

    /** The engine answered 200 with another status: the connection ended. */
    public const ENDED = 'ended';

    /** 401: the engine no longer takes the key, so the connection had ended already. */
    public const KEY_REFUSED = 'key_refused';

    /** No answer, or an error other than 401: the engine was not told. */
    public const NOT_TOLD = 'not_told';

    private Client $client;

    private Connections $connections;

    private EngineSettings $settings;

    private EventReport $report;

    /**
     * @param EventReport $report sends the installation report's `disconnected` event
     */
    public function __construct(Client $client, Connections $connections, EngineSettings $settings, EventReport $report)
    {
        $this->client = $client;
        $this->connections = $connections;
        $this->settings = $settings;
        $this->report = $report;
    }

    /**
     * Ends one connection. Its prefix is remembered for the next setup exchange of its shop, which
     * reports it so that the engine gives it back.
     *
     * @return string one of the outcome constants: what the engine was told
     */
    public function disconnect(Connection $connection): string
    {
        $this->report->send(ReportBuilder::EVENT_DISCONNECTED, [$connection->id()]);
        $outcome = $this->tell($connection);
        $this->settings->rememberPrefix($connection->idShop(), $connection->prefix());
        $this->connections->delete($connection);

        return $outcome;
    }

    /**
     * Ends every stored connection, before an uninstall removes the module's data. A connection
     * that cannot be ended stops neither the next one nor the uninstall.
     */
    public function disconnectAll(): void
    {
        foreach ($this->connections->all() as $connection) {
            try {
                $this->tell($connection);
                $this->connections->delete($connection);
            } catch (\Throwable $error) {
                Log::failure('the disconnect of a Campaign Intelligence connection', $error);
            }
        }
    }

    private function tell(Connection $connection): string
    {
        try {
            $status = $this->client->storeUninstall($connection)['status'] ?? null;
        } catch (EngineException $failure) {
            return $failure->httpStatus() === 401 ? self::KEY_REFUSED : self::NOT_TOLD;
        }

        return in_array($status, [self::PAUSED, self::CONNECTION_REMOVED], true) ? $status : self::ENDED;
    }
}
