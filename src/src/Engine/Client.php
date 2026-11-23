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
use PrestaShop\Module\SmailyForPrestaShop\Http\NetworkException;
use PrestaShop\Module\SmailyForPrestaShop\Http\Transport;
use PrestaShop\Module\SmailyForPrestaShop\Report\ConnectionHealth;

/**
 * The Campaign Intelligence engine client (docs/RECENGINE_API_CONTRACT.md): the setup exchange
 * (§1), the ping (§2) and the disconnect (§16).
 *
 * - It calls https://intelligence.smaily.com and no other host: a setup link, a stored endpoint
 *   or an answer that names another address is refused before any request, so neither a setup
 *   token nor an API key can reach another server. It follows no redirect.
 * - An authenticated call carries the connection's API key as a Bearer token. The key never
 *   reaches a page or a log line.
 * - Every failure is typed where it is thrown: an HTTP 3xx or 4xx other than 429 is an
 *   EngineRequestException; no answer, a 429, a 5xx and an answer that is not JSON are an
 *   EngineTransportException. Each carries the HTTP status and an error code, never the engine's
 *   message.
 * - Each call is one attempt with its own time limit. The client does not retry and writes no log
 *   line: the caller decides both.
 * - Every answer to a connection's call is told to the installation report's health of its engine
 *   store (Report\ConnectionHealth): '' or the error code, and whether an ingest request was
 *   accepted. The setup exchange names no engine store and tells nothing.
 */
class Client
{
    public const HOST = 'intelligence.smaily.com';

    public const BASE_URL = 'https://' . self::HOST;

    public const PATH_SETUP_EXCHANGE = '/api/setup/exchange';

    /** The endpoints map key and the fallback path of the ping (§2). */
    public const ENDPOINT_PING = 'ingest_ping';
    public const PATH_PING = '/api/v1/ingest/ping';

    /** The endpoints map key and the fallback path of the disconnect (§16). */
    public const ENDPOINT_STORE_UNINSTALL = 'store_uninstall';
    public const PATH_STORE_UNINSTALL = '/api/v1/store/uninstall';

    /** Seconds the setup exchange may take: the merchant waits for it. */
    public const EXCHANGE_TIMEOUT_SECONDS = 10;

    /** Seconds a ping may take, so that a slow engine does not hold the tick. */
    public const PING_TIMEOUT_SECONDS = 5;

    /** Seconds the disconnect may take: an uninstall waits for it. */
    public const DISCONNECT_TIMEOUT_SECONDS = 3;

    /** The address is not an https address on HOST. */
    public const HOST_NOT_ALLOWED = 'host_not_allowed';

    /** The connection's stored API key does not decrypt. */
    public const KEY_UNREADABLE = 'key_unreadable';

    /** No answer arrived: DNS, connection, TLS or a timeout. */
    public const NETWORK_ERROR = 'network_error';

    /** The answer is not JSON. */
    public const MALFORMED_ANSWER = 'malformed_answer';

    /**
     * An https address on HOST: the default port, a plain path (the endpoints map's `{email}` and
     * `{key}` placeholders included), and no credentials, query or fragment.
     */
    private const ENGINE_URL = '~^https://intelligence\.smaily\.com(?::443)?(?:/[A-Za-z0-9._\~%{}/-]*)?$~D';

    private Transport $transport;

    private string $userAgent;

    private ?ConnectionHealth $health;

    /**
     * @param string $moduleVersion the installed module's version, which the User-Agent names
     * @param ConnectionHealth|null $health told how each connection's call went; null tells nobody
     */
    public function __construct(Transport $transport, string $moduleVersion, ?ConnectionHealth $health = null)
    {
        $this->transport = $transport;
        $this->userAgent = 'SmailyConnect-PrestaShopModule/' . $moduleVersion;
        $this->health = $health;
    }

    /**
     * Whether $url is an https address on the engine's host.
     */
    public static function isEngineUrl(string $url): bool
    {
        return preg_match(self::ENGINE_URL, $url) === 1 && strpos($url, '..') === false;
    }

