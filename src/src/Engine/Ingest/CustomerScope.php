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

namespace PrestaShop\Module\SmailyForPrestaShop\Engine\Ingest;

if (!defined('_PS_VERSION_')) {
    exit;
}

use PrestaShop\Module\SmailyForPrestaShop\Config\ContactsSettings;
use PrestaShop\Module\SmailyForPrestaShop\Contacts\ContactAudience;
use PrestaShop\Module\SmailyForPrestaShop\Database\Sql;
use PrestaShop\Module\SmailyForPrestaShop\Engine\Connection;
use PrestaShop\Module\SmailyForPrestaShop\Queue\ContactEntity;

/**
 * Which customers Campaign Intelligence gets (contract §4 "Which customers to send"), and no
 * other:
 *
 *  (a) a customer whom the shop's own contact sync puts into Smaily: the sync is on and confirmed
 *      in the customer's shop, and the shop's consent mode sends the customer. The rule is
 *      Contacts\ContactAudience's, the one the first import and the daily refresh ask; there is
 *      no second audience rule here.
 *  (b) a registered customer with an order in one of the connection's shops inside the history
 *      horizon (`config.history.horizon_months`, 60 months when the connection names none),
 *      whatever the customer's marketing consent. A guest-checkout customer is not sent for an
 *      order: a guest buyer reaches the engine with the order, as the contract says.
 *  (c) a registered customer who signs in, or registers, with marketing consent
 *      (consents()). That customer is sent at that moment, before the identity merge
 *      (IdentityMergeHandler), and by no other path.
 *
 * Marketing consent of group (c) is the merchant's confirmation that the store's cookie banner
 * covers Smaily tracking (Config\EngineSettings::consentConfirmed()), and nothing of the customer
 * (owner, 2026-11-23): `customer.newsletter` decides the contact sync of group (a) alone.
 * `customer.optin` (partner offers) is never read. The engine stores no consent, and none is sent
 * to it.
 *
 * Not final: a unit test stands in for the stored customers and orders.
 */
class CustomerScope
{
    /** The horizon of a connection whose engine named none (contract §1 "History import"). */
    public const DEFAULT_HORIZON_MONTHS = 60;

    private ContactsSettings $contacts;

    /** @var callable(int): bool */
    private $trackingConfirmed;

    /** @var callable(): int */
    private $now;

    /**
     * @param callable(int): bool $trackingConfirmed whether the merchant confirmed the cookie banner
     *                                               for a connection's id_shop
     *                                               (Config\EngineSettings::consentConfirmed())
     * @param callable(): int $now the current Unix time
     */
    public function __construct(ContactsSettings $contacts, callable $trackingConfirmed, callable $now)
    {
        $this->contacts = $contacts;
        $this->trackingConfirmed = $trackingConfirmed;
        $this->now = $now;
    }

    /**
     * The stored customer, or null when the store holds none that can be sent: no row, a deleted
     * customer, or a row without an address.
     *
     * @return array{id_customer: int, id_shop: int, id_lang: int, email: string, firstname: string, lastname: string, newsletter: bool, is_guest: bool, date_add: string}|null
     */
    public function customer(int $idCustomer): ?array
    {
        $row = $idCustomer <= 0 ? null : Sql::row(
            'SELECT `id_customer`, `id_shop`, `id_lang`, `email`, `firstname`, `lastname`, `newsletter`, `is_guest`, `deleted`, `date_add`'
            . ' FROM `' . _DB_PREFIX_ . 'customer` WHERE `id_customer` = ' . $idCustomer
        );
        if ($row === null || (int) $row['deleted'] === 1 || trim((string) $row['email']) === '') {
            return null;
        }

        return [
            'id_customer' => (int) $row['id_customer'],
            'id_shop' => (int) $row['id_shop'],
            'id_lang' => (int) $row['id_lang'],
            'email' => ContactEntity::address((string) $row['email']),
            'firstname' => trim((string) $row['firstname']),
            'lastname' => trim((string) $row['lastname']),
            'newsletter' => (int) $row['newsletter'] === 1,
            'is_guest' => (int) $row['is_guest'] === 1,
            'date_add' => (string) $row['date_add'],
        ];
    }

