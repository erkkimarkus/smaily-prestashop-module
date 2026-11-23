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

/**
 * What the subscription service answered an installation report: the HTTP status (0 when no answer
 * came), the service's error code ('' for none), whether the report may be sent again and the wait
 * it asked for.
 */
final class SendResult
{
    private int $httpStatus;

    private string $error;

    private bool $retry;

    private int $retryAfter;

    public function __construct(int $httpStatus, string $error, bool $retry, int $retryAfter = 0)
    {
        $this->httpStatus = $httpStatus;
        $this->error = $error;
        $this->retry = $retry;
        $this->retryAfter = $retryAfter;
    }

    /**
     * Whether the service took the report in (a 2xx answer).
     */
    public function isAccepted(): bool
    {
        return $this->httpStatus >= 200 && $this->httpStatus < 300;
    }

    /**
     * Whether the same report may pass later: a 429, a 5xx, or no answer.
     */
    public function mayRetry(): bool
    {
        return $this->retry;
    }

    /**
     * The seconds that the answer's Retry-After asked for, 0 when it named none.
     */
    public function retryAfter(): int
    {
        return $this->retryAfter;
    }

    /**
     * For one line of the shop's log: the status and the error code, never a message, a body or a
     * credential.
     */
    public function summary(): string
    {
        return sprintf('http=%d error=%s', $this->httpStatus, $this->error !== '' ? $this->error : '-');
    }
}
