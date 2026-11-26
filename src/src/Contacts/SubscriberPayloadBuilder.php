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

namespace PrestaShop\Module\SmailyForPrestaShop\Contacts;

if (!defined('_PS_VERSION_')) {
    exit;
}

use PrestaShop\Module\SmailyForPrestaShop\Database\Sql;

/**
 * The one shape of a contact row for Smaily's `contact.php`.
 *
 * A row carries `email`, `store` (the domain of the contact's shop, as 2.x sent it), `language` (the
 * ISO code of the contact's language) and the merchant's selected fields, with the field names 2.x
 * sent and, for the fields that 2.x did not offer, Magento's names: `user_gender` (`Male` or
 * `Female`), `customer_id` and `customer_group` (the group's name in the shop's default language;
 * a newsletter-block subscriber without an account is in the shop's guest group, as Magento sends
 * `Guest`). `is_unsubscribed` is there only when ContactAudience gives a status. An empty value is
 * left out: Smaily keeps a field that is absent and clears one that is empty.
 */
final class SubscriberPayloadBuilder
{
    /**
     * The fields a merchant can select, by Smaily name, and the `customer` column each comes from.
     */
    private const COLUMNS = [
        'first_name' => 'firstname',
        'last_name' => 'lastname',
        'birthday' => 'birthday',
        'website' => 'website',
        'customer_id' => 'id_customer',
    ];

    /** The Smaily names of the fields a merchant can select. */
    public const FIELDS = ['first_name', 'last_name', 'birthday', 'website', 'user_gender', 'customer_id', 'customer_group'];

    /**
     * The fields selected where nothing is stored: the fields of 2.x. Gender, Customer ID and
     * Customer group are the merchant's choice.
     */
    public const DEFAULT_FIELDS = ['first_name', 'last_name', 'birthday', 'website'];

    /** PrestaShop's value of a birthday that was never entered. */
    private const NO_BIRTHDAY = '0000-00-00';

    /** PrestaShop's gender types (`gender`.`type`) that Smaily takes, by Magento's words. */
    private const GENDERS = ['0' => 'Male', '1' => 'Female'];

    /** @var callable(int): ?string */
    private $shopDomain;

    /** @var callable(int): ?string */
    private $genderType;

    /** @var callable(int, int): ?string */
    private $groupName;

    /**
     * @param callable(int): ?string $shopDomain the domain of a shop's main URL, as
     *                                           ShopUrl::getMainShopDomain() reads it
     * @param callable(int): ?string $genderType the type of a gender id, as genderType() reads it
     * @param callable(int, int): ?string $groupName the name of a customer group in a shop, as
     *                                               groupName() reads it; group 0 is the
     *                                               shop's guest group
     */
    public function __construct(callable $shopDomain, callable $genderType, callable $groupName)
    {
        $this->shopDomain = $shopDomain;
        $this->genderType = $genderType;
        $this->groupName = $groupName;
    }

    /**
     * The `type` of a gender (0 male, 1 female, 2 neutral), or null for none.
     */
    public static function genderType(int $idGender): ?string
    {
        return $idGender > 0 ? Sql::value('SELECT `type` FROM `' . _DB_PREFIX_ . 'gender` WHERE `id_gender` = ' . $idGender) : null;
    }

    /**
     * A customer group's name in the shop's default language; group 0 is the shop's guest group.
     */
    public static function groupName(int $idGroup, int $idShop): ?string
    {
        $idGroup = $idGroup > 0 ? $idGroup : (int) \Configuration::get('PS_GUEST_GROUP', null, null, $idShop);
        $idLang = (int) \Configuration::get('PS_LANG_DEFAULT', null, null, $idShop);

        return $idGroup > 0 ? Sql::value(sprintf(
            'SELECT `name` FROM `%sgroup_lang` WHERE `id_group` = %d AND `id_lang` = %d',
            _DB_PREFIX_,
            $idGroup,
            $idLang
        )) : null;
    }

    /**
     * @param string $language the contact's ISO language code, or ''
     * @param list<string> $fields the selected fields, by Smaily name; others are ignored
     * @param array<string, mixed> $customer the contact's `customer` columns; empty for a
     *                                       newsletter-block subscriber without an account
     * @param bool|null $isUnsubscribed the status ContactAudience gives, or null for none
     *
     * @return array<string, string|int>
     */
    public function build(string $email, int $idShop, string $language, array $fields, array $customer, ?bool $isUnsubscribed): array
    {
        $contact = ['email' => strtolower(trim($email))];
        if ($isUnsubscribed !== null) {
            $contact['is_unsubscribed'] = $isUnsubscribed ? 1 : 0;
        }
        $contact['store'] = trim((string) ($this->shopDomain)($idShop));
        $contact['language'] = $language;
        foreach (self::COLUMNS as $field => $column) {
            if (in_array($field, $fields, true)) {
                $contact[$field] = trim((string) ($customer[$column] ?? ''));
            }
        }
        if (in_array('user_gender', $fields, true) && $customer !== []) {
            $contact['user_gender'] = self::GENDERS[(string) ($this->genderType)((int) ($customer['id_gender'] ?? 0))] ?? '';
        }
        $group = $customer === [] ? 0 : (int) ($customer['id_default_group'] ?? 0);
        if (in_array('customer_group', $fields, true) && ($customer === [] || $group > 0)) {
            $contact['customer_group'] = trim((string) ($this->groupName)($group, $idShop));
        }
        if (($contact['customer_id'] ?? '') === '0') {
            $contact['customer_id'] = '';
        }
        if (($contact['birthday'] ?? '') === self::NO_BIRTHDAY) {
            $contact['birthday'] = '';
        }

        return array_filter($contact, static fn ($value): bool => $value !== '');
    }
}
