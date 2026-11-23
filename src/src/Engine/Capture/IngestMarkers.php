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

use PrestaShop\Module\SmailyForPrestaShop\Engine\Connection;
use PrestaShop\Module\SmailyForPrestaShop\Engine\Ingest\IngestFlusher;
use PrestaShop\Module\SmailyForPrestaShop\Engine\Ingest\IngestQueue;
use PrestaShop\Module\SmailyForPrestaShop\Runtime\Log;

/**
 * What one request has to tell Campaign Intelligence, written to the ingest queue when the request
 * ends: one row per connection, domain and product, customer or order, whatever number of hooks
 * named it. A product save on the product page fires its hook seven times, and a combination
 * "Save list" once per row of the list (facts file part 2): each leaves one catalog row for the
 * product.
 *
 * A marker names its entity and holds nothing of it: the flush reads the store when it sends
 * (Engine\Ingest\IngestFlusher).
 */
final class IngestMarkers
{
    private IngestQueue $queue;

    /** @var callable(callable): void */
    private $onShutdown;

    /** @var array<string, array{id_connection: int, domain: string, id: int, removed: list<int>}> */
    private array $markers = [];

    private bool $scheduled = false;

    /**
     * @param callable(callable): void $onShutdown registers a function to run at shutdown
     */
    public function __construct(IngestQueue $queue, callable $onShutdown)
    {
        $this->queue = $queue;
        $this->onShutdown = $onShutdown;
    }

    /**
     * Notes that a product, a customer or an order goes to a connection.
     *
     * @param list<int> $removed for a catalog marker: combinations of the product that were deleted
     */
    public function add(Connection $connection, string $domain, int $id, array $removed = []): void
    {
        if ($id <= 0) {
            return;
        }
        $key = self::key($connection->id(), $domain, $id);
        $this->markers[$key] = [
            'id_connection' => $connection->id(),
            'domain' => $domain,
            'id' => $id,
            'removed' => array_values(array_unique(array_merge($this->markers[$key]['removed'] ?? [], $removed))),
        ];
        if (!$this->scheduled) {
            $this->scheduled = true;
            ($this->onShutdown)([$this, 'atShutdown']);
        }
    }

    /**
     * Whether this request already holds a marker of the domain for the entity, for any
     * connection: a hook that fires again for it has nothing to add.
     */
    public function has(string $domain, int $id): bool
    {
        foreach ($this->markers as $marker) {
            if ($marker['domain'] === $domain && $marker['id'] === $id) {
                return true;
            }
        }

        return false;
    }

    /**
     * Forgets a marker of this request: a product that was changed and then deleted has no rows
     * left to send.
     */
    public function drop(Connection $connection, string $domain, int $id): void
    {
        unset($this->markers[self::key($connection->id(), $domain, $id)]);
    }

    /**
     * Writes the request's markers as rows of the ingest queue.
     *
     * @return int the number of rows written
     */
    public function commit(): int
    {
        $markers = $this->markers;
        $this->markers = [];
        $this->queue->enqueueMany(array_map(static fn (array $marker): array => [
            'id_connection' => $marker['id_connection'],
            'domain' => $marker['domain'],
            'entity_key' => (string) $marker['id'],
            'payload' => $marker['removed'] === [] ? [] : [IngestFlusher::REMOVED => $marker['removed']],
        ], array_values($markers)));

        return count($markers);
    }

    public function atShutdown(): void
    {
        // A marker added after this point schedules another write.
        $this->scheduled = false;
        try {
            $this->commit();
        } catch (\Throwable $error) {
            Log::failure('the Campaign Intelligence capture', $error);
        }
    }

    private static function key(int $idConnection, string $domain, int $id): string
    {
        return $idConnection . ':' . $domain . ':' . $id;
    }
}
