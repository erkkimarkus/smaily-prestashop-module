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
use PrestaShop\Module\SmailyForPrestaShop\Engine\History\HistoryJobs;

/**
 * What a change of the shop layout does with the Campaign Intelligence connections (owner,
 * 2026-11-23; ARCHITECTURE 8.9): one connection is carried over into the new layout, and every
 * other stored connection is ended.
 *
 * - One store to separate stores: the installation-wide connection (id_shop 0) becomes the
 *   connection of one shop, the shop selected on the page or the default shop.
 * - Separate stores to one store: that shop's connection becomes the installation-wide one.
 *
 * The carried row keeps its id, its key and its engine store, so its queue rows and its imports
 * go on. An ended connection goes as on Disconnect (Disconnector): the installation report's
 * `disconnected` event, the engine told once, the row removed; its imports are cancelled at the
 * next background run and its waiting rows closed. The merchant's cookie-banner confirmation
 * follows EngineSettings::keepConsentFor().
 *
 * It runs once the new layout is in force, and moves the carried row before it ends the others,
 * so each `disconnected` report names the carried connection where the new layout reads it.
 */
final class LayoutConnections
{
    private Connections $connections;

    private Disconnector $disconnector;

    private EngineSettings $settings;

    private HistoryJobs $jobs;

    public function __construct(Connections $connections, Disconnector $disconnector, EngineSettings $settings, HistoryJobs $jobs)
    {
        $this->connections = $connections;
        $this->disconnector = $disconnector;
        $this->settings = $settings;
        $this->jobs = $jobs;
    }

    /**
     * What a change would do, for the page's question before it.
     *
     * @param int $from the id_shop of the connection that is carried: 0 to separate stores, the
     *                  shop to one store
     *
     * @return array{carried: Connection|null, ended: list<Connection>}
     */
    public function plan(int $from): array
    {
        $carried = null;
        $ended = [];
        foreach ($this->connections->all() as $connection) {
            if ($connection->idShop() === $from) {
                $carried = $connection;
            } else {
                $ended[] = $connection;
            }
        }

        return ['carried' => $carried, 'ended' => $ended];
    }

    /**
     * @param int $from as plan()
     * @param int $to the id_shop that the carried connection takes: the shop in separate stores, 0
     *                in one store
     */
    public function change(int $from, int $to): void
    {
        $plan = $this->plan($from);
        $carried = $plan['carried'];
        if ($carried !== null) {
            // A row of the other layout that holds the place goes first: one row per id_shop.
            foreach ($plan['ended'] as $connection) {
                if ($connection->idShop() === $to) {
                    $this->disconnector->disconnect($connection);
                }
            }
            $this->connections->move($carried, $to);
            $this->jobs->moveToShop($carried->id(), $to);
        }
        foreach ($plan['ended'] as $connection) {
            if ($carried === null || $connection->idShop() !== $to) {
                $this->disconnector->disconnect($connection);
            }
        }
        $this->settings->keepConsentFor($to > 0 ? $to : null);
    }
}
