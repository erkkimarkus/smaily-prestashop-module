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

use PrestaShop\Module\SmailyForPrestaShop\Engine\Capture\IdentityCapture;
use PrestaShop\Module\SmailyForPrestaShop\Engine\Client;
use PrestaShop\Module\SmailyForPrestaShop\Engine\Connection;
use PrestaShop\Module\SmailyForPrestaShop\Engine\Connections;
use PrestaShop\Module\SmailyForPrestaShop\Engine\Exception\EngineException;
use PrestaShop\Module\SmailyForPrestaShop\Engine\Exception\EngineRequestException;
use PrestaShop\Module\SmailyForPrestaShop\Engine\HealthCheck;
use PrestaShop\Module\SmailyForPrestaShop\Engine\Payload\CustomerPayloadBuilder;
use PrestaShop\Module\SmailyForPrestaShop\Privacy\ProfilingConsent;
use PrestaShop\Module\SmailyForPrestaShop\Queue\EventQueue;
use PrestaShop\Module\SmailyForPrestaShop\Queue\ExchangeSource;
use PrestaShop\Module\SmailyForPrestaShop\Queue\Failure;
use PrestaShop\Module\SmailyForPrestaShop\Queue\HandlerInterface;
use PrestaShop\Module\SmailyForPrestaShop\Queue\Pending;
use PrestaShop\Module\SmailyForPrestaShop\Queue\Skipped;
use PrestaShop\Module\SmailyForPrestaShop\Storefront\Shape;

/**
 * Sends the queued identity merges (contract §7), each through the connection of its row's shop,
 * as the Magento connector's handler does.
 *
 * The customer goes first where the contract asks for it, in this run and not through the ingest
 * queue, whose job may run after this one:
 *  - a sign-in with marketing consent (`send_customer`, group (c) of the customer scope: the
 *    merchant's confirmation of the cookie banner, CustomerScope::consents()): the customer is
 *    sent (§4) before the merge, and also when the row has no visitor token and so no merge. The
 *    confirmation is read again here: where the merchant took it back since, this group sends
 *    nobody.
 *  - a merge that the engine answers with `404 customer_not_found`: the customer is sent when
 *    the store sends that customer anyway (groups (a) and (b), CustomerScope::includes()), and
 *    once §4 took the customer the merge is tried once more (§7 "Retry after 404"). It is never
 *    tried again before that: the row remembers the 404.
 * Each customer sent this way is one row of the Log (ingest queue, domain `customers`).
 *
 * A row is closed without a request when its shop has no connection, when the customer is gone,
 * when the shopper opted out of personalization (Privacy\ProfilingConsent, as the Magento
 * connector's handler), or when it has nothing to send (no visitor token, and the confirmation
 * taken back). It waits, as it is, while the engine refuses the connection. A reason is a code,
 * never the engine's text.
 */
final class IdentityMergeHandler implements HandlerInterface, ExchangeSource
{
    public const SKIPPED_NO_CONNECTION = IngestQueue::SKIPPED_NO_CONNECTION;

    public const SKIPPED_CUSTOMER_GONE = IngestFlusher::SKIPPED_CUSTOMER_GONE;

    public const SKIPPED_NOTHING_TO_SEND = 'engine_nothing_to_merge';

    /** The shopper opted out of personalization: the browsing is not linked to the address. */
    public const SKIPPED_OPTED_OUT = 'engine_opted_out';

    /** The engine does not have the customer, and the store does not send that customer. */
    public const FAILED_NOT_SENT = 'engine_customer_not_sent';

    /** The engine refused the customer's row. */
    public const FAILED_CUSTOMER_REFUSED = 'engine_customer_refused';

    /** The engine still does not find the customer after it took the customer's row. */
    public const FAILED_NOT_FOUND = 'engine_customer_not_found';

    public const FAILED_MALFORMED_PAYLOAD = 'malformed_payload';

    /** Payload key: the merge was answered `404 customer_not_found` before. */
    private const NOT_FOUND = 'customer_not_found';

    private Connections $connections;

    private Client $client;

