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

namespace PrestaShop\Module\SmailyForPrestaShop\Report;

if (!defined('_PS_VERSION_')) {
    exit;
}

use PrestaShop\Module\SmailyForPrestaShop\Http\NetworkException;
use PrestaShop\Module\SmailyForPrestaShop\Http\Transport;

/**
 * Sends one installation report to Smaily's subscription service (contract installation-report-v1
 * "Endpoint: POST /v1/reports").
 *
 * HTTPS only, the certificate verified; 3 seconds to connect and 5 in all; never a redirect. The
 * main account's API user and password go in the Authorization header only. It answers what the
 * service said (SendResult); deciding whether to try again is the caller's. It never throws, and
 * it reads nothing of the answer but the HTTP status, the error code and Retry-After: the answer's
 * `latest_version` and `notice` are not read (ARCHITECTURE 11.5).
 *
 * Tests and dev shops never reach ENDPOINT: tests give it a transport that answers, the dev stacks
 * resolve the host to the shop container itself (docker-compose.yaml), and the egress guard hands
 * it to a local mock receiver. A report there would create a real installation record.
 *
 * Not final: the unit tests stand in for it.
 */
class ReportSender
{
    /** The receiver: the one place its address is written. */
    public const ENDPOINT = 'https://subscription.smaily.com/v1/reports';

    public const CONNECT_TIMEOUT_SECONDS = 3;

    public const TIMEOUT_SECONDS = 5;

    public const MAX_BODY_BYTES = 65536;

    /** No answer came: DNS, connection, TLS or a timeout. */
    public const NETWORK_ERROR = 'network_error';

    /** The form of the error code in the service's answers, and of this class's own codes. */
    private const CODE_PATTERN = '/^[a-z0-9_]{1,64}$/D';

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

    public function send(Report $report): SendResult
    {
        $json = json_encode($report->body(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            return new SendResult(0, 'invalid_json', false);
        }
        if (strlen($json) > self::MAX_BODY_BYTES) {
            return new SendResult(0, 'body_too_large', false);
        }

        try {
            $response = $this->transport->request('POST', self::ENDPOINT, [
                'body' => $json,
                'headers' => [
                    'Authorization' => $report->credentials()->authorization(),
                    'Content-Type' => 'application/json; charset=utf-8',
                    'Accept' => 'application/json',
                    'User-Agent' => $this->userAgent,
                ],
                'timeout' => self::CONNECT_TIMEOUT_SECONDS,
                'max_duration' => self::TIMEOUT_SECONDS,
                'max_redirects' => 0,
            ]);
        } catch (NetworkException $exception) {
            // The transport's message is not kept: the next report carries the state.
            return new SendResult(0, self::NETWORK_ERROR, true);
        }

        $status = $response->status();
        if ($status >= 200 && $status < 300) {
            return new SendResult($status, '', false);
        }

        $answer = json_decode($response->body(), true);
        $error = is_array($answer) && is_string($answer['error'] ?? null) && preg_match(self::CODE_PATTERN, $answer['error']) === 1
            ? $answer['error']
            : '';
        if ($status >= 300 && $status < 400) {
            // A redirect means the base URL is wrong; it is never followed.
            return new SendResult($status, $error !== '' ? $error : 'redirect', false);
        }

        $retryAfter = (string) $response->header('Retry-After');

        return new SendResult($status, $error, $status === 429 || $status >= 500, ctype_digit($retryAfter) ? (int) $retryAfter : 0);
    }
}
