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

namespace PrestaShop\Module\SmailyForPrestaShop\Smaily\Exception;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * A failure that may pass later: no answer, a malformed answer, an HTTP 429 or a 5xx.
 */
class TransportException extends SmailyClientException
{
    use HttpStatusCode;

    private ?int $retryAfter;

    /**
     * @param int $httpStatus the HTTP status of the answer, 0 when none arrived
     * @param int|null $retryAfter the seconds Smaily asked the caller to wait
     */
    public function __construct(string $message, int $httpStatus = 0, ?int $retryAfter = null)
    {
        parent::__construct($message, $httpStatus);
        $this->retryAfter = $retryAfter;
    }

    /**
     * The Retry-After header of a 429 in seconds, or null when Smaily sent none.
     */
    public function getRetryAfter(): ?int
    {
        return $this->retryAfter;
    }
}