    private HealthCheck $health;

    private CustomerScope $scope;

    private CustomerPayloadBuilder $customers;

    private IngestQueue $ingestQueue;

    private EventQueue $eventQueue;

    private ProfilingConsent $profiling;

    /** @var array<int, array{request: array<int|string, mixed>, response: array{http_status: int, body: mixed}|null}> */
    private array $exchanges = [];

    /** @var array{request: array<string, mixed>, response: array{http_status: int, body: mixed}} what the last customer send carried */
    private array $customerExchange = ['request' => [], 'response' => ['http_status' => 0, 'body' => null]];

    public function __construct(Connections $connections, Client $client, HealthCheck $health, CustomerScope $scope, CustomerPayloadBuilder $customers, IngestQueue $ingestQueue, EventQueue $eventQueue, ProfilingConsent $profiling)
    {
        $this->profiling = $profiling;
        $this->connections = $connections;
        $this->client = $client;
        $this->health = $health;
        $this->scope = $scope;
        $this->customers = $customers;
        $this->ingestQueue = $ingestQueue;
        $this->eventQueue = $eventQueue;
    }

    public function handle(array $events): array
    {
        $this->exchanges = [];
        $outcomes = [];
        foreach ($events as $event) {
            $outcomes[(int) $event['id']] = $this->deliver($event);
        }

        return $outcomes;
    }

    public function exchanges(): array
    {
        return $this->exchanges;
    }

    /**
     * @param array<string, mixed> $event
     *
     * @return mixed true, a Skipped, a Pending or a Failure
     */
    private function deliver(array $event)
    {
        $payload = json_decode((string) $event['payload'], true);
        if (!is_array($payload) || (int) ($payload['id_customer'] ?? 0) <= 0) {
            return Failure::permanent(self::FAILED_MALFORMED_PAYLOAD);
        }
        $connection = $this->connections->forShop((int) $event['id_shop']);
        if ($connection === null) {
            return new Skipped(self::SKIPPED_NO_CONNECTION);
        }
        if (!$connection->isTaken()) {
            return new Pending();
        }
        $customer = $this->scope->customer((int) $payload['id_customer']);
        if ($customer === null) {
            return new Skipped(self::SKIPPED_CUSTOMER_GONE);
        }
        if (!$this->profiling->isAllowed((string) $customer['email'])) {
            return new Skipped(self::SKIPPED_OPTED_OUT);
        }

        $confirmed = $this->scope->consents($connection, $customer);
        $consents = !empty($payload['send_customer']) && $confirmed;
        $token = is_string($payload['visitor_token'] ?? null) && Shape::isVisitorToken($payload['visitor_token'])
            ? $payload['visitor_token']
            : null;
        if ($token !== null && Shape::isStoreVisitorToken($token) && !$confirmed) {
            // A token that the store created travels only with marketing consent (contract §7).
            $token = null;
        }
        $metNotFound = !empty($payload[self::NOT_FOUND]);

        try {
            $sent = false;
            $refused = false;
            if ($consents || ($token !== null && $metNotFound && $this->scope->includes($connection, $customer))) {
                $refused = !$this->sendCustomer($connection, $customer);
                $sent = true;
            }
            if ($token === null) {
                if (!$sent) {
                    return new Skipped(self::SKIPPED_NOTHING_TO_SEND);
                }
                // No merge follows: the row keeps the customer that was sent for it.
                $this->exchanges[(int) $event['id']] = $this->customerExchange;

                return $refused ? Failure::permanent(self::FAILED_CUSTOMER_REFUSED) : true;
            }

            // After a 404 this merge is the one retry; a first merge has one left.
            $retryLeft = !$metNotFound;
            while (true) {
                if ($this->merge($connection, $event, $customer, $token, $payload)) {
                    return true;
                }
                if (!$metNotFound) {
                    $metNotFound = true;
                    $this->eventQueue->updatePayload($event, [self::NOT_FOUND => true] + $payload);
                }
                if (!$sent && $this->scope->includes($connection, $customer)) {
                    $refused = !$this->sendCustomer($connection, $customer);
                    $sent = true;
                }
                if (!$sent) {
                    return Failure::permanent(self::FAILED_NOT_SENT);
                }
                if ($refused) {
                    return Failure::permanent(self::FAILED_CUSTOMER_REFUSED);
                }
                if (!$retryLeft) {
                    return Failure::permanent(self::FAILED_NOT_FOUND);
                }
                $retryLeft = false;
            }
        } catch (EngineException $failure) {
            return $this->failed($connection, $failure);
        }
    }

