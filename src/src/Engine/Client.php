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

    /**
     * @param string $moduleVersion the installed module's version, which the User-Agent names
     */
    public function __construct(Transport $transport, string $moduleVersion)
    {
        $this->transport = $transport;
        $this->userAgent = 'SmailyConnect-PrestaShopModule/' . $moduleVersion;
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
            $connection->apiKey()
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
            $connection->apiKey()
        );
    }

    /**
     * @param string|null $apiKey the connection's key; null for the unauthenticated exchange
     * @param array<string, mixed>|null $body sent as JSON; null sends no body
     *
     * @return array<string, mixed>
     */
    private function request(string $method, string $url, int $timeout, ?string $apiKey, ?array $body = null): array
    {
        if (!self::isEngineUrl($url)) {
            throw new EngineRequestException(self::HOST_NOT_ALLOWED);
        }
        if ($apiKey === '') {
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
            throw new EngineTransportException(self::NETWORK_ERROR);
        }

        $status = $response->status();
        $answer = json_decode($response->body(), true);
        if ($status >= 200 && $status < 300) {
            if (!is_array($answer)) {
                throw new EngineTransportException(self::MALFORMED_ANSWER, $status);
            }

            return $answer;
        }

        $error = is_array($answer) ? ($answer['error'] ?? null) : null;
        $code = is_string($error) && preg_match(EngineRequestException::CODE_PATTERN, $error) === 1
            ? $error
            : 'http_' . $status;
        if ($status === 429 || $status >= 500) {
            throw new EngineTransportException($code, $status);
        }

        throw new EngineRequestException($code, $status);
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
            $connection->apiKey()
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
            $connection->apiKey(),
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
            $connection->apiKey(),
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
}
