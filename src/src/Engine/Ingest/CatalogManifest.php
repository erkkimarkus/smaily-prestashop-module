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

namespace PrestaShop\Module\SmailyForPrestaShop\Engine\Ingest;

if (!defined('_PS_VERSION_')) {
    exit;
}

use PrestaShop\Module\SmailyForPrestaShop\Database\Sql;
use PrestaShop\Module\SmailyForPrestaShop\Engine\Client;
use PrestaShop\Module\SmailyForPrestaShop\Engine\Connection;
use PrestaShop\Module\SmailyForPrestaShop\Engine\Connections;
use PrestaShop\Module\SmailyForPrestaShop\Engine\Exception\EngineException;
use PrestaShop\Module\SmailyForPrestaShop\Engine\Exception\EngineRequestException;
use PrestaShop\Module\SmailyForPrestaShop\Engine\Payload\CatalogPayloadBuilder;
use PrestaShop\Module\SmailyForPrestaShop\Scheduler\Deadline;

/**
 * The nightly product list (contract §3c): once a night each connection sends the `sku` and
 * `in_stock` of every row of its own products, in one request. The engine stops recommending a
 * product that the list leaves out and takes the list's stock, so a delete or a stock change that
 * never arrived is healed within a night.
 *
 * The list is the connection's own: the products of its shops, under its prefix
 * (CatalogPayloadBuilder::manifestItems()). It is built right before it is sent and never queued,
 * so it is never older than its send. Each list sent is one row of the Log (domain
 * `catalog_manifest`) with a sample of the list and the engine's answer; a send that fails is not
 * tried again that night, as on the Magento connector: the next night builds a new list.
 *
 * A list is due from 03:30 of the shop's day, once (the first tick after that time sends it).
 * Nothing is sent, and nothing is recorded, while one of these holds; the next run asks again:
 *  - the engine refuses the connection, or its key does not read;
 *  - the connection shares its engine store (`tenant_id`) with another stored connection while
 *    either holds no prefix (suppressed(), ARCHITECTURE 5.3): two lists without a prefix would
 *    each claim the whole engine store and remove the other's products;
 *  - catalog rows or removals of the connection still wait in the queue;
 *  - the connection's shops hold no product: an empty list would say that the catalog is gone.
 * A store with more than 50 000 rows cannot use the list (contract §3c): it sends none, and the
 * Log gets one failed row a night that says so.
 */
final class CatalogManifest
{
    /** Contract §3c: at most this many items, in one request. */
    public const MAX_ITEMS = 50000;

    public const FAILED_TOO_MANY = 'engine_manifest_too_many';

    /** The time of the shop's day from which a night's list is due. */
    public const DUE_FROM = '03:30:00';

    /** Products read per page. */
    private const PAGE_SIZE = 1000;

    /** Items of the list that the Log's row keeps as a sample of what was sent. */
    private const LOGGED_ITEMS = 20;

    private Connections $connections;

    private IngestQueue $queue;

    private Client $client;

    private CatalogPayloadBuilder $catalog;

    /** @var callable(): int */
    private $now;

    /**
     * @param callable(): int $now the current Unix time
     */
    public function __construct(Connections $connections, IngestQueue $queue, Client $client, CatalogPayloadBuilder $catalog, callable $now)
    {
        $this->connections = $connections;
        $this->queue = $queue;
        $this->client = $client;
        $this->catalog = $catalog;
        $this->now = $now;
    }

    /**
     * Sends the list of every connection whose list is due, until the deadline passes.
     */
    public function run(Deadline $deadline): void
    {
        $connections = $this->connections->all();
        foreach ($connections as $connection) {
            if ($deadline->passed()) {
                return;
            }
            if ($this->isDue($connection, $connections)) {
                $this->send($connection, $deadline);
            }
        }
    }

    /**
     * Whether the connection's list of this night goes out now.
     *
     * @param list<Connection> $connections every stored connection
     */
    public function isDue(Connection $connection, array $connections): bool
    {
        if (!IngestFlusher::maySend($connection) || self::suppressed($connection, $connections)) {
            return false;
        }
        $now = ($this->now)();
        $from = (int) strtotime(date('Y-m-d', $now) . ' ' . self::DUE_FROM);
        if ($from > $now) {
            $from = (int) strtotime(date('Y-m-d', $now - 86400) . ' ' . self::DUE_FROM);
        }
        $last = $this->queue->newest($connection->id(), IngestQueue::DOMAIN_MANIFEST);
        if ($last !== null && (int) strtotime($last . ' UTC') >= $from) {
            return false;
        }

        return !$this->queue->hasUndelivered($connection->id(), [IngestQueue::DOMAIN_CATALOG, IngestQueue::DOMAIN_CATALOG_REMOVE]);
    }

    /**
     * Whether a connection sends no list because it shares its engine store with another stored
     * connection while either of the two holds no prefix (ARCHITECTURE 5.3).
     *
     * @param list<Connection> $connections every stored connection
     */
    public static function suppressed(Connection $connection, array $connections): bool
    {
        foreach ($connections as $other) {
            if ($other->id() !== $connection->id()
                && $other->tenantId() === $connection->tenantId()
                && ($other->prefix() === '' || $connection->prefix() === '')
            ) {
                return true;
            }
        }

        return false;
    }

    private function send(Connection $connection, Deadline $deadline): void
    {
        $shops = implode(', ', ConnectionShops::of($connection));
        $items = [];
        $after = 0;
        do {
            if ($deadline->passed()) {
                // No partial list: the next run builds it again.
                return;
            }
            $ids = array_map('intval', array_column(Sql::rows(
                'SELECT DISTINCT `id_product` FROM `' . _DB_PREFIX_ . 'product_shop`'
                . ' WHERE `id_shop` IN (' . $shops . ') AND `id_product` > ' . $after
                . ' ORDER BY `id_product` LIMIT ' . self::PAGE_SIZE
            ), 'id_product'));
            if ($ids !== []) {
                $after = max($ids);
                array_push($items, ...$this->catalog->manifestItems($connection, $ids));
            }
        } while (count($ids) === self::PAGE_SIZE && count($items) <= self::MAX_ITEMS);

        if ($items === []) {
            return;
        }
        $summary = ['count' => count($items), 'in_stock' => count(array_filter(array_column($items, 'in_stock')))];
        if (count($items) > self::MAX_ITEMS) {
            $this->queue->record($connection->id(), IngestQueue::DOMAIN_MANIFEST, '', ['limit' => self::MAX_ITEMS], null, self::FAILED_TOO_MANY);

            return;
        }

        $request = $summary + ['products' => array_slice($items, 0, self::LOGGED_ITEMS)];
        try {
            $answer = $this->client->catalogManifest($connection, $items);
        } catch (EngineException $failure) {
            $this->queue->record(
                $connection->id(),
                IngestQueue::DOMAIN_MANIFEST,
                '',
                $request,
                $failure->httpStatus() > 0 ? ['http_status' => $failure->httpStatus(), 'body' => ['error' => $failure->errorCode()]] : null,
                $failure instanceof EngineRequestException
                    ? sprintf('permanent_http_%d: %s', $failure->httpStatus(), $failure->errorCode())
                    : $failure->errorCode()
            );

            return;
        }
        $this->queue->record($connection->id(), IngestQueue::DOMAIN_MANIFEST, '', $request, ['http_status' => 200, 'body' => IngestFlusher::withoutText($answer)], null);
    }
}