    /**
     * Sends the merge and keeps the exchange for the row.
     *
     * @param array<string, mixed> $event
     * @param array{id_customer: int, email: string} $customer
     * @param array<string, mixed> $payload
     *
     * @return bool false when the engine does not have the customer (`404 customer_not_found`)
     *
     * @throws EngineException any other failure
     */
    private function merge(Connection $connection, array $event, array $customer, string $token, array $payload): bool
    {
        $body = [
            'smaily_visitor_token' => $token,
            'customer_email' => $customer['email'],
            'customer_external_id' => (string) $customer['id_customer'],
        ];
        if (is_string($payload['merge_ts'] ?? null)) {
            $body['merge_ts'] = $payload['merge_ts'];
        }
        if (in_array($payload['merge_reason'] ?? null, [IdentityCapture::REASON_SIGNED_IN, IdentityCapture::REASON_CHECKOUT], true)) {
            $body['merge_reason'] = $payload['merge_reason'];
        }
        try {
            $answer = $this->client->identityMerge($connection, $body);
        } catch (EngineException $failure) {
            $this->exchanges[(int) $event['id']] = ['request' => $body, 'response' => $failure->response()];
            if ($failure instanceof EngineRequestException && $failure->httpStatus() === 404 && $failure->errorCode() === self::NOT_FOUND) {
                return false;
            }
            throw $failure;
        }
        $this->exchanges[(int) $event['id']] = ['request' => $body, 'response' => ['http_status' => 200, 'body' => IngestFlusher::withoutText($answer)]];

        return true;
    }

    /**
     * Sends the customer (§4) and writes the Log's row of it.
     *
     * @param array{id_customer: int, id_shop: int, id_lang: int, email: string, firstname: string, lastname: string, date_add: string} $customer
     *
     * @return bool whether the engine took the customer; false when it refused the row
     *
     * @throws EngineException when the request failed as a whole: nothing is recorded, and the
     *                         merge row carries the failure
     */
    private function sendCustomer(Connection $connection, array $customer): bool
    {
        $row = ['event_id' => self::uuid()] + $this->customers->row($connection, $customer);
        $answer = $this->client->ingest($connection, IngestQueue::DOMAIN_CUSTOMERS, [$row]);
        $errors = array_values(array_filter(is_array($answer['errors'] ?? null) ? $answer['errors'] : [], 'is_array'));
        $this->customerExchange = [
            'request' => ['customers' => [$row]],
            'response' => ['http_status' => 200, 'body' => IngestFlusher::withoutText($answer)],
        ];
        $this->ingestQueue->record(
            $connection->id(),
            IngestQueue::DOMAIN_CUSTOMERS,
            (string) $customer['id_customer'],
            $this->customerExchange['request'],
            $this->customerExchange['response'],
            $errors === [] ? null : IngestFlusher::FAILED_ITEM_REFUSED
        );

        return $errors === [];
    }

    /**
     * The outcome of a request that failed as a whole: the row waits when the engine now refuses
     * the connection itself, takes the retry ladder when the request may pass later, and is
     * parked otherwise.
     *
     * @return Pending|Failure
     */
    private function failed(Connection $connection, EngineException $failure)
    {
        if (!$failure instanceof EngineRequestException) {
            return Failure::temporary($failure->errorCode());
        }
        if ($this->health->refuses($connection, $failure)) {
            return new Pending();
        }

        return Failure::permanent($failure->permanentReason());
    }

    /**
     * A random version 4 UUID, the `event_id` of a row that no queue row numbers.
     */
    private static function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0F) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3F) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
