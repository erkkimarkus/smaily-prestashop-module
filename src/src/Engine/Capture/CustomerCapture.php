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

namespace PrestaShop\Module\SmailyForPrestaShop\Engine\Capture;

if (!defined('_PS_VERSION_')) {
    exit;
}

use PrestaShop\Module\SmailyForPrestaShop\Database\Sql;
use PrestaShop\Module\SmailyForPrestaShop\Engine\Connections;
use PrestaShop\Module\SmailyForPrestaShop\Engine\Ingest\CustomerScope;
use PrestaShop\Module\SmailyForPrestaShop\Engine\Ingest\IngestQueue;

/**
 * Notes the customers that Campaign Intelligence gets by group (a) or (b) of its customer scope
 * (Engine\Ingest\CustomerScope): a new account or a guest at the order step, a saved customer, a
 * buyer of a new order, and a customer who subscribed through the newsletter block.
 *
 * A customer is noted only for a stored connection, and only while the scope holds the customer
 * for it: the connection of the customer's own shop and, for an order, the connection of the
 * order's shop. A customer outside the scope is not queued; nothing removes a customer whom the
 * engine already has. The flush asks the scope again and reads the customer then.
 *
 * Group (c), a customer who signs in with marketing consent, is IdentityCapture's.
 */
final class CustomerCapture
{
    private Connections $connections;

    private CustomerScope $scope;

    private IngestMarkers $markers;

    public function __construct(Connections $connections, CustomerScope $scope, IngestMarkers $markers)
    {
        $this->connections = $connections;
        $this->scope = $scope;
        $this->markers = $markers;
    }

    /**
     * actionCustomerAccountAdd: a registration, or a guest at the order step.
     *
     * @param array<string, mixed> $params
     */
    public function accountAdded(array $params): void
    {
        $customer = $params['newCustomer'] ?? null;
        if ($customer instanceof \Customer) {
            $this->consider((int) $customer->id);
        }
    }

    /**
     * actionObjectCustomerUpdateAfter: an account edit, the admin form, the admin grid's switch.
     *
     * @param array<string, mixed> $params
     */
    public function customerSaved(array $params): void
    {
        $customer = $params['object'] ?? null;
        if ($customer instanceof \Customer) {
            $this->consider((int) $customer->id);
        }
    }

    /**
     * actionValidateOrder: the buyer has an order now.
     *
     * @param array<string, mixed> $params
     */
    public function orderValidated(array $params): void
    {
        $order = $params['order'] ?? null;
        if ($order instanceof \Order) {
            $this->consider((int) $order->id_customer, (int) $order->id_shop);
        }
    }

    /**
     * actionNewsletterRegistrationAfter: the newsletter block subscribed an address; where it is a
     * customer's, the block set `customer.newsletter` with plain SQL, and no customer hook fired.
     *
     * @param array<string, mixed> $params
     */
    public function newsletterSubscribed(array $params): void
    {
        $email = $params['email'] ?? null;
        if (!is_string($email) || trim($email) === '' || $this->connections->all() === []) {
            return;
        }
        $customers = Sql::rows(
            'SELECT `id_customer` FROM `' . _DB_PREFIX_ . 'customer` WHERE `email` = \'' . pSQL($email) . '\''
            . ' AND `id_shop` = ' . (int) \Context::getContext()->shop->id . ' AND `newsletter` = 1 AND `deleted` = 0'
        );
        foreach ($customers as $row) {
            $this->consider((int) $row['id_customer']);
        }
    }

    /**
     * @param int $idShop the shop of the event, when it is not the customer's own
     */
    private function consider(int $idCustomer, int $idShop = 0): void
    {
        if ($idCustomer <= 0 || $this->markers->has(IngestQueue::DOMAIN_CUSTOMERS, $idCustomer) || $this->connections->all() === []) {
            return;
        }
        $customer = $this->scope->customer($idCustomer);
        if ($customer === null) {
            return;
        }
        foreach (array_unique([$customer['id_shop'], $idShop > 0 ? $idShop : $customer['id_shop']]) as $shop) {
            $connection = $this->connections->forShop($shop);
            if ($connection !== null && $this->scope->includes($connection, $customer)) {
                $this->markers->add($connection, IngestQueue::DOMAIN_CUSTOMERS, $idCustomer);
            }
        }
    }
}