    /**
     * Exchanges a one-time setup token for a connection (§1). Unauthenticated.
     *
     * @param string $baseUrl the address the setup link names
     * @param array<string, mixed> $body the request body: `setup_token`, `plugin_info`,
     *                                   `capabilities`, and where they apply `store_config` and
     *                                   `connection`
     *
     * @return array<string, mixed> the engine's answer, unchecked
     */
    public function setupExchange(string $baseUrl, array $body): array
    {
        return $this->request('POST', rtrim($baseUrl, '/') . self::PATH_SETUP_EXCHANGE, self::EXCHANGE_TIMEOUT_SECONDS, null, $body);
    }

    /**
     * Asks the engine whether the connection's key is alive (§2).
     *
     * @return array<string, mixed> the engine's answer, unchecked
     */
    public function ping(Connection $connection): array
    {
        return $this->request(
            'GET',
            $connection->endpoint(self::ENDPOINT_PING, self::PATH_PING),
            self::PING_TIMEOUT_SECONDS,
            $connection
        );
    }

    /**
     * Tells the engine that the connection ends (§16): it revokes the calling key. No body.
     *
     * @return array<string, mixed> the engine's answer, unchecked
     */
    public function storeUninstall(Connection $connection): array
    {
        return $this->request(
            'POST',
            $connection->endpoint(self::ENDPOINT_STORE_UNINSTALL, self::PATH_STORE_UNINSTALL),
            self::DISCONNECT_TIMEOUT_SECONDS,
            $connection
        );
    }

    /**
     * @param Connection|null $connection the connection whose key the call carries; null for the
     *                                    unauthenticated exchange
     * @param array<string, mixed>|null $body sent as JSON; null sends no body
     *
     * @return array<string, mixed>
     */
    private function request(string $method, string $url, int $timeout, ?Connection $connection, ?array $body = null): array
    {
        if (!self::isEngineUrl($url)) {
            throw new EngineRequestException(self::HOST_NOT_ALLOWED);
        }
        $apiKey = $connection !== null ? $connection->apiKey() : null;
        if ($apiKey === '') {
            $this->tell($connection, self::KEY_UNREADABLE);
            throw new EngineRequestException(self::KEY_UNREADABLE);
        }

        $options = [
            'headers' => ['User-Agent' => $this->userAgent, 'Accept' => 'application/json'],
            'timeout' => $timeout,
            'max_duration' => $timeout,
            'max_redirects' => 0,
        ];
        if ($apiKey !== null) {
            $options['headers']['Authorization'] = 'Bearer ' . $apiKey;
        }
        if ($body !== null) {
            $options['json'] = $body;
        }

        try {
            $response = $this->transport->request($method, $url, $options);
        } catch (NetworkException $exception) {
            $this->tell($connection, self::NETWORK_ERROR);
            throw new EngineTransportException(self::NETWORK_ERROR);
        }

        $status = $response->status();
        $answer = json_decode($response->body(), true);
        if ($status >= 200 && $status < 300) {
            if (!is_array($answer)) {
                $this->tell($connection, self::MALFORMED_ANSWER);
                throw new EngineTransportException(self::MALFORMED_ANSWER, $status);
            }
            $this->tell($connection, '', $method === 'POST' && strpos((string) parse_url($url, PHP_URL_PATH), '/ingest/') !== false);

            return $answer;
        }

        $error = is_array($answer) ? ($answer['error'] ?? null) : null;
        $code = is_string($error) && preg_match(EngineRequestException::CODE_PATTERN, $error) === 1
            ? $error
            : 'http_' . $status;
        $this->tell($connection, $code);
        if ($status === 429 || $status >= 500) {
            throw new EngineTransportException($code, $status);
        }

        throw new EngineRequestException($code, $status, self::refusedFields($answer));
    }

    /**
     * The fields that a refusal's `errors[]` names (§13: `{index?, trigger_key?, field, message}`):
     * the position, the key and the field's path of each entry, where they are plain values. The
     * entry's `message` is the engine's text and is not read.
     *
     * @param mixed $answer the refusal's body
     *
     * @return list<array{index: int|null, key: string, field: string}>
     */
    private static function refusedFields($answer): array
    {
        $fields = [];
        foreach (is_array($answer) && is_array($answer['errors'] ?? null) ? $answer['errors'] : [] as $error) {
            $field = is_array($error) ? ($error['field'] ?? null) : null;
            if (!is_string($field) || preg_match('/^[A-Za-z0-9_.]{1,64}$/D', $field) !== 1) {
                continue;
            }
            $key = $error['trigger_key'] ?? null;
            $fields[] = [
                'index' => is_int($error['index'] ?? null) ? $error['index'] : null,
                'key' => is_string($key) && preg_match('/^[A-Za-z0-9_.-]{1,64}$/D', $key) === 1 ? $key : '',
                'field' => $field,
            ];
        }

        return $fields;
    }

