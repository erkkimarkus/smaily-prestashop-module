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

namespace PrestaShop\Module\SmailyForPrestaShop\Smaily;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * The Smaily account of a shop (0 = all shops) and a language ('' = no language: the shop's own
 * account, or the fallback account in mode A).
 */
final class Account
{
    private int $idShop;

    private string $language;

    private string $subdomain;

    private string $username;

    private string $password;

    public function __construct(int $idShop, string $language, string $subdomain, string $username, string $password)
    {
        $this->idShop = $idShop;
        $this->language = $language;
        $this->subdomain = $subdomain;
        $this->username = $username;
        $this->password = $password;
    }

    public function idShop(): int
    {
        return $this->idShop;
    }

    public function language(): string
    {
        return $this->language;
    }

    public function subdomain(): string
    {
        return $this->subdomain;
    }

    public function username(): string
    {
        return $this->username;
    }

    /**
     * The password, or '' when the stored one does not decrypt.
     */
    public function password(): string
    {
        return $this->password;
    }

    /**
     * Whether the account can call Smaily: no credential is empty.
     */
    public function hasCredentials(): bool
    {
        return $this->subdomain !== '' && $this->username !== '' && $this->password !== '';
    }
}
