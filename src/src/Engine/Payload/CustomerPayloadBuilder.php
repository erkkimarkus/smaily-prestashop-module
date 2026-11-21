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

namespace PrestaShop\Module\SmailyForPrestaShop\Engine\Payload;

if (!defined('_PS_VERSION_')) {
    exit;
}

use PrestaShop\Module\SmailyForPrestaShop\Database\Sql;
use PrestaShop\Module\SmailyForPrestaShop\Engine\Connection;
use PrestaShop\Module\SmailyForPrestaShop\Engine\Ingest\ConnectionShops;

/**
 * A customer as the customers row of a connection (contract §4), with the fields that the Magento
 * and WooCommerce connectors send and no other: `email` (lower case, the engine's identity),
 * `external_id` (the customer id, without a prefix), `first_name` and `last_name`, `country` and
 * `phone` of the customer's first address, `language` and `first_seen_at`, each only when the
 * store holds a value, and `website` (the customer's shop) for a connection that serves two or
 * more shops.
 *
 * No consent field is sent: the engine stores no consent (contract §4 "Note on consent"). No
 * price group is sent (owner, 2026-11-21). Whether a customer is sent at all is
 * Engine\Ingest\CustomerScope's question, never this builder's.
 */
class CustomerPayloadBuilder
{
    /**
     * @param array{id_customer: int, id_shop: int, id_lang: int, email: string, firstname: string, lastname: string, date_add: string} $customer what CustomerScope::customer() answered
     *
     * @return array<string, string>
     */
    public function row(Connection $connection, array $customer): array
    {
        $row = ['email' => $customer['email']];
        if ($customer['firstname'] !== '') {
            $row['first_name'] = $customer['firstname'];
        }
        if ($customer['lastname'] !== '') {
            $row['last_name'] = $customer['lastname'];
        }
        $address = $this->firstAddress($customer['id_customer']);
        if ($address['country'] !== '') {
            $row['country'] = $address['country'];
        }
        $language = $customer['id_lang'] > 0 ? \Language::getIsoById($customer['id_lang']) : false;
        if (is_string($language) && $language !== '') {
            $row['language'] = strtolower($language);
        }
        if ($address['phone'] !== '') {
            $row['phone'] = $address['phone'];
        }
        // PrestaShop stores the time in the shop's time zone, which is PHP's.
        $registered = strtotime($customer['date_add']);
        if ($registered !== false && $registered > 0) {
            $row['first_seen_at'] = gmdate('Y-m-d\TH:i:s\Z', $registered);
        }
        $row['external_id'] = (string) $customer['id_customer'];
        $website = in_array($customer['id_shop'], ConnectionShops::of($connection), true) ? ConnectionShops::website($connection, $customer['id_shop']) : null;
        if ($website !== null) {
            $row['website'] = $website;
        }

        return $row;
    }

    /**
     * The country (ISO 3166-1 alpha-2) and the phone of the customer's first address that is in
     * use, each '' when the address holds none.
     *
     * @return array{country: string, phone: string}
     */
    protected function firstAddress(int $idCustomer): array
    {
        $address = Sql::row(
            'SELECT a.`phone`, a.`phone_mobile`, c.`iso_code` FROM `' . _DB_PREFIX_ . 'address` a'
            . ' LEFT JOIN `' . _DB_PREFIX_ . 'country` c ON c.`id_country` = a.`id_country`'
            . ' WHERE a.`id_customer` = ' . $idCustomer . ' AND a.`deleted` = 0 AND a.`active` = 1 ORDER BY a.`id_address`'
        );
        $country = strtoupper(trim((string) ($address['iso_code'] ?? '')));
        $phone = trim((string) ($address['phone'] ?? ''));

        return [
            'country' => preg_match('/^[A-Z]{2}$/D', $country) === 1 ? $country : '',
            'phone' => $phone !== '' ? $phone : trim((string) ($address['phone_mobile'] ?? '')),
        ];
    }
}
