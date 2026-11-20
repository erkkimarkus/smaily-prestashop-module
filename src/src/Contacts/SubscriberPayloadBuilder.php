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

/**
 * The one shape of a contact row for Smaily's `contact.php`.
 *
 * A row carries `email`, `store` (the domain of the contact's shop, as 2.x sent it), `language` (the
 * ISO code of the contact's language) and the merchant's selected fields, with the field names 2.x
 * sent. `is_unsubscribed` is there only when ContactAudience gives a status. An empty value is left
 * out: Smaily keeps a field that is absent and clears one that is empty.
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
    ];

    /** The Smaily names of the fields a merchant can select: the keys of COLUMNS. */
    public const FIELDS = ['first_name', 'last_name', 'birthday', 'website'];

    /** PrestaShop's value of a birthday that was never entered. */
    private const NO_BIRTHDAY = '0000-00-00';

    /** @var callable(int): ?string */
    private $shopDomain;

    /**
     * @param callable(int): ?string $shopDomain the domain of a shop's main URL, as
     *                                           ShopUrl::getMainShopDomain() reads it
     */
    public function __construct(callable $shopDomain)
    {
        $this->shopDomain = $shopDomain;
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
        if (($contact['birthday'] ?? '') === self::NO_BIRTHDAY) {
            $contact['birthday'] = '';
        }

        return array_filter($contact, static fn ($value): bool => $value !== '');
    }
}
