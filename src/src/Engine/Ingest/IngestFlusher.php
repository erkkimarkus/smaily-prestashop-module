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
use PrestaShop\Module\SmailyForPrestaShop\Engine\HealthCheck;
use PrestaShop\Module\SmailyForPrestaShop\Engine\Payload\CatalogPayloadBuilder;
use PrestaShop\Module\SmailyForPrestaShop\Engine\Payload\CustomerPayloadBuilder;
use PrestaShop\Module\SmailyForPrestaShop\Engine\Payload\Keys;
use PrestaShop\Module\SmailyForPrestaShop\Engine\Payload\OrderPayloadBuilder;
use PrestaShop\Module\SmailyForPrestaShop\Privacy\ProfilingConsent;
use PrestaShop\Module\SmailyForPrestaShop\Queue\Failure;
use PrestaShop\Module\SmailyForPrestaShop\Scheduler\Deadline;

/**
 * Drains the ingest queue: for each connection that may send, one batch of each domain in turn,
 * round after round, until nothing is due or the deadline passes.
 *
 * What goes out is built now, from the store as it is (the payload builders), for the row's own
 * connection and under its prefix. A row is closed without a request where the store has nothing
 * to send for it: its product, customer or order is gone, its customer left the customer scope
 * (CustomerScope), or its product still exists (a removal). The reasons are the SKIPPED_ codes.
 *
 * Gates. Rows are sent only for a stored connection whose key reads and that the engine does not
 * refuse (Connection::isTaken()): a refused connection's rows wait as they are. A row whose
 * connection is no longer stored is closed (IngestQueue::closeOrphans()).
 *
 * Outcomes, as the Magento connector's flush decides them:
 *  - the engine's `errors[]` names the rows it refused, by position: such a row is parked as
 *    failed at once (FAILED_ITEM_REFUSED), and the other rows of the request are sent;
 *  - a request that fails as a whole and may pass later (no answer, a 429, a 5xx) fails its rows
 *    onto the retry ladder and ends the connection's turn for this run;
 *  - a request that the engine refuses as a whole parks its rows and ends the domain's turn,
 *    unless the engine now refuses the connection itself (asked with a ping, which records it):
 *    then the rows go back as they were and wait.
 * A removal (§3b) has no position-aligned errors: every id of an answered request was applied,
 * also one the engine did not find, except an id that `errors[]` names.
 *
 * Each row keeps what was sent for it and the engine's answer for it, without the engine's
 * `message`, which can name a shopper. One row that cannot be built fails alone.
 *
 * A customer or an order that the engine took has its shopper's stored opt-out of personalization
 * sent again (ProfilingConsent::resendOptOuts(), as the Magento connector's OptOutReplay): the
 * engine keeps an opt-out only for a shopper it holds, and these rows carry no consent.
 */
final class IngestFlusher
{
    /** Rows claimed per batch. A catalog row can be many items: one per combination. */
    public const BATCH = [
        IngestQueue::DOMAIN_CATALOG => 20,
        IngestQueue::DOMAIN_CATALOG_REMOVE => 100,
        IngestQueue::DOMAIN_CUSTOMERS => 100,
        IngestQueue::DOMAIN_ORDERS => 50,
    ];

    /** Items per request: the contract's recommended batch, and the orders route's limit of 50. */
    public const ITEMS_PER_REQUEST = [
        IngestQueue::DOMAIN_CATALOG => 100,
        IngestQueue::DOMAIN_CUSTOMERS => 100,
        IngestQueue::DOMAIN_ORDERS => 50,
    ];

    public const SKIPPED_PRODUCT_GONE = 'engine_product_gone';

    public const SKIPPED_PRODUCT_EXISTS = 'engine_product_exists';

    public const SKIPPED_CUSTOMER_GONE = 'engine_customer_gone';

    public const SKIPPED_NOT_IN_SCOPE = 'engine_customer_not_in_scope';

    public const SKIPPED_ORDER_GONE = 'engine_order_gone';

    public const FAILED_ITEM_REFUSED = 'engine_item_refused';