    /**
     * Whether the connection sends the customer by group (a) or (b).
     *
     * @param array{id_customer: int, id_shop: int, newsletter: bool, is_guest: bool} $customer what customer() answered
     */
    public function includes(Connection $connection, array $customer): bool
    {
        $shops = ConnectionShops::of($connection);
        if (in_array($customer['id_shop'], $shops, true)
            && $this->contacts->syncActive($customer['id_shop'])
            && (new ContactAudience($this->contacts->mode($customer['id_shop'])))->importsCustomer($customer['newsletter'], $customer['is_guest'])
        ) {
            return true;
        }

        return !$customer['is_guest'] && $this->hasOrderSince($customer['id_customer'], $shops, $this->horizon($connection));
    }

    /**
     * Whether a customer who signs in or registers is sent and joined to the browsing: group (c),
     * asked at the sign-in and again at the send. The merchant's confirmation of the cookie banner
     * for the connection decides it alone; the customer's `newsletter` is not asked. A
     * guest-checkout customer has no account to sign in to.
     *
     * @param array{is_guest: bool} $customer what customer() answered
     */
    public function consents(Connection $connection, array $customer): bool
    {
        return !$customer['is_guest'] && ($this->trackingConfirmed)($connection->idShop());
    }

    /**
     * The customers of groups (a) and (b), for the customers import: up to $limit ids above
     * $afterId, lowest first. The rule is includes()'s, asked of the table at once; a customer
     * without an address is left out, as customer() leaves it out.
     *
     * @return list<int>
     */
    public function page(Connection $connection, int $afterId, int $limit): array
    {
        return array_map('intval', array_column(Sql::rows(
            'SELECT c.`id_customer` FROM `' . _DB_PREFIX_ . 'customer` c WHERE c.`id_customer` > ' . $afterId
            . ' AND ' . $this->condition($connection) . ' ORDER BY c.`id_customer` LIMIT ' . $limit
        ), 'id_customer'));
    }

    /** The number of customers of groups (a) and (b). */
    public function count(Connection $connection): int
    {
        return (int) Sql::value('SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'customer` c WHERE ' . $this->condition($connection));
    }

    /**
     * includes() as a condition on the customer table `c`: the audience of each shop's contact
     * sync as ContactAudience answers it for the four kinds of customer, or an order since the
     * horizon.
     */
    private function condition(Connection $connection): string
    {
        $shops = ConnectionShops::of($connection);
        $in = implode(', ', array_map('intval', $shops));
        $groups = ['(c.`is_guest` = 0 AND EXISTS (SELECT 1 FROM `' . _DB_PREFIX_ . 'orders` o WHERE o.`id_customer` = c.`id_customer`'
            . ' AND o.`id_shop` IN (' . $in . ') AND o.`date_add` >= \'' . pSQL($this->horizon($connection)) . '\'))'];
        foreach ($shops as $idShop) {
            if (!$this->contacts->syncActive($idShop)) {
                continue;
            }
            $audience = new ContactAudience($this->contacts->mode($idShop));
            foreach ([[true, true], [true, false], [false, true], [false, false]] as [$newsletter, $guest]) {
                if ($audience->importsCustomer($newsletter, $guest)) {
                    $groups[] = sprintf('(c.`id_shop` = %d AND c.`newsletter` = %d AND c.`is_guest` = %d)', $idShop, (int) $newsletter, (int) $guest);
                }
            }
        }

        return 'c.`deleted` = 0 AND TRIM(c.`email`) <> \'\' AND (' . implode(' OR ', $groups) . ')';
    }

    /**
     * The oldest order time inside the connection's history horizon, in the shop's time, as the
     * orders are stored.
     */
    public function horizon(Connection $connection): string
    {
        $months = $connection->historyMonths('horizon_months', self::DEFAULT_HORIZON_MONTHS);

        return date('Y-m-d H:i:s', (int) strtotime('-' . $months . ' months', ($this->now)()));
    }

    /**
     * @param list<int> $shops
     */
    protected function hasOrderSince(int $idCustomer, array $shops, string $since): bool
    {
        return Sql::value(
            'SELECT 1 FROM `' . _DB_PREFIX_ . 'orders` WHERE `id_customer` = ' . $idCustomer
            . ' AND `id_shop` IN (' . implode(', ', array_map('intval', $shops)) . ')'
            . ' AND `date_add` >= \'' . pSQL($since) . '\''
        ) !== null;
    }
}
