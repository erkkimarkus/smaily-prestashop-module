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
use PrestaShop\Module\SmailyForPrestaShop\Queue\Deferred;
use PrestaShop\Module\SmailyForPrestaShop\Queue\Failure;
use PrestaShop\Module\SmailyForPrestaShop\Queue\HandlerInterface;
use PrestaShop\Module\SmailyForPrestaShop\Queue\Skipped;

/**
 * Sends a shopper's queued choice about personalization to Campaign Intelligence (contract §10),
 * as the Magento connector's Model\Queue\Handler\ProfilingConsentHandler.
 *
 * A row is sent only while it still says what the store's record says (ProfilingRecord): a retry
 * of a choice that the shopper has replaced since closes as skipped, without a call, so an older
 * answer never undoes a newer one at the engine. The newer choice has a row of its own.
 *
 * - The engine does not know the shopper (HTTP 404): nothing is there to exclude, and the row is
 *   sent.
 * - The engine store has taken its privacy requests of the minute, or answers 429: the row waits,
 *   with no attempt spent (GdprPace).
 * - Any other refusal (a 3xx or 4xx) fails the row for good; no answer and a 5xx take the retry
 *   ladder (EngineFailure).
 * - The row's connection is gone: skipped.
 *
 * The payload is {"connection": <id>, "email": <address>, "opt_out": bool, "opted_out_at": <UTC>}.
 */
final class ProfilingConsentHandler implements HandlerInterface
{
    public const SKIPPED_REPLACED = 'preference_replaced';

    public const FAILED_MALFORMED_PAYLOAD = 'malformed_payload';

    private Client $client;

    private Connections $connections;

    private ProfilingRecord $record;

    private GdprPace $pace;

    public function __construct(Client $client, Connections $connections, ProfilingRecord $record, GdprPace $pace)
    {
        $this->client = $client;
        $this->connections = $connections;
        $this->record = $record;
        $this->pace = $pace;
    }

    public function handle(array $events): array
    {
        $connections = [];
        foreach ($this->connections->all() as $connection) {
            $connections[$connection->id()] = $connection;
        }

        $outcomes = [];
        foreach ($events as $event) {
            $outcomes[(int) $event['id']] = $this->send(json_decode((string) $event['payload'], true), $connections);
        }

        return $outcomes;
    }

    /**
     * @param mixed $payload
     * @param array<int, Connection> $connections
     *
     * @return true|Skipped|Deferred|Failure
     */
    private function send($payload, array $connections)
    {
        if (!is_array($payload) || !is_string($payload['email'] ?? null) || trim($payload['email']) === '' || !is_bool($payload['opt_out'] ?? null)) {
            return Failure::permanent(self::FAILED_MALFORMED_PAYLOAD);
        }
        $connection = $connections[(int) ($payload['connection'] ?? 0)] ?? null;
        if ($connection === null) {
            return new Skipped(EngineFailure::SKIPPED_NO_CONNECTION);
        }
        if ($payload['opt_out'] !== $this->record->optedOut($payload['email'])) {
            return new Skipped(self::SKIPPED_REPLACED);
        }

        try {
            $deferred = $this->pace->send($connection, fn (): array => $this->client->customerOptOut(
                $connection,
                $payload['email'],
                $payload['opt_out'],
                (string) ($payload['opted_out_at'] ?? '')
            ));
        } catch (EngineRequestException $refusal) {
            return $refusal->httpStatus() === 404 ? true : EngineFailure::of($refusal);
        } catch (EngineTransportException $failure) {
            return EngineFailure::of($failure);
        }

        return $deferred ?? true;
    }
}
