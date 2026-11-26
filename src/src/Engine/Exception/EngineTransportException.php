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

namespace PrestaShop\Module\SmailyForPrestaShop\Engine\Exception;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * A failure that may pass later: no answer, an HTTP 429 or 5xx, or an answer that is not JSON. A
 * 429 carries the wait it asked for.
 */
class EngineTransportException extends EngineException
{
    private ?int $retryAfter;

    /**
     * @param int|null $retryAfter the seconds a 429 asked the caller to wait, if it said
     */
    public function __construct(string $errorCode, int $httpStatus = 0, ?int $retryAfter = null)
    {
        parent::__construct($errorCode, $httpStatus);
        $this->retryAfter = $retryAfter;
    }

    /**
     * The wait in seconds that a 429 asked for (`retry_after_seconds`, else the `Retry-After`
     * header), or null when it named none.
     */
    public function retryAfter(): ?int
    {
        return $this->retryAfter;
    }
}
