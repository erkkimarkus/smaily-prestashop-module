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

/**
 * The one answer to "which shop, language and account" for an event (ARCHITECTURE 5.2).
 *
 * The shop and the language come from the store's data: the record of the event (customer,
 * newsletter subscription row, order, cart), or the shop and the language of the storefront
 * request. Never from the context of the request that processes the event later, such as a cron.
 */
final class ScopeResolver
{
    private StoreLayout $layout;

    private AccountResolver $accounts;

    /** @var callable(int): (string|false) */
    private $isoCode;

    /**
     * @param callable(int): (string|false) $isoCode the ISO code of a PrestaShop language id, as
     *                                               Language::getIsoById() reads it
     */
    public function __construct(StoreLayout $layout, AccountResolver $accounts, callable $isoCode)
    {
        $this->layout = $layout;
        $this->accounts = $accounts;
        $this->isoCode = $isoCode;
    }

    /**
     * The scope of a record that names its shop and its language in `id_shop` and `id_lang`: a
     * Customer, Order or Cart object, or a row of their tables or of `emailsubscription`.
     *
     * @param array<string, mixed>|object $record
     */
    public function forRecord(array|object $record): Scope
    {
        $fields = is_array($record) ? $record : get_object_vars($record);

        return $this->resolve((int) ($fields['id_shop'] ?? 0), (int) ($fields['id_lang'] ?? 0));
    }

    public function resolve(int $idShop, int $idLang): Scope
    {
        $iso = $idLang > 0 ? ($this->isoCode)($idLang) : false;
        $language = is_string($iso) ? strtolower($iso) : '';

        return new Scope(
            $idShop,
            $language,
            $this->layout->separateStores() ? $idShop : 0,
            $this->accounts->for($idShop, $language)
        );
    }
}
