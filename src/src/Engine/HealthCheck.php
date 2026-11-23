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

use PrestaShop\Module\SmailyForPrestaShop\Engine\Exception\EngineRequestException;
use PrestaShop\Module\SmailyForPrestaShop\Engine\Exception\EngineTransportException;
use PrestaShop\Module\SmailyForPrestaShop\Scheduler\Deadline;

/**
 * Asks the engine whether each connection is alive (contract §2) and records the answer with the
 * connection.
 *
 * - `connected`: the engine answered the ping. A remembered refusal ends, and the `config.history`
 *   and `config.connection.prefix` that the ping names replace the stored ones: the engine's
 *   values win, and a connection that held no prefix gets it here.
 * - `refused`: the engine answered and does not take this connection: a 401 (the key is unknown
 *   or revoked), a 403 `tenant_inactive`, a 409 `plugin_update_required` or another 3xx or 4xx; or
 *   the stored key does not decrypt, and nothing was asked. Waiting changes nothing. The time of
 *   the first refusal is kept (`refused_at`) until the engine answers a ping again.
 * - `unreachable`: no answer, a 429, a 5xx or an answer that is not JSON. It may pass; a refusal
 *   remembered before it stays.
 *
 * What is recorded is the status, an error code, the HTTP status and the time: never the engine's
 * message. The request carries the connection's key and nothing of the store.
 */
final class HealthCheck
{
    public const CONNECTED = 'connected';

    public const REFUSED = 'refused';

    public const UNREACHABLE = 'unreachable';

    private Client $client;

    private Connections $connections;

    /** @var callable(): int */
    private $now;

    /**
     * @param callable(): int $now the current Unix time
     */
    public function __construct(Client $client, Connections $connections, callable $now)
    {
        $this->client = $client;
        $this->connections = $connections;
        $this->now = $now;
    }

    /**
     * Checks every stored connection, the one checked longest ago first, until the deadline passes;
     * the rest come first in the next run.
     */
    public function run(Deadline $deadline): void
    {
        $connections = $this->connections->all();
        usort(
            $connections,
            static fn (Connection $a, Connection $b): int => strcmp($a->health()['checked_at'], $b->health()['checked_at'])
        );
        foreach ($connections as $connection) {
            if ($deadline->passed()) {
                return;
            }
            $this->check($connection);
        }
    }

    /**
     * Checks one connection and records the outcome with it.
     *
     * @return string CONNECTED, REFUSED or UNREACHABLE
     */
    public function check(Connection $connection): string
    {
        $now = gmdate('Y-m-d\TH:i:s\Z', ($this->now)());
        $settings = $connection->settings();
        try {
            $answer = $this->client->ping($connection);
            $status = self::CONNECTED;
            $settings = self::withAnswer($settings, $answer);
            $settings['refused_at'] = '';
            unset($settings['refused_error']);
            $settings['health'] = self::health($status, '', 200, $now);
        } catch (EngineRequestException $refusal) {
            $status = self::REFUSED;
            if ($connection->refusedAt() === '') {
                $settings['refused_at'] = $now;
            }
            // Why the engine refuses, kept with the refusal: a later check without an answer
            // replaces the health record, and the Intelligence tab still has to say why.
            $settings['refused_error'] = $refusal->errorCode();
            $settings['health'] = self::health($status, $refusal->errorCode(), $refusal->httpStatus(), $now);
        } catch (EngineTransportException $failure) {
            $status = self::UNREACHABLE;
            $settings['health'] = self::health($status, $failure->errorCode(), $failure->httpStatus(), $now);
        }
        $this->connections->saveSettings($connection, $settings);

        return $status;
    }

    /**
     * The health record that a connection's settings hold.
     *
     * @return array{status: string, error: string, http_status: int, checked_at: string}
     */
    public static function health(string $status, string $error, int $httpStatus, string $checkedAt): array
    {
        return ['status' => $status, 'error' => $error, 'http_status' => $httpStatus, 'checked_at' => $checkedAt];
    }

    /**
     * The settings with what a ping's answer names: the engine version, the history window and the
     * connection prefix, each only when the answer holds it in the form the contract gives it. A
     * prefix that is neither '' nor `c<N>-` is ignored, as in the Magento and WooCommerce
     * connectors (Connection::isPrefix()); one that is taken is kept exactly as it came.
     *
     * @param array<string, mixed> $settings
     * @param array<string, mixed> $answer
     *
     * @return array<string, mixed>
     */
    public static function withAnswer(array $settings, array $answer): array
    {
        if (is_string($answer['engine_version'] ?? null)) {
            $settings['engine_version'] = $answer['engine_version'];
        }
        $config = is_array($answer['config'] ?? null) ? $answer['config'] : [];
        $stored = is_array($settings['config'] ?? null) ? $settings['config'] : [];
        if (is_array($config['history'] ?? null)) {
            $stored['history'] = $config['history'];
        }
        if (is_array($config['connection'] ?? null) && Connection::isPrefix($config['connection']['prefix'] ?? null)) {
            $stored['connection'] = is_array($stored['connection'] ?? null) ? $stored['connection'] : [];
            $stored['connection']['prefix'] = $config['connection']['prefix'];
        }
        $settings['config'] = $stored;

        return $settings;
    }
}
