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
 * A Smaily account's API credentials, as the installation report's Authorization header carries
 * them. They never go in the body, a log line or an exception message.
 */
final class Credentials
{
    private string $subdomain;

    private string $username;

    private string $password;

    public function __construct(string $subdomain, string $username, string $password)
    {
        $this->subdomain = $subdomain;
        $this->username = $username;
        $this->password = $password;
    }

    public function subdomain(): string
    {
        return $this->subdomain;
    }

    /**
     * The Authorization header value: HTTP Basic with the API user and password.
     */
    public function authorization(): string
    {
        return 'Basic ' . base64_encode($this->username . ':' . $this->password);
    }
}