    /**
     * Tells the installation report how a call of the connection's engine store went: '' or an
     * error code, and whether an ingest request was accepted. Only a connection names a store.
     */
    private function tell(?Connection $connection, string $errorCode, bool $wrote = false): void
    {
        if ($this->health !== null && $connection !== null) {
            $this->health->recordEngine($connection->tenantId(), $errorCode, $wrote);
        }
    }

    /** The endpoints map keys and the fallback paths of the privacy calls (§8, §9, §10). */
    public const ENDPOINT_CUSTOMER_EXPORT = 'customer_export';
    public const PATH_CUSTOMER_EXPORT = '/api/v1/customer/{email}/export';
    public const ENDPOINT_CUSTOMER_DELETE = 'customer_delete';
    public const PATH_CUSTOMER_DELETE = '/api/v1/customer/{email}';
    public const ENDPOINT_CUSTOMER_OPT_OUT = 'customer_opt_out';
    public const PATH_CUSTOMER_OPT_OUT = '/api/v1/customer/{email}/opt-out';

    /** Seconds a privacy call may take: an export waits for it, and so does a queue flush. */
    public const PRIVACY_TIMEOUT_SECONDS = 10;

    /**
     * Everything the engine holds of a shopper (§8). A shopper whom the engine does not know is
     * an EngineRequestException with the HTTP status 404.
     *
     * @return array<string, mixed> the engine's answer, unchecked
     */
    public function customerExport(Connection $connection, string $email): array
    {
        return $this->request(
            'GET',
            self::customerUrl($connection, self::ENDPOINT_CUSTOMER_EXPORT, self::PATH_CUSTOMER_EXPORT, $email),
            self::PRIVACY_TIMEOUT_SECONDS,
            $connection
        );
    }

    /**
     * Asks the engine to delete everything it holds of a shopper (§9). A shopper whom the engine
     * does not know, or has deleted already, is an EngineRequestException with the HTTP status
     * 404, which the caller takes as done.
     *
     * @return array<string, mixed> the engine's answer, unchecked
     */
    public function customerDelete(Connection $connection, string $email): array
    {
        return $this->request(
            'DELETE',
            self::customerUrl($connection, self::ENDPOINT_CUSTOMER_DELETE, self::PATH_CUSTOMER_DELETE, $email),
            self::PRIVACY_TIMEOUT_SECONDS,
            $connection,
            ['confirm' => true, 'reason' => 'user_request']
        );
    }

    /**
     * Tells the engine a shopper's choice about personalization (§10): an opt-out with its time,
     * or the opt-in that reverses it. A shopper whom the engine does not know is an
     * EngineRequestException with the HTTP status 404.
     *
     * @param string $optedOutAt the UTC time of an opt-out, `Y-m-d\TH:i:s\Z`; not sent with an opt-in
     *
     * @return array<string, mixed> the engine's answer, unchecked
     */
    public function customerOptOut(Connection $connection, string $email, bool $optOut, string $optedOutAt = ''): array
    {
        $body = ['opt_out' => $optOut, 'reason' => 'user_preference'];
        if ($optOut && $optedOutAt !== '') {
            $body['opted_out_at'] = $optedOutAt;
        }

        return $this->request(
            'POST',
            self::customerUrl($connection, self::ENDPOINT_CUSTOMER_OPT_OUT, self::PATH_CUSTOMER_OPT_OUT, $email),
            self::PRIVACY_TIMEOUT_SECONDS,
            $connection,
            $body
        );
    }

    /**
     * The URL of a privacy endpoint for an address: the address stands in the path, trimmed, in
     * lower case and URL-encoded, where the endpoint names `{email}`. The second of two dots in a
     * row is encoded too: no address reads as a step up the path.
     */
    private static function customerUrl(Connection $connection, string $key, string $fallbackPath, string $email): string
    {
        $address = str_replace('..', '.%2E', rawurlencode(strtolower(trim($email))));

        return str_replace('{email}', $address, $connection->endpoint($key, $fallbackPath));
    }

    // The storefront calls (Story 5.5): browse events (§6) and a shopper's recommendations (§15).

