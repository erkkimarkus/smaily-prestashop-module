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
use PrestaShop\Module\SmailyForPrestaShop\Report\ConnectionHealth;
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
 * TransportException; an answer whose code is not 101 is an ApiException. A message is English
 * source text that names the HTTP status, the code or whether a network failure was a timeout,
 * never Smaily's answer, the HTTP client's text or a credential; the Log translates it
 * (Log\FailureMessage::MESSAGES). The client does not retry and writes no log line: the caller
 * decides both.
 *
 * lastExchange() holds what the last request sent and what came back, for the Log's Details: the
 * request body (or the query of a read), and the answer's HTTP status with its JSON body, less any
 * `message` (Smaily's text can name a contact). An answer that is not JSON keeps its status only.
 * It never holds a credential.
 *
 * Every answer is also told to the observer (observe()), for the installation report's health of
 * the account: the subdomain, '' or an error code, and whether the request wrote data. The clients
 * are built per account all over the module, so the observer is the class's, not an instance's:
 * no caller can leave it out.
 */
class Client
{
    public const ENDPOINT_CONTACT = 'contact';
    public const ENDPOINT_AUTORESPONDER = 'autoresponder';
    public const ENDPOINT_WORKFLOWS = 'workflows';

    /*
     * The messages of the failures this client throws, as English source text with %name%
     * placeholders: a queue row stores them with the values filled in, and the Log reads them back
     * by their wording (Log\FailureMessage::MESSAGES). A changed wording no longer reads rows
     * stored before.
     */
    public const MESSAGE_CREDENTIALS_REJECTED = 'Smaily API credentials were rejected';
    public const MESSAGE_PLAN_BLOCKED = 'Smaily refused the request: the account\'s package does not include API access.';
    public const MESSAGE_HTTP_STATUS = 'Smaily API request failed with HTTP %status%';
    public const MESSAGE_NO_ANSWER = 'Smaily API request failed: no answer within %seconds% seconds';
    public const MESSAGE_NOT_CONNECTED = 'Smaily API request failed: could not connect to Smaily';
    public const MESSAGE_MALFORMED_BODY = 'Smaily API returned a malformed response body';

    /** One DNS label: letters, digits and inner hyphens, at most 63 characters. */
    private const PLAIN_SUBDOMAIN = '/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/i';

    private const TIMEOUT_SECONDS = 30;

    /** @var (callable(string, string, bool): void)|null */
    private static $observer;

    private Transport $transport;

    private string $subdomain;

    private string $username;

    private string $password;

    /** @var array{request: array<int|string, mixed>, response: array{http_status: int, body: mixed}|null}|null */
    private ?array $lastExchange = null;

    public function __construct(Transport $transport, string $subdomain, string $username, string $password)
    {
        $this->transport = $transport;
        $this->subdomain = $subdomain;
        $this->username = $username;
        $this->password = $password;
    }

    /**
     * Names who is told how each request of every client went: the account's subdomain, '' for a
     * success or the installation report's error code, and whether the request wrote data. Null
     * tells nobody. The observer must not throw.
     *
     * @param (callable(string, string, bool): void)|null $observer
     *
     * @return (callable(string, string, bool): void)|null the observer named before, for a test
     *                                                     to put back
     */
    public static function observe(?callable $observer): ?callable
    {
        $before = self::$observer;
        self::$observer = $observer;

        return $before;
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
     * The last request's body and the answer to it: null before any request, and a null answer when
     * none arrived.
     *
     * @return array{request: array<int|string, mixed>, response: array{http_status: int, body: mixed}|null}|null
     */
    public function lastExchange(): ?array
    {
        return $this->lastExchange;
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
        $this->lastExchange = ['request' => (array) ($options['json'] ?? $options['query'] ?? []), 'response' => null];

        try {
            $response = $this->transport->request(
                $method,
                sprintf('https://%s.sendsmaily.net/api/%s.php', $this->subdomain, $endpoint),
                $options + [
                    'auth_basic' => [$this->username, $this->password],
                    'timeout' => self::TIMEOUT_SECONDS,
                    'max_duration' => self::TIMEOUT_SECONDS,
                ]
            );
        } catch (NetworkException $exception) {
            $this->tell(ConnectionHealth::UNREACHABLE);
            // The kind of failure, never the HTTP client's text: it is English, whatever the admin's.
            throw new TransportException($exception->timedOut() ? strtr(self::MESSAGE_NO_ANSWER, ['%seconds%' => (string) self::TIMEOUT_SECONDS]) : self::MESSAGE_NOT_CONNECTED);
        }

        $status = $response->status();
        $decoded = json_decode($response->body(), true);
        $this->lastExchange['response'] = ['http_status' => $status, 'body' => is_array($decoded) ? self::withoutMessages($decoded) : null];

        if ($status >= 400) {
            if (is_array($decoded) && (int) ($decoded['code'] ?? 0) === PlanBlockedException::SMAILY_CODE) {
                $this->tell(ConnectionHealth::PLAN_BLOCKED);
                throw new PlanBlockedException(self::MESSAGE_PLAN_BLOCKED, $status);
            }
            $this->tell($status !== 429 && $status < 500 ? ConnectionHealth::CREDENTIALS_REJECTED : ConnectionHealth::UNREACHABLE);
            if ($status === 401 || $status === 403) {
                throw new AuthenticationException(self::MESSAGE_CREDENTIALS_REJECTED, $status);
            }
            $message = strtr(self::MESSAGE_HTTP_STATUS, ['%status%' => (string) $status]);
            if ($status !== 429 && $status < 500) {
                throw new RequestRefusedException($message, $status);
            }

            throw new TransportException($message, $status, $this->retryAfterSeconds($response->header('Retry-After')));
        }

        if (!is_array($decoded)) {
            $this->tell(ConnectionHealth::UNREACHABLE);
            throw new TransportException(self::MESSAGE_MALFORMED_BODY, $status);
        }
        $refused = isset($decoded['code']) && (int) $decoded['code'] !== ApiException::CODE_SUCCESS;
        // A write whose code refuses it is a failure; a read's code is an answer (206: no such
        // contact), not a refusal.
        $this->tell($refused && $method === 'POST' ? self::refusedWriteCode((int) $decoded['code']) : '', $method === 'POST' && !$refused);
        if ($refused) {
            throw new ApiException((int) $decoded['code']);
        }

        return $decoded;
    }

    /**
     * Tells the observer how this account's request went: '' or an error code, and whether it
     * wrote data. The subdomain and a code only, never a credential or a message.
     */
    private function tell(string $errorCode, bool $wrote = false): void
    {
        if (self::$observer !== null) {
            (self::$observer)($this->subdomain, $errorCode, $wrote);
        }
    }

    /**
     * The installation report's code for an HTTP 200 answer that refused a write: code 227 is the
     * package, the permanent refusal (203, invalid data) is named by its code, any other may pass
     * later.
     */
    private static function refusedWriteCode(int $smailyCode): string
    {
        if ($smailyCode === PlanBlockedException::SMAILY_CODE) {
            return ConnectionHealth::PLAN_BLOCKED;
        }

        return $smailyCode === ApiException::CODE_INVALID_DATA ? 'permanent_envelope_' . $smailyCode : ConnectionHealth::UNREACHABLE;
    }

    /**
     * @param array<int|string, mixed> $body
     *
     * @return array<int|string, mixed>
     */
    private static function withoutMessages(array $body): array
    {
        unset($body['message']);
        foreach ($body as $key => $value) {
            if (is_array($value)) {
                $body[$key] = self::withoutMessages($value);
            }
        }

        return $body;
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
