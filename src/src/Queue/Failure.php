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

namespace PrestaShop\Module\SmailyForPrestaShop\Queue;

if (!defined('_PS_VERSION_')) {
    exit;
}

use PrestaShop\Module\SmailyForPrestaShop\Smaily\Exception\ApiException;
use PrestaShop\Module\SmailyForPrestaShop\Smaily\Exception\RequestRefusedException;
use PrestaShop\Module\SmailyForPrestaShop\Smaily\Exception\TransportException;

/**
 * A handler's outcome for a row whose send failed: the reason the Log shows, and whether the row
 * stops for good or takes the retry ladder (RetryPolicy). The classification of the sibling
 * connectors (Magento's Model\Queue\Failure):
 *  - permanent: a refusal that a retry cannot change, parked on the first attempt. A 4xx other
 *    than 429, as `permanent_http_<code>: <message>`; a Smaily envelope code that the client marks
 *    permanent (203 "invalid data" on HTTP 200), as `permanent_envelope_<code>: <message>`; or a
 *    handler's own verdict (a malformed payload, an event type no handler takes).
 *  - temporary: anything else (a 429, spaced by its Retry-After; a 5xx; a network failure; any
 *    other envelope code), which keeps the ladder.
 *
 * Which Smaily answers are permanent is decided once, by the type of the Smaily client's error
 * (Smaily\Exception); of() reads that verdict.
 *
 * A reason is short and names no person: the message a handler passes is the client's own text
 * (the HTTP status, the code), never a request body or the remote server's text.
 */
final class Failure
{
    /**
     * The failure class a permanent refusal's reason starts with, followed by `: ` and the message.
     */
    public const CLASS_REGEX = 'permanent_(http|envelope)_[0-9]+';

    private string $reason;

    private bool $permanent;

    private ?int $retryAfter;

    private function __construct(string $reason, bool $permanent, ?int $retryAfter)
    {
        $this->reason = $reason;
        $this->permanent = $permanent;
        $this->retryAfter = $retryAfter;
    }

    /**
     * A handler's own verdict that the row can never be sent.
     */
    public static function permanent(string $reason): self
    {
        return new self($reason, true, null);
    }

    /**
     * A failure that a later attempt can pass.
     */
    public static function temporary(string $reason, ?int $retryAfter = null): self
    {
        return new self($reason, false, $retryAfter);
    }

    /**
     * A request answered with a non-success HTTP status. $retryAfter is the delay in seconds that a
     * 429 asked for, if any.
     */
    public static function http(int $status, string $message, ?int $retryAfter = null): self
    {
        if ($status >= 400 && $status < 500 && $status !== 429) {
            return self::permanent(sprintf('permanent_http_%d: %s', $status, $message));
        }

        return self::temporary($message, $status === 429 ? $retryAfter : null);
    }

    /**
     * An error a handler threw or handed back. A failure of the Smaily client keeps the verdict
     * that its type carries and its message, which names no person. Any other error is kept by its
     * type, its code and the place it was thrown, never its text, which can quote a customer's data.
     */
    public static function of(\Throwable $error): self
    {
        if ($error instanceof RequestRefusedException) {
            return self::permanent(sprintf('permanent_http_%d: %s', $error->getHttpStatus(), $error->getMessage()));
        }
        if ($error instanceof ApiException) {
            return $error->isPermanent()
                ? self::permanent(sprintf('permanent_envelope_%d: %s', $error->getSmailyCode(), $error->getMessage()))
                : self::temporary($error->getMessage());
        }
        if ($error instanceof TransportException) {
            return self::temporary($error->getMessage(), $error->getRetryAfter());
        }

        return self::temporary(sprintf(
            '%s at %s:%d (code %s)',
            get_class($error),
            basename($error->getFile()),
            $error->getLine(),
            (string) $error->getCode()
        ));
    }

    public function reason(): string
    {
        return $this->reason;
    }

    public function isPermanent(): bool
    {
        return $this->permanent;
    }

    /**
     * The delay in seconds that Smaily asked for, which replaces the ladder's step.
     */
    public function retryAfter(): ?int
    {
        return $this->retryAfter;
    }
}
