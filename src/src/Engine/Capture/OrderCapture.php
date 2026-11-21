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

namespace PrestaShop\Module\SmailyForPrestaShop\Engine\Capture;

if (!defined('_PS_VERSION_')) {
    exit;
}

use PrestaShop\Module\SmailyForPrestaShop\Database\Sql;
use PrestaShop\Module\SmailyForPrestaShop\Engine\Connections;
use PrestaShop\Module\SmailyForPrestaShop\Engine\Ingest\IngestQueue;

/**
 * Notes the orders that changed, for Campaign Intelligence (ARCHITECTURE section 7): an order that
 * was placed, changed its status, was edited, or got a credit slip. Each marks the order once per
 * request for the connection of the order's shop; the flush sends the whole order as it is then,
 * with its status and the lines that were returned.
 *
 * Nothing is noted where no connection is stored for the order's shop.
 */
final class OrderCapture
{
    private Connections $connections;

    private IngestMarkers $markers;

    public function __construct(Connections $connections, IngestMarkers $markers)
    {
        $this->connections = $connections;
        $this->markers = $markers;
    }

    /**
     * actionValidateOrder, actionOrderEdited, actionOrderSlipAdd: the hook hands the order over.
     *
     * @param array<string, mixed> $params
     */
    public function orderChanged(array $params): void
    {
        $order = $params['order'] ?? null;
        if ($order instanceof \Order) {
            $this->changed((int) $order->id, (int) $order->id_shop);
        }
    }

    /**
     * actionOrderStatusPostUpdate: the hook names the order by its id.
     *
     * @param array<string, mixed> $params
     */
    public function statusUpdated(array $params): void
    {
        $this->changed((int) ($params['id_order'] ?? 0), 0);
    }

    /**
     * @param int $idShop the order's shop; 0 when the hook did not hand it over
     */
    private function changed(int $idOrder, int $idShop): void
    {
        if ($idOrder <= 0 || $this->markers->has(IngestQueue::DOMAIN_ORDERS, $idOrder) || $this->connections->all() === []) {
            return;
        }
        if ($idShop <= 0) {
            $idShop = (int) Sql::value('SELECT `id_shop` FROM `' . _DB_PREFIX_ . 'orders` WHERE `id_order` = ' . $idOrder);
        }
        $connection = $idShop > 0 ? $this->connections->forShop($idShop) : null;
        if ($connection !== null) {
            $this->markers->add($connection, IngestQueue::DOMAIN_ORDERS, $idOrder);
        }
    }
}
