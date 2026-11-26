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

namespace PrestaShop\Module\SmailyForPrestaShop\Contacts\Import;

if (!defined('_PS_VERSION_')) {
    exit;
}

use PrestaShop\Module\SmailyForPrestaShop\Contacts\ContactAudience;
use PrestaShop\Module\SmailyForPrestaShop\Database\Sql;

/**
 * The store's contact records that an import walks, in the order of their primary keys: first the
 * customers that are not deleted (guest-checkout customers included), then the active
 * newsletter-block rows (`emailsubscription`). Whether a record is in the audience is not decided
 * here: ContactImport asks ContactAudience about each one.
 *
 * A cursor names the source and the last id read, as "customer:<id_customer>" or
 * "newsletter_block:<id>"; no cursor is the start.
 *
 * A record: `source` (a ContactAudience source), `id`, `id_shop`, `id_lang`, `email`, `newsletter`
 * (`1` for a newsletter-block row), `is_guest` and `customer` (the customer's columns that
 * SubscriberPayloadBuilder reads; empty for a newsletter-block row).
 */
final class ContactSource
{
    private const NEWSLETTER_TABLE = 'emailsubscription';

    /**
     * The next records after the cursor in one shop (0 = every shop), and the cursor after them; no
     * records and a null cursor at the end.
     *
     * @return array{0: list<array<string, mixed>>, 1: string|null}
     */
    public function page(?string $cursor, int $idShop, int $limit): array
    {
        [$source, $afterId] = self::parse($cursor);
        if ($source === ContactAudience::SOURCE_CUSTOMER) {
            $rows = $this->customers($afterId, $idShop, $limit);
            if ($rows !== []) {
                return [$rows, ContactAudience::SOURCE_CUSTOMER . ':' . end($rows)['id']];
            }
            $afterId = 0;
        }

        $rows = self::hasNewsletterTable() ? $this->newsletterRows($afterId, $idShop, $limit) : [];

        return $rows === [] ? [[], null] : [$rows, ContactAudience::SOURCE_NEWSLETTER_BLOCK . ':' . end($rows)['id']];
    }

    /**
     * How many records of each kind one shop (0 = every shop) holds, for the import's total.
     *
     * @return list<array{source: string, id_shop: int, newsletter: int, is_guest: int, count: int}>
     */
    public function groups(int $idShop): array
    {
        $groups = [];
        $rows = Sql::rows(
            'SELECT `id_shop`, `newsletter`, `is_guest`, COUNT(*) AS `count` FROM `' . _DB_PREFIX_ . 'customer`'
            . ' WHERE `deleted` = 0' . self::shopFilter($idShop)
            . ' GROUP BY `id_shop`, `newsletter`, `is_guest`'
        );
        foreach ($rows as $row) {
            $groups[] = ['source' => ContactAudience::SOURCE_CUSTOMER] + self::ints($row);
        }
        if (self::hasNewsletterTable()) {
            $rows = Sql::rows(
                'SELECT `id_shop`, 1 AS `newsletter`, 0 AS `is_guest`, COUNT(*) AS `count` FROM `' . _DB_PREFIX_ . self::NEWSLETTER_TABLE . '`'
                . ' WHERE `active` = 1' . self::shopFilter($idShop)
                . ' GROUP BY `id_shop`'
            );
            foreach ($rows as $row) {
                $groups[] = ['source' => ContactAudience::SOURCE_NEWSLETTER_BLOCK] + self::ints($row);
            }
        }

        return $groups;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function customers(int $afterId, int $idShop, int $limit): array
    {
        $rows = Sql::rows(
            'SELECT `id_customer`, `id_shop`, `id_lang`, `email`, `newsletter`, `is_guest`, `firstname`, `lastname`, `birthday`, `website`, `id_gender`, `id_default_group`'
            . ' FROM `' . _DB_PREFIX_ . 'customer`'
            . ' WHERE `id_customer` > ' . $afterId . ' AND `deleted` = 0' . self::shopFilter($idShop)
            . ' ORDER BY `id_customer` LIMIT ' . $limit
        );

        return array_map(static fn (array $row): array => [
            'source' => ContactAudience::SOURCE_CUSTOMER,
            'id' => (int) $row['id_customer'],
            'id_shop' => (int) $row['id_shop'],
            'id_lang' => (int) $row['id_lang'],
            'email' => (string) $row['email'],
            'newsletter' => (int) $row['newsletter'],
            'is_guest' => (int) $row['is_guest'],
            'customer' => [
                'firstname' => $row['firstname'],
                'lastname' => $row['lastname'],
                'birthday' => $row['birthday'],
                'website' => $row['website'],
                'id_customer' => (int) $row['id_customer'],
                'id_gender' => (int) $row['id_gender'],
                'id_default_group' => (int) $row['id_default_group'],
            ],
        ], $rows);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function newsletterRows(int $afterId, int $idShop, int $limit): array
    {
        $rows = Sql::rows(
            'SELECT `id`, `id_shop`, `id_lang`, `email` FROM `' . _DB_PREFIX_ . self::NEWSLETTER_TABLE . '`'
            . ' WHERE `id` > ' . $afterId . ' AND `active` = 1' . self::shopFilter($idShop)
            . ' ORDER BY `id` LIMIT ' . $limit
        );

        return array_map(static fn (array $row): array => [
            'source' => ContactAudience::SOURCE_NEWSLETTER_BLOCK,
            'id' => (int) $row['id'],
            'id_shop' => (int) $row['id_shop'],
            'id_lang' => (int) $row['id_lang'],
            'email' => (string) $row['email'],
            'newsletter' => 1,
            'is_guest' => 0,
            'customer' => [],
        ], $rows);
    }

    /**
     * @return array{0: string, 1: int}
     */
    private static function parse(?string $cursor): array
    {
        $parts = explode(':', (string) $cursor, 2);
        if (count($parts) === 2 && in_array($parts[0], ContactAudience::SOURCES, true)) {
            return [$parts[0], (int) $parts[1]];
        }

        return [ContactAudience::SOURCE_CUSTOMER, 0];
    }

    private static function shopFilter(int $idShop): string
    {
        return $idShop > 0 ? ' AND `id_shop` = ' . $idShop : '';
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array{id_shop: int, newsletter: int, is_guest: int, count: int}
     */
    private static function ints(array $row): array
    {
        return [
            'id_shop' => (int) $row['id_shop'],
            'newsletter' => (int) $row['newsletter'],
            'is_guest' => (int) $row['is_guest'],
            'count' => (int) $row['count'],
        ];
    }

    private static function hasNewsletterTable(): bool
    {
        return Sql::rows('SHOW TABLES LIKE \'' . _DB_PREFIX_ . self::NEWSLETTER_TABLE . '\'') !== [];
    }
}
