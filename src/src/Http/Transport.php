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

/**
 * Sends one HTTP request and hands back the whole answer, whatever its status.
 */
interface Transport
{
    /**
     * @param array<string, mixed> $options the request options of Symfony's HttpClientInterface that
     *                                      the module uses: query, json, auth_basic, headers, timeout
     *
     * @throws NetworkException when no answer arrives
     */
    public function request(string $method, string $url, array $options = []): Response;
}
