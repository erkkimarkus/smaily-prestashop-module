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
use PrestaShop\Module\SmailyForPrestaShop\Engine\Connection;
use PrestaShop\Module\SmailyForPrestaShop\Engine\Connections;
use PrestaShop\Module\SmailyForPrestaShop\Engine\Ingest\ConnectionShops;
use PrestaShop\Module\SmailyForPrestaShop\Engine\Ingest\IngestQueue;

/**
 * Notes the products that changed, for Campaign Intelligence (ARCHITECTURE section 7):
 *
 * - a product save, a stock change, a combination save and a change of a specific price each mark
 *   the product, once per request, for the connection of every shop that holds it;
 * - a deleted combination marks the product and names the combination, which is sent once more as
 *   out of stock;
 * - a deleted product becomes a removal (§3b) for the connection of every shop it was deleted
 *   from, unless another shop of that connection still holds it: then the product is marked as
 *   changed, because the shops that sell it changed.
 *
 * Nothing is noted where no connection is stored for the shop, and a listener reads nothing of the
 * product then. Nothing of the product is read here but the shops that hold it: the flush builds
 * the rows.
 */
final class CatalogCapture
{
    private Connections $connections;

    private IngestMarkers $markers;

    public function __construct(Connections $connections, IngestMarkers $markers)
    {
        $this->connections = $connections;
        $this->markers = $markers;
    }

    /**
     * actionObjectProductAddAfter, actionObjectProductUpdateAfter.
     *
     * @param array<string, mixed> $params
     */
    public function productSaved(array $params): void
    {
        $product = $params['object'] ?? null;
        if ($product instanceof \Product) {
            $this->changed((int) $product->id);
        }
    }

    /**
     * actionUpdateQuantity.
     *
     * @param array<string, mixed> $params
     */
    public function quantityUpdated(array $params): void
    {
        $this->changed((int) ($params['id_product'] ?? 0));
    }

    /**
     * actionObjectCombinationUpdateAfter.
     *
     * @param array<string, mixed> $params
     */
    public function combinationSaved(array $params): void
    {
        $combination = $params['object'] ?? null;
        if ($combination instanceof \Combination) {
            $this->changed((int) $combination->id_product);
        }
    }

    /**
     * actionObjectCombinationDeleteAfter.
     *
     * @param array<string, mixed> $params
     */
    public function combinationDeleted(array $params): void
    {
        $combination = $params['object'] ?? null;
        if ($combination instanceof \Combination && (int) $combination->id > 0) {
            $this->changed((int) $combination->id_product, [(int) $combination->id]);
        }
    }

    /**
     * actionObjectSpecificPriceAddAfter, …UpdateAfter, …DeleteAfter. A specific price that names
     * no product is not followed: the next change of a product sends its price.
     *
     * @param array<string, mixed> $params
     */
    public function specificPriceChanged(array $params): void
    {
        $specificPrice = $params['object'] ?? null;
        if ($specificPrice instanceof \SpecificPrice) {
            $this->changed((int) $specificPrice->id_product);
        }
    }

    /**
     * actionObjectProductDeleteAfter: PrestaShop removed the product from the shops of its shop
     * context, and deleted it where no other shop holds it.
     *
     * @param array<string, mixed> $params
     */
    public function productDeleted(array $params): void
    {
        $product = $params['object'] ?? null;
        $idProduct = $product instanceof \Product ? (int) $product->id : 0;
        if ($idProduct <= 0) {
            return;
        }
        $holding = null;
        foreach ($this->connectionsOf(array_map('intval', \Shop::getContextListShopID())) as $connection) {
            $holding = $holding ?? $this->shopsOf($idProduct);
            if (array_intersect(ConnectionShops::of($connection), $holding) !== []) {
                $this->markers->add($connection, IngestQueue::DOMAIN_CATALOG, $idProduct);
            } else {
                $this->markers->drop($connection, IngestQueue::DOMAIN_CATALOG, $idProduct);
                $this->markers->add($connection, IngestQueue::DOMAIN_CATALOG_REMOVE, $idProduct);
            }
        }
    }

    /**
     * @param list<int> $removed combinations of the product that were deleted
     */
    private function changed(int $idProduct, array $removed = []): void
    {
        if ($idProduct <= 0 || ($removed === [] && $this->markers->has(IngestQueue::DOMAIN_CATALOG, $idProduct))) {
            return;
        }
        $stored = $this->connections->all();
        if ($stored === []) {
            return;
        }
        foreach ($this->connectionsOf($this->shopsOf($idProduct), $stored) as $connection) {
            $this->markers->add($connection, IngestQueue::DOMAIN_CATALOG, $idProduct, $removed);
        }
    }

    /**
     * The stored connections that serve these shops, each once.
     *
     * @param list<int> $shops
     * @param list<Connection>|null $stored the stored connections, when the caller has read them
     *
     * @return array<int, Connection> by connection id
     */
    private function connectionsOf(array $shops, ?array $stored = null): array
    {
        $byShop = [];
        foreach ($stored ?? $this->connections->all() as $connection) {
            $byShop[$connection->idShop()] = $connection;
        }
        $connections = [];
        foreach ($byShop === [] ? [] : $shops as $idShop) {
            $connection = $byShop[$this->connections->keyOf($idShop)] ?? null;
            if ($connection !== null) {
                $connections[$connection->id()] = $connection;
            }
        }

        return $connections;
    }

    /**
     * @return list<int> the shops that hold the product
     */
    private function shopsOf(int $idProduct): array
    {
        return array_map('intval', array_column(Sql::rows(
            'SELECT `id_shop` FROM `' . _DB_PREFIX_ . 'product_shop` WHERE `id_product` = ' . $idProduct
        ), 'id_shop'));
    }
}
