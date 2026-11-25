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
 * The base of every failure of a Campaign Intelligence call.
 *
 * It carries an error code and the HTTP status (0 when no answer arrived), and nothing else of the
 * answer: the engine's message can name a shopper, so it is never read. The code is the engine's
 * own (`error`) when it is a plain code, else `http_<status>`, or one of the module's own for a
 * failure before or without an answer.
 */
class EngineException extends \RuntimeException
{
    /**
     * A plain code: what the engine's `error` field holds, and nothing that could be a sentence.
     * The installation report's service names its errors in the same form (Report\ReportSender,
     * Report\ConnectionHealth).
     */
    public const CODE_PATTERN = '/^[a-z0-9_]{1,64}$/D';

    /*
     * The messages, as English source text with %name% placeholders: a row stores them with the
     * values filled in (Privacy\EngineFailure), and the Log reads them back by their wording
     * (Log\FailureMessage::MESSAGES).
     */
    public const MESSAGE_ANSWERED = 'Campaign Intelligence answered HTTP %status% (%code%).';
    public const MESSAGE_FAILED = 'The Campaign Intelligence request failed (%code%).';

    private string $errorCode;

    public function __construct(string $errorCode, int $httpStatus = 0)
    {
        parent::__construct(
            $httpStatus > 0
                ? strtr(self::MESSAGE_ANSWERED, ['%status%' => (string) $httpStatus, '%code%' => $errorCode])
                : strtr(self::MESSAGE_FAILED, ['%code%' => $errorCode]),
            $httpStatus
        );
        $this->errorCode = $errorCode;
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }

    /**
     * The HTTP status of the answer, 0 when none arrived or none was asked for.
     */
    public function httpStatus(): int
    {
        return $this->getCode();
    }

    /**
     * The stored reason of a row that this failure parks for good: the HTTP status and the code.
     */
    public function permanentReason(): string
    {
        return sprintf('permanent_http_%d: %s', $this->httpStatus(), $this->errorCode);
    }

    /**
     * What a row keeps as the answer of the failed request: the HTTP status and the code, or null
     * when no answer arrived.
     *
     * @return array{http_status: int, body: array{error: string}}|null
     */
    public function response(): ?array
    {
        return $this->httpStatus() > 0 ? ['http_status' => $this->httpStatus(), 'body' => ['error' => $this->errorCode]] : null;
    }
}
