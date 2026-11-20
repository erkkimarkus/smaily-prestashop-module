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
 * The Smaily account that a contact of a shop and a language goes to (ARCHITECTURE 5.2).
 *
 * A shop reads its own account rows first. In one store it then reads the global rows (id_shop
 * 0); in separate stores it never reads a row of another shop or a global one. Within those rows,
 * mode `a` takes the account of the contact's language, else the account of the fallback language,
 * else the account of no language; every other mode takes the account of no language only, so the
 * rows of a language that an earlier mode `a` left are not read.
 */
final class AccountResolver
{
    private StoreLayout $layout;

    /** @var callable(int, string): ?Account */
    private $find;

    /**
     * @param callable(int, string): ?Account $find the account row of a shop and a language, as
     *                                              Smaily\AccountStore::find() reads it
     */
    public function __construct(StoreLayout $layout, callable $find)
    {
        $this->layout = $layout;
        $this->find = $find;
    }

    /**
     * The account, or null when the shop has none. An account whose password does not decrypt is
     * still returned: the contact belongs to it, and it has no credentials until it is reconnected.
     */
    public function for(int $idShop, string $language): ?Account
    {
        $languages = [''];
        if ($this->layout->languageMode($idShop) === StoreLayout::MODE_ACCOUNT_PER_LANGUAGE) {
            $languages = array_filter([$language, $this->layout->fallbackLanguage($idShop)], 'strlen');
            $languages[] = '';
            $languages = array_values(array_unique($languages));
        }
        $shops = $idShop === 0 || $this->layout->separateStores() ? [$idShop] : [$idShop, 0];

        foreach ($shops as $shop) {
            foreach ($languages as $candidate) {
                $account = ($this->find)($shop, $candidate);
                if ($account !== null) {
                    return $account;
                }
            }
        }

        return null;
    }
}
