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

/**
 * The limit of the anonymous storefront endpoints per network address (the browse relay, the
 * landing and the recommendations), as the Magento connector's PerAddressLimiter: a counter per
 * endpoint, address and fixed window, so the store cannot be used to flood the engine with its
 * own key.
 *
 * No address is stored: a counter is kept under an HMAC of the address with the shop's cookie key,
 * and it expires after two windows (Store). The address is the one PrestaShop itself reads for a
 * request (Tools::getRemoteAddr()).
 */
final class RateLimiter
{
    private Store $store;

    /** @var callable(): string */
    private $address;

    /** @var callable(): int */
    private $clock;

    private string $secret;

    /**
     * @param callable(): string $address the caller's network address
     * @param callable(): int $clock the current Unix time
     * @param string $secret the key of the address hash
     */
    public function __construct(Store $store, callable $address, callable $clock, string $secret)
    {
        $this->store = $store;
        $this->address = $address;
        $this->clock = $clock;
        $this->secret = $secret;
    }

    /**
     * Counts one request of the caller's address to $endpoint in the current window when the
     * address has had fewer than $limit; a refused request is not counted.
     */
    public function allow(string $endpoint, int $limit, int $windowSeconds = 60): bool
    {
        $window = intdiv(($this->clock)(), $windowSeconds);
        $key = 'limit:' . $endpoint . ':' . hash_hmac('sha256', (string) ($this->address)(), $this->secret) . ':' . $window;
        $count = (int) $this->store->get($key);
        if ($count >= $limit) {
            return false;
        }
        $this->store->set($key, (string) ($count + 1), 2 * $windowSeconds);

        return true;
    }
}
