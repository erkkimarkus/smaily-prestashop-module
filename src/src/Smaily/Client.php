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

namespace PrestaShop\Module\SmailyForPrestaShop\Smaily;

if (!defined('_PS_VERSION_')) {
    exit;
}

use PrestaShop\Module\SmailyForPrestaShop\Http\NetworkException;
use PrestaShop\Module\SmailyForPrestaShop\Http\Transport;
use PrestaShop\Module\SmailyForPrestaShop\Smaily\Exception\ApiException;
use PrestaShop\Module\SmailyForPrestaShop\Smaily\Exception\AuthenticationException;
use PrestaShop\Module\SmailyForPrestaShop\Smaily\Exception\InvalidSubdomainException;
use PrestaShop\Module\SmailyForPrestaShop\Smaily\Exception\PlanBlockedException;
use PrestaShop\Module\SmailyForPrestaShop\Smaily\Exception\RequestRefusedException;
use PrestaShop\Module\SmailyForPrestaShop\Smaily\Exception\TransportException;

/**
 * The Smaily marketing API client of one account.
 *
 * Wire facts, shared with the Magento and WooCommerce connectors: the base URL is
 * https://{subdomain}.sendsmaily.net/api/{endpoint}.php, with HTTP Basic authentication. A write
 * that Smaily took answers HTTP 200 with {"code": 101}; list endpoints answer a plain array.
 *
 * Every failure is typed where it is thrown, as Smaily Connect for Magento types it: an HTTP 4xx
 * other than 429 is a RequestRefusedException (401 and 403 an AuthenticationException, Smaily code
 * 227 a PlanBlockedException); a 429, a 5xx, a malformed answer and a network failure are a
 * TransportException; an answer whose code is not 101 is an ApiException. A message names the HTTP
 * status, the code or the network error, never Smaily's answer and never a credential. The client
 * does not retry and writes no log line: the caller decides both.
 */
class Client
{
    public const ENDPOINT_CONTACT = 'contact';
    public const ENDPOINT_AUTORESPONDER = 'autoresponder';
    public const ENDPOINT_WORKFLOWS = 'workflows';

    /** One DNS label: letters, digits and inner hyphens, at most 63 characters. */
    private const PLAIN_SUBDOMAIN = '/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/i';

    private const TIMEOUT_SECONDS = 30;

    private Transport $transport;

    private string $subdomain;

    private string $username;

    private string $password;

    public function __construct(Transport $transport, string $subdomain, string $username, string $password)
    {
        $this->transport = $transport;
        $this->subdomain = $subdomain;
        $this->username = $username;
        $this->password = $password;
    }

    /**
     * @param array<string, mixed> $query
     *
     * @return array<int|string, mixed> the decoded answer
     */
    public function get(string $endpoint, array $query = []): array
    {
        return $this->request('GET', $endpoint, ['query' => $query]);
    }

    /**
     * @param array<int|string, mixed> $payload sent as JSON
     *
     * @return array<int|string, mixed> the decoded answer
     */
    public function post(string $endpoint, array $payload): array
    {
        return $this->request('POST', $endpoint, ['json' => $payload]);
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return array<int|string, mixed>
     */
    private function request(string $method, string $endpoint, array $options): array
    {
        if (preg_match(self::PLAIN_SUBDOMAIN, $this->subdomain) !== 1) {
            throw new InvalidSubdomainException();
        }

        try {
            $response = $this->transport->request(
                $method,
                sprintf('https://%s.sendsmaily.net/api/%s.php', $this->subdomain, $endpoint),
                $options + [
                    'auth_basic' => [$this->username, $this->password],
                    'timeout' => self::TIMEOUT_SECONDS,
                ]
            );
        } catch (NetworkException $exception) {
            throw new TransportException(sprintf('Smaily API request failed: %s', $exception->getMessage()));
        }

        $status = $response->status();
        $decoded = json_decode($response->body(), true);

        if ($status >= 400) {
            if (is_array($decoded) && (int) ($decoded['code'] ?? 0) === PlanBlockedException::SMAILY_CODE) {
                throw new PlanBlockedException('Smaily refused the request: the account\'s package does not include API access.', $status);
            }
            if ($status === 401 || $status === 403) {
                throw new AuthenticationException('Smaily API credentials were rejected', $status);
            }
            $message = sprintf('Smaily API request failed with HTTP %d', $status);
            if ($status !== 429 && $status < 500) {
                throw new RequestRefusedException($message, $status);
            }

            throw new TransportException($message, $status, $this->retryAfterSeconds($response->header('Retry-After')));
        }

        if (!is_array($decoded)) {
            throw new TransportException('Smaily API returned a malformed response body', $status);
        }
        if (isset($decoded['code']) && (int) $decoded['code'] !== ApiException::CODE_SUCCESS) {
            throw new ApiException((int) $decoded['code']);
        }

        return $decoded;
    }

    /**
     * The Retry-After header in whole seconds, or null when it is absent or an HTTP date (Smaily
     * sends seconds; a date falls back to the caller's own backoff rather than being misread).
     */
    private function retryAfterSeconds(?string $header): ?int
    {
        if ($header === null || !is_numeric($header)) {
            return null;
        }
        $seconds = (int) $header;

        return $seconds > 0 ? $seconds : null;
    }
}
