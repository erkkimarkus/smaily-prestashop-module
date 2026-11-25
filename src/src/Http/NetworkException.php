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

namespace PrestaShop\Module\SmailyForPrestaShop\Http;

if (!defined('_PS_VERSION_')) {
    exit;
}

use Symfony\Contracts\HttpClient\Exception\TimeoutExceptionInterface;

/**
 * No answer arrived: DNS, connection, TLS or a timeout.
 *
 * The HTTP client's message quotes the request URL, whose query or path can carry a contact's
 * address. The message here keeps each URL's scheme, host, port and path only; a path segment
 * holding an address reads {email}. The client's exception is not chained; whether it was a
 * timeout is kept (timedOut()).
 */
final class NetworkException extends \RuntimeException
{
    private const URL_PATTERN = '~[a-z][a-z0-9+.-]*://[^\s()<>"\']+~i';

    private bool $timedOut = false;

    public static function of(\Throwable $failure): self
    {
        $exception = new self((string) preg_replace_callback(
            self::URL_PATTERN,
            static fn (array $match): string => self::maskUrl($match[0]),
            $failure->getMessage()
        ));
        // Symfony 4.4, which PrestaShop 8 ships, has no TimeoutException: its message names the
        // idle timeout ("Idle timeout reached for …"), as curl's names its own ("… timed out …").
        $exception->timedOut = $failure instanceof TimeoutExceptionInterface
            || preg_match('/timed out|timeout reached/i', $failure->getMessage()) === 1;

        return $exception;
    }

    /**
     * Whether no answer came within the request's timeout.
     */
    public function timedOut(): bool
    {
        return $this->timedOut;
    }

    private static function maskUrl(string $url): string
    {
        $parts = parse_url($url);
        if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
            return '[url]';
        }

        $segments = explode('/', $parts['path'] ?? '');
        foreach ($segments as $index => $segment) {
            if (strpos(rawurldecode($segment), '@') !== false) {
                $segments[$index] = '{email}';
            }
        }

        return $parts['scheme'] . '://' . $parts['host']
            . (isset($parts['port']) ? ':' . $parts['port'] : '')
            . implode('/', $segments);
    }
}
