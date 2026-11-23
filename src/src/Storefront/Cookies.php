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

namespace PrestaShop\Module\SmailyForPrestaShop\Storefront;

if (!defined('_PS_VERSION_')) {
    exit;
}

use Symfony\Component\HttpFoundation\Request;

/**
 * The cookies of the visitor's request, and the one way the server sets a storefront cookie.
 *
 * Every cookie the server sets has the same attributes as the ones the storefront scripts write
 * (contract "Cookie names"): the shop's own path, no domain (the shop's host), `Secure` on HTTPS,
 * `SameSite=Lax` and no `HttpOnly`, because the scripts read them. A cookie from a `Set-Cookie`
 * header keeps its whole lifetime in Safari, which cuts a cookie that page JavaScript writes to
 * 7 days.
 */
final class Cookies
{
    /** @var array<string, mixed> */
    private array $received;

    private string $path;

    private bool $secure;

    /** @var callable(string, string, array<string, mixed>): bool */
    private $send;

    /**
     * @param array<string, mixed> $received the request's cookies by name
     * @param string $path the shop's base path, e.g. `/`
     * @param callable(string, string, array<string, mixed>): bool|null $send sets a cookie as PHP's
     *                                                                        setcookie() does
     */
    public function __construct(array $received, string $path, bool $secure, ?callable $send = null)
    {
        $this->received = $received;
        $this->path = $path;
        $this->secure = $secure;
        $this->send = $send ?? static fn (string $name, string $value, array $options): bool => setcookie($name, $value, $options);
    }

    /**
     * The cookies of the current storefront request.
     */
    public static function ofRequest(\Context $context): self
    {
        return new self(
            Request::createFromGlobals()->cookies->all(),
            $context->shop instanceof \Shop ? (string) $context->shop->getBaseURI() : '/',
            (bool) \Tools::usingSecureMode()
        );
    }

    /**
     * The trimmed value of a cookie of the request, or null when the request has none.
     */
    public function get(string $name): ?string
    {
        $value = $this->received[$name] ?? null;

        return is_string($value) ? trim($value) : null;
    }

    /**
     * Sets a cookie for a number of days, or deletes it when $value is ''.
     *
     * @return bool false when the cookie could not be sent: the response has started
     */
    public function set(string $name, string $value, int $days): bool
    {
        return (bool) ($this->send)($name, $value, [
            'expires' => $value === '' ? 1 : time() + $days * 86400,
            'path' => $this->path,
            'secure' => $this->secure,
            'httponly' => false,
            'samesite' => 'Lax',
        ]);
    }
}
