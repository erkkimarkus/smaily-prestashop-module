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

namespace PrestaShop\Module\SmailyForPrestaShop\Scope;

if (!defined('_PS_VERSION_')) {
    exit;
}

use PrestaShop\Module\SmailyForPrestaShop\Smaily\Account;

/**
 * Which shop, language and account an event belongs to.
 */
final class Scope
{
    private int $idShop;

    private string $language;

    private int $setupShopId;

    private ?Account $account;

    public function __construct(int $idShop, string $language, int $setupShopId, ?Account $account)
    {
        $this->idShop = $idShop;
        $this->language = $language;
        $this->setupShopId = $setupShopId;
        $this->account = $account;
    }

    /**
     * The shop of the contact or the event.
     */
    public function idShop(): int
    {
        return $this->idShop;
    }

    /**
     * The two-letter ISO code of the contact's language, or '' for no language.
     */
    public function language(): string
    {
        return $this->language;
    }

    /**
     * The shop whose own setup serves the event: the shop itself in separate stores, else 0, the
     * setup that the shops share. It is the id_shop of the event's Campaign Intelligence
     * connection (0 = the installation-wide connection).
     */
    public function setupShopId(): int
    {
        return $this->setupShopId;
    }

    /**
     * The Smaily account the contact goes to, or null when the shop has none.
     */
    public function account(): ?Account
    {
        return $this->account;
    }
}