    /** The endpoints map key and the fallback path of the browse events (§6). */
    public const ENDPOINT_BROWSE = 'ingest_browse';
    public const PATH_BROWSE = '/api/v1/ingest/browse';

    /** The endpoints map key and the fallback path of a shopper's recommendations (§15). */
    public const ENDPOINT_RECOMMENDATIONS = 'recommendations_customer';
    public const PATH_RECOMMENDATIONS = '/api/v1/recommendations/customer';

    /** Seconds the browse relay may take: a storefront request waits for it. */
    public const RELAY_TIMEOUT_SECONDS = 3;

    /** Seconds the recommendations may take (§15 "Timeouts": at most 10). */
    public const RECOMMENDATIONS_TIMEOUT_SECONDS = 10;

    /**
     * Forwards browse events (§6), one attempt.
     *
     * @param list<array<string, mixed>> $events 1 to 100 events in the contract's shape
     *
     * @return array<string, mixed> the engine's answer, unchecked
     */
    public function relayBrowse(Connection $connection, array $events): array
    {
        return $this->request(
            'POST',
            $connection->endpoint(self::ENDPOINT_BROWSE, self::PATH_BROWSE),
            self::RELAY_TIMEOUT_SECONDS,
            $connection,
            ['events' => $events]
        );
    }

    /**
     * Asks for one shopper's current recommendations (§15), one attempt. The request names the
     * shopper by the store's customer id or by the visitor token only, never by a contact detail.
     *
     * @param array{customer_external_id: string}|array{smaily_visitor_token: string} $identifier
     *
     * @return array<string, mixed> the engine's answer, unchecked
     */
    public function recommendations(Connection $connection, array $identifier, int $limit): array
    {
        return $this->request(
            'POST',
            $connection->endpoint(self::ENDPOINT_RECOMMENDATIONS, self::PATH_RECOMMENDATIONS),
            self::RECOMMENDATIONS_TIMEOUT_SECONDS,
            $connection,
            $identifier + ['limit' => $limit]
        );
    }

    // --- Ingest (Story 5.2): the catalog (§3, §3b, §3c), customers (§4), orders (§5) and the
    // identity merge (§7). Each is one attempt, as every call above: the ingest queue retries.

    /** The endpoints map key, the fallback path and the body's wrapper of each ingest domain. */
    public const INGEST = [
        'catalog' => ['ingest_catalog', '/api/v1/ingest/catalog', 'products'],
        'customers' => ['ingest_customers', '/api/v1/ingest/customers', 'customers'],
        'orders' => ['ingest_orders', '/api/v1/ingest/orders', 'orders'],
    ];

    /** The endpoints map key and the fallback path of the product removal (§3b). */
    public const ENDPOINT_CATALOG_REMOVE = 'ingest_catalog_remove';
    public const PATH_CATALOG_REMOVE = '/api/v1/ingest/catalog/remove';

    /** The endpoints map key and the fallback path of the nightly product list (§3c). */
    public const ENDPOINT_CATALOG_MANIFEST = 'ingest_catalog_manifest';
    public const PATH_CATALOG_MANIFEST = '/api/v1/ingest/catalog/manifest';

    /** The endpoints map key and the fallback path of the identity merge (§7). */
    public const ENDPOINT_IDENTITY_MERGE = 'identity_merge';
    public const PATH_IDENTITY_MERGE = '/api/v1/identity/merge';

    /** Seconds one ingest request may take, so that a slow engine does not hold the tick. */
    public const INGEST_TIMEOUT_SECONDS = 10;

    /** Seconds the nightly product list may take: one request with up to 50 000 items. */
    public const MANIFEST_TIMEOUT_SECONDS = 20;

    /**
     * Sends one batch of catalog rows (§3), customers (§4) or orders (§5).
     *
     * @param string $domain a key of INGEST
     * @param list<array<string, mixed>> $items the rows, as the contract's field reference names them
     *
     * @return array<string, mixed> the engine's answer, unchecked: `errors[]` names the rows it refused
     */
    public function ingest(Connection $connection, string $domain, array $items): array
    {
        [$endpoint, $path, $wrapper] = self::INGEST[$domain];

        return $this->request('POST', $connection->endpoint($endpoint, $path), self::INGEST_TIMEOUT_SECONDS, $connection, [$wrapper => $items]);
    }