    /** The field that names the shopper in an item of the domains whose rows are a shopper's. */
    private const SHOPPER = [IngestQueue::DOMAIN_CUSTOMERS => 'email', IngestQueue::DOMAIN_ORDERS => 'customer_email'];

    /** The payload key of the combinations that a catalog change removed. */
    public const REMOVED = 'removed';

    private IngestQueue $queue;

    private Connections $connections;

    private Client $client;

    private HealthCheck $health;

    private CatalogPayloadBuilder $catalog;

    private CustomerPayloadBuilder $customers;

    private OrderPayloadBuilder $orders;

    private CustomerScope $scope;

    private ProfilingConsent $profiling;

    /** @var array<int, true> the connections that send nothing more in this run, by id */
    private array $stopped = [];

    /** @var array<string, true> "<connection id>:<domain>" that send nothing more in this run */
    private array $halted = [];

    public function __construct(IngestQueue $queue, Connections $connections, Client $client, HealthCheck $health, CatalogPayloadBuilder $catalog, CustomerPayloadBuilder $customers, OrderPayloadBuilder $orders, CustomerScope $scope, ProfilingConsent $profiling)
    {
        $this->queue = $queue;
        $this->connections = $connections;
        $this->client = $client;
        $this->health = $health;
        $this->catalog = $catalog;
        $this->customers = $customers;
        $this->orders = $orders;
        $this->scope = $scope;
        $this->profiling = $profiling;
    }

    public function flush(Deadline $deadline): void
    {
        $connections = $this->connections->all();
        $this->queue->requeueStale();
        $this->queue->closeOrphans(array_map(static fn (Connection $connection): int => $connection->id(), $connections));

        $sending = array_values(array_filter($connections, static fn (Connection $connection): bool => $connection->isTaken()));
        $this->stopped = [];
        $this->halted = [];
        do {
            $claimed = false;
            foreach ($sending as $connection) {
                foreach (IngestQueue::QUEUED as $domain) {
                    if ($deadline->passed()) {
                        return;
                    }
                    if (isset($this->stopped[$connection->id()]) || isset($this->halted[$connection->id() . ':' . $domain])) {
                        continue;
                    }
                    $claimed = $this->flushDomain($connection, $domain) || $claimed;
                }
            }
        } while ($claimed);
    }

    /**
     * Sends one batch of a connection's rows of a domain.
     *
     * @return bool whether a batch was claimed
     */
    private function flushDomain(Connection $connection, string $domain): bool
    {
        $rows = $this->queue->claim($connection->id(), $domain, self::BATCH[$domain]);
        if ($rows === []) {
            return false;
        }

        $entries = [];
        foreach ($rows as $row) {
            try {
                $items = $this->build($connection, $domain, $row);
            } catch (\Throwable $error) {
                $this->queue->markFailed($row, Failure::of($error));
                continue;
            }
            if (is_string($items)) {
                $this->queue->markSkipped($row, $items);
            } elseif ($items === []) {
                $this->queue->discard($row);
            } else {
                $entries[] = ['row' => $row, 'items' => $items];
            }
        }
        if ($entries !== []) {
            $domain === IngestQueue::DOMAIN_CATALOG_REMOVE ? $this->remove($connection, $entries) : $this->send($connection, $domain, $entries);
        }

        return true;
    }

