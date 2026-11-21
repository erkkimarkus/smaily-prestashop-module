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

namespace PrestaShop\Module\SmailyForPrestaShop\Privacy;

if (!defined('_PS_VERSION_')) {
    exit;
}

use PrestaShop\Module\SmailyForPrestaShop\Engine\Client;
use PrestaShop\Module\SmailyForPrestaShop\Engine\Connection;
use PrestaShop\Module\SmailyForPrestaShop\Engine\Connections;
use PrestaShop\Module\SmailyForPrestaShop\Engine\Exception\EngineRequestException;
use PrestaShop\Module\SmailyForPrestaShop\Engine\Exception\EngineTransportException;
use PrestaShop\Module\SmailyForPrestaShop\Queue\EventQueue;
use PrestaShop\Module\SmailyForPrestaShop\Queue\Failure;
use PrestaShop\Module\SmailyForPrestaShop\Queue\HandlerInterface;
use PrestaShop\Module\SmailyForPrestaShop\Queue\RetryPolicy;
use PrestaShop\Module\SmailyForPrestaShop\Queue\Skipped;
use PrestaShop\Module\SmailyForPrestaShop\Smaily\Crypto;

/**
 * Asks Campaign Intelligence to delete a shopper (contract §9), from the `engine.customer_delete`
 * row that an erasure queued for one connection (PrivacyRequest). The call is on the queue so that
 * it survives an engine that does not answer: the row takes the retry ladder.
 *
 * The erasure left the address nowhere else, and the call needs it. The row therefore holds it
 * encrypted (Smaily\Crypto), under a key whose value the Log never shows (Log\PayloadRedactor),
 * and its entity is the erased one. The address leaves the row as soon as the row is closed: sent,
 * skipped, or parked as failed. A parked row cannot be sent again; a new erasure of the address
 * queues the call anew.
 *
 * - The engine does not know the shopper, or has deleted the shopper already (HTTP 404): done.
 * - Any other refusal parks the row at once; no answer, a 429 and a 5xx take the retry ladder
 *   (EngineFailure).
 * - The row's connection is gone: skipped. Its engine store is no longer the store's.
 *
 * The payload is {"connection": <id>, "address_secret": <the address, encrypted>}.
 */
final class CustomerDeleteHandler implements HandlerInterface
{
    public const EVENT_TYPE = 'engine.customer_delete';

    public const FAILED_MALFORMED_PAYLOAD = 'malformed_payload';

    private const ADDRESS = 'address_secret';

    private Client $client;

    private Connections $connections;

    private Crypto $crypto;

    private EventQueue $queue;

    public function __construct(Client $client, Connections $connections, Crypto $crypto, EventQueue $queue)
    {
        $this->client = $client;
        $this->connections = $connections;
        $this->crypto = $crypto;
        $this->queue = $queue;
    }

    /**
     * The payload of a row that deletes $email at $connection.
     *
     * @return array{connection: int, address_secret: string}
     */
    public static function payload(Crypto $crypto, Connection $connection, string $email): array
    {
        return ['connection' => $connection->id(), self::ADDRESS => $crypto->encrypt(strtolower(trim($email)))];
    }

    public function handle(array $events): array
    {
        $connections = [];
        foreach ($this->connections->all() as $connection) {
            $connections[$connection->id()] = $connection;
        }

        $outcomes = [];
        foreach ($events as $event) {
            $payload = json_decode((string) $event['payload'], true);
            $outcome = $this->delete($payload, $connections);
            if (!$outcome instanceof Failure || RetryPolicy::parks((int) $event['attempts'] + 1, $outcome)) {
                // The row is closed: it no longer needs the address.
                $this->queue->updatePayload($event, ['connection' => is_array($payload) ? (int) ($payload['connection'] ?? 0) : 0]);
            }
            $outcomes[(int) $event['id']] = $outcome;
        }

        return $outcomes;
    }

    /**
     * @param mixed $payload
     * @param array<int, Connection> $connections
     *
     * @return true|Skipped|Failure
     */
    private function delete($payload, array $connections)
    {
        $email = is_array($payload) && is_string($payload[self::ADDRESS] ?? null) ? $this->crypto->decrypt($payload[self::ADDRESS]) : null;
        if ($email === null || $email === '') {
            return Failure::permanent(self::FAILED_MALFORMED_PAYLOAD);
        }
        $connection = $connections[(int) ($payload['connection'] ?? 0)] ?? null;
        if ($connection === null) {
            return new Skipped(EngineFailure::SKIPPED_NO_CONNECTION);
        }

        try {
            $this->client->customerDelete($connection, $email);
        } catch (EngineRequestException $refusal) {
            return $refusal->httpStatus() === 404 ? true : EngineFailure::of($refusal);
        } catch (EngineTransportException $failure) {
            return EngineFailure::of($failure);
        }

        return true;
    }
}