    /**
     * Tells the engine that products were deleted (§3b): it stops recommending every row of each.
     *
     * @param list<string> $productIds the parent product ids, as `tags.product_id` carries them
     *
     * @return array<string, mixed> the engine's answer, unchecked
     */
    public function catalogRemove(Connection $connection, array $productIds): array
    {
        return $this->request(
            'POST',
            $connection->endpoint(self::ENDPOINT_CATALOG_REMOVE, self::PATH_CATALOG_REMOVE),
            self::INGEST_TIMEOUT_SECONDS,
            $connection,
            ['product_ids' => $productIds]
        );
    }

    /**
     * Sends the connection's complete product list (§3c): each row's `sku` and `in_stock`.
     *
     * @param list<array{sku: string, in_stock: bool}> $products
     *
     * @return array<string, mixed> the engine's answer, unchecked
     */
    public function catalogManifest(Connection $connection, array $products): array
    {
        return $this->request(
            'POST',
            $connection->endpoint(self::ENDPOINT_CATALOG_MANIFEST, self::PATH_CATALOG_MANIFEST),
            self::MANIFEST_TIMEOUT_SECONDS,
            $connection,
            ['products' => $products]
        );
    }

    /**
     * Binds a visitor's token to a customer whom the store now knows (§7).
     *
     * @param array<string, mixed> $body `customer_email`, `smaily_visitor_token`, and where known
     *                                   `customer_external_id`, `merge_ts` and `merge_reason`
     *
     * @return array<string, mixed> the engine's answer, unchecked
     */
    public function identityMerge(Connection $connection, array $body): array
    {
        return $this->request(
            'POST',
            $connection->endpoint(self::ENDPOINT_IDENTITY_MERGE, self::PATH_IDENTITY_MERGE),
            self::INGEST_TIMEOUT_SECONDS,
            $connection,
            $body
        );
    }

    // --- The engine's automated emails (Story 5.4): the catalogue (§11), the store's
    // configuration rows (§12) and their save (§13). Each is one attempt: the merchant's page asks.

    /** The endpoints map key and the fallback path of the automation catalogue (§11). */
    public const ENDPOINT_AUTOMATIONS_CATALOG = 'automations_catalog';
    public const PATH_AUTOMATIONS_CATALOG = '/api/v1/automations/catalog';

    /** The endpoints map key and the fallback path of the automation configuration (§12, §13). */
    public const ENDPOINT_AUTOMATIONS_CONFIG = 'automations_config';
    public const PATH_AUTOMATIONS_CONFIG = '/api/v1/automations/config';

    /** Seconds an automation call may take: the merchant's page waits for it. */
    public const AUTOMATIONS_TIMEOUT_SECONDS = 10;

    /**
     * The automated emails that the engine offers this store now (§11).
     *
     * @return array<string, mixed> the engine's answer, unchecked
     */
    public function automationsCatalog(Connection $connection): array
    {
        return $this->request(
            'GET',
            $connection->endpoint(self::ENDPOINT_AUTOMATIONS_CATALOG, self::PATH_AUTOMATIONS_CATALOG),
            self::AUTOMATIONS_TIMEOUT_SECONDS,
            $connection
        );
    }

    /**
     * The store's automation configuration as the engine holds it (§12).
     *
     * @return array<string, mixed> the engine's answer, unchecked
     */
    public function automationsConfig(Connection $connection): array
    {
        return $this->request(
            'GET',
            $connection->endpoint(self::ENDPOINT_AUTOMATIONS_CONFIG, self::PATH_AUTOMATIONS_CONFIG),
            self::AUTOMATIONS_TIMEOUT_SECONDS,
            $connection
        );
    }

    /**
     * Saves automation configuration rows (§13). The engine checks all rows before it writes any:
     * a refusal is an EngineRequestException whose fields() name what it refused, and nothing was
     * saved.
     *
     * @param list<array<string, mixed>> $rows 1 to 50 rows in the contract's shape
     *
     * @return array<string, mixed> the engine's answer, unchecked
     */
    public function saveAutomationsConfig(Connection $connection, array $rows): array
    {
        return $this->request(
            'PUT',
            $connection->endpoint(self::ENDPOINT_AUTOMATIONS_CONFIG, self::PATH_AUTOMATIONS_CONFIG),
            self::AUTOMATIONS_TIMEOUT_SECONDS,
            $connection,
            ['configs' => $rows]
        );
    }
}