    /**
     * What goes out for a row: its items, or the reason it is closed without a send. An empty
     * list is a row with nothing to send and nothing to tell.
     *
     * @param array<string, string|null> $row
     *
     * @return list<array<string, mixed>|string>|string
     */
    private function build(Connection $connection, string $domain, array $row)
    {
        $id = (int) $row['entity_key'];
        switch ($domain) {
            case IngestQueue::DOMAIN_CATALOG:
                $payload = json_decode((string) $row['payload'], true);
                $removed = is_array($payload) && is_array($payload[self::REMOVED] ?? null) ? array_map('intval', $payload[self::REMOVED]) : [];
                $items = $this->catalog->rows($connection, $id, $removed);
                if ($items === null) {
                    return self::SKIPPED_PRODUCT_GONE;
                }

                return array_map(fn (array $item): array => ['event_id' => self::eventId($connection, $row, (string) $item['sku'])] + $item, $items);
            case IngestQueue::DOMAIN_CATALOG_REMOVE:
                $held = Sql::value('SELECT 1 FROM ' . ConnectionShops::products($connection) . ' AND `id_product` = ' . $id);

                return $held !== null ? self::SKIPPED_PRODUCT_EXISTS : [Keys::productId($connection->prefix(), $id)];
            case IngestQueue::DOMAIN_CUSTOMERS:
                $customer = $this->scope->customer($id);
                if ($customer === null) {
                    return self::SKIPPED_CUSTOMER_GONE;
                }
                if (!$this->scope->includes($connection, $customer)) {
                    return self::SKIPPED_NOT_IN_SCOPE;
                }

                return [['event_id' => self::eventId($connection, $row, '')] + $this->customers->row($connection, $customer)];
            default:
                $order = $this->orders->row($connection, $id);

                return $order === null ? self::SKIPPED_ORDER_GONE : [['event_id' => self::eventId($connection, $row, '')] + $order];
        }
    }

    /**
     * Sends the items of catalog, customers or orders rows, in requests of the domain's size, and
     * records each row's outcome.
     *
     * @param list<array{row: array<string, string|null>, items: list<array<string, mixed>>}> $entries
     */
    private function send(Connection $connection, string $domain, array $entries): void
    {
        $wrapper = Client::INGEST[$domain][2];
        // Every item with the entry it belongs to, in the order sent.
        $flat = [];
        foreach ($entries as $index => $entry) {
            foreach ($entry['items'] as $item) {
                $flat[] = [$index, $item];
            }
        }

        $refusedItems = [];
        $answers = [];
        $failure = null;
        $failedFrom = count($flat);
        foreach (array_chunk($flat, self::ITEMS_PER_REQUEST[$domain]) as $number => $chunk) {
            try {
                $answer = $this->client->ingest($connection, $domain, array_column($chunk, 1));
            } catch (EngineException $error) {
                $failure = $error;
                $failedFrom = $number * self::ITEMS_PER_REQUEST[$domain];
                break;
            }
            foreach (is_array($answer['errors'] ?? null) ? $answer['errors'] : [] as $refusal) {
                if (is_array($refusal) && isset($chunk[(int) ($refusal['index'] ?? -1)])) {
                    $refusedItems[$chunk[(int) $refusal['index']][0]][] = $refusal;
                }
            }
            foreach ($chunk as [$index]) {
                $answers[$index] = $answer;
            }
        }

        $position = 0;
        $unsent = [];
        $sent = [];
        $confirmed = [];
        foreach ($entries as $index => $entry) {
            $last = $position + count($entry['items']) - 1;
            $position = $last + 1;
            $request = [$wrapper => $entry['items']];
            if ($last >= $failedFrom) {
                $unsent[] = ['row' => $entry['row'], 'request' => $request];
                continue;
            }
            $body = ['errors' => $refusedItems[$index] ?? []] + $answers[$index];
            $this->queue->recordExchange($entry['row'], $request, ['http_status' => 200, 'body' => self::withoutText($body)]);
            if (isset($refusedItems[$index])) {
                $this->queue->markFailed($entry['row'], Failure::permanent(self::FAILED_ITEM_REFUSED));
            } else {
                $sent[] = $entry['row'];
                $confirmed[] = (string) ($entry['items'][0][self::SHOPPER[$domain] ?? ''] ?? '');
            }
        }
        $this->queue->markSent($sent);
        if (isset(self::SHOPPER[$domain])) {
            $this->profiling->resendOptOuts($confirmed, $connection, ConnectionShops::of($connection)[0] ?? 0);
        }
        if ($failure !== null) {
            $this->failed($connection, $domain, $unsent, $failure);
        }
    }

    /**
     * Sends the product removals of a batch as one request (§3b) and records each row's outcome.
     *
     * @param list<array{row: array<string, string|null>, items: list<string>}> $entries each with its one product id
     */
    private function remove(Connection $connection, array $entries): void
    {
        $ids = array_values(array_unique(array_map(static fn (array $entry): string => $entry['items'][0], $entries)));
        try {
            $answer = $this->client->catalogRemove($connection, $ids);
        } catch (EngineException $error) {
            $this->failed($connection, IngestQueue::DOMAIN_CATALOG_REMOVE, array_map(
                static fn (array $entry): array => ['row' => $entry['row'], 'request' => ['product_ids' => $entry['items']]],
                $entries
            ), $error);

            return;
        }

        $refused = [];
        foreach (is_array($answer['errors'] ?? null) ? $answer['errors'] : [] as $refusal) {
            if (is_array($refusal) && is_scalar($refusal['product_id'] ?? null)) {
                $refused[(string) $refusal['product_id']] = $refusal;
            }
        }
        $notFound = array_map('strval', is_array($answer['not_found'] ?? null) ? $answer['not_found'] : []);
        $sent = [];
        foreach ($entries as $entry) {
            $id = $entry['items'][0];
            $body = isset($refused[$id])
                ? ['errors' => [$refused[$id]]]
                : ['outcome' => in_array($id, $notFound, true) ? 'not_found' : 'removed'];
            $this->queue->recordExchange($entry['row'], ['product_ids' => [$id]], ['http_status' => 200, 'body' => self::withoutText($body)]);
            if (isset($refused[$id])) {
                $this->queue->markFailed($entry['row'], Failure::permanent(self::FAILED_ITEM_REFUSED));
            } else {
                $sent[] = $entry['row'];
            }
        }
        $this->queue->markSent($sent);
    }

    /**
     * Records a request that failed as a whole on the rows it carried, and the rows that were not
     * sent after it.
     *
     * @param list<array{row: array<string, string|null>, request: array<string, mixed>}> $unsent
     */
    private function failed(Connection $connection, string $domain, array $unsent, EngineException $failure): void
    {
        $rows = array_column($unsent, 'row');
        if (!$failure instanceof EngineRequestException) {
            // It may pass later: the rows take the ladder, and the connection waits for the next run.
            $this->stopped[$connection->id()] = true;
            foreach ($unsent as $entry) {
                $this->record($entry, $failure);
                $this->queue->markFailed($entry['row'], Failure::temporary($failure->errorCode()));
            }

            return;
        }

        $this->halted[$connection->id() . ':' . $domain] = true;
        // A key the engine no longer takes is the connection's state, not these rows' fault.
        if ($this->health->refuses($connection, $failure)) {
            $this->stopped[$connection->id()] = true;
            $this->queue->release($rows);

            return;
        }
        foreach ($unsent as $entry) {
            $this->record($entry, $failure);
            $this->queue->markFailed($entry['row'], Failure::permanent($failure->permanentReason()));
        }
    }

    /**
     * @param array{row: array<string, string|null>, request: array<string, mixed>} $entry
     */
    private function record(array $entry, EngineException $failure): void
    {
        $this->queue->recordExchange($entry['row'], $entry['request'], $failure->response());
    }

    /**
     * An answer without the engine's `message` texts, at any depth: a message can name a shopper.
     *
     * @param array<int|string, mixed> $answer
     *
     * @return array<int|string, mixed>
     */
    public static function withoutText(array $answer): array
    {
        unset($answer['message']);

        return array_map(static fn ($value) => is_array($value) ? self::withoutText($value) : $value, $answer);
    }

    /**
     * The item's `event_id` (contract "Idempotency"): the same for every attempt of the same row,
     * so the engine counts a repeat as one, and its own for every row and connection.
     *
     * @param array<string, string|null> $row
     */
    private static function eventId(Connection $connection, array $row, string $item): string
    {
        $hash = md5($connection->storedKey() . '|' . $row['id'] . '|' . $row['created_at'] . '|' . $item);

        // The form of a version 4 UUID.
        return sprintf(
            '%s-%s-4%s-%s%s-%s',
            substr($hash, 0, 8),
            substr($hash, 8, 4),
            substr($hash, 13, 3),
            '89ab'[hexdec($hash[16]) & 3],
            substr($hash, 17, 3),
            substr($hash, 20, 12)
        );
    }
}
