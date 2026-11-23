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

use PrestaShop\Module\SmailyForPrestaShop\Engine\Connections;
use PrestaShop\Module\SmailyForPrestaShop\Engine\Ingest\CustomerScope;
use PrestaShop\Module\SmailyForPrestaShop\Queue\ContactEntity;
use PrestaShop\Module\SmailyForPrestaShop\Queue\EventQueue;

/**
 * Queues the identity merge (contract §7) when a shopper becomes known in the storefront: at a
 * sign-in (actionAuthentication), and at a registration or a guest checkout
 * (actionCustomerAccountAdd), where PrestaShop signs the shopper in without that hook (facts file
 * part 2). The queue's handler (Engine\Ingest\IdentityMergeHandler) sends it, so a sign-in never
 * waits for the engine.
 *
 * One row of the event queue carries both things that the contract ties to that moment:
 *  - the customer of group (c): a registered customer who signs in or registers in a shop whose
 *    merchant confirmed that the cookie banner covers Smaily tracking
 *    (CustomerScope::consents(); the customer's `newsletter` is not asked) is sent first
 *    (`send_customer`), also when the customer never bought and is not in the contact sync;
 *  - the merge itself, when the request has a visitor token (VisitorToken). The token is the
 *    storefront's; nothing here reads a cookie or creates a token. A token of the store (`vs_…`)
 *    is sent only under that same confirmation.
 * A sign-in with neither queues nothing.
 *
 * Only the shopper's own storefront request counts: an employee who creates or changes a customer
 * is not that shopper. Nothing is queued where the shop has no stored connection. The row names
 * the customer by id; its address is read when it is sent.
 */
final class IdentityCapture
{
    public const EVENT_TYPE = 'engine.identity_merge';

    /** The contract's `merge_reason` of a sign-in and of a registration. */
    public const REASON_SIGNED_IN = 'user_logged_in';

    /** The contract's `merge_reason` of a guest who gave an address at the checkout. */
    public const REASON_CHECKOUT = 'email_provided_at_checkout';

    /** An engine token (`vt_`) or a token of the store (`vs_`), contract "ID formats". */
    public const VISITOR_TOKEN = '/^(?:vt_[A-Za-z0-9]{1,61}|vs_[A-Za-z0-9]{22})$/D';

    private Connections $connections;

    private CustomerScope $scope;

    private VisitorToken $visitor;

    private EventQueue $queue;

    /** @var callable(): int */
    private $now;

    /**
     * @param callable(): int $now the current Unix time
     */
    public function __construct(Connections $connections, CustomerScope $scope, VisitorToken $visitor, EventQueue $queue, callable $now)
    {
        $this->connections = $connections;
        $this->scope = $scope;
        $this->visitor = $visitor;
        $this->queue = $queue;
        $this->now = $now;
    }

    /**
     * actionAuthentication: a sign-in.
     *
     * @param array<string, mixed> $params
     */
    public function signedIn(array $params): void
    {
        $customer = $params['customer'] ?? null;
        if ($customer instanceof \Customer) {
            $this->identified((int) $customer->id, self::REASON_SIGNED_IN);
        }
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
            $this->identified((int) $customer->id, (int) $customer->is_guest === 1 ? self::REASON_CHECKOUT : self::REASON_SIGNED_IN);
        }
    }

    private function identified(int $idCustomer, string $reason): void
    {
        $context = \Context::getContext();
        if (!$context->controller instanceof \FrontController) {
            return;
        }
        $idShop = (int) $context->shop->id;
        $connection = $this->connections->forShop($idShop);
        if ($connection === null) {
            return;
        }
        $customer = $this->scope->customer($idCustomer);
        if ($customer === null) {
            return;
        }

        $consents = $this->scope->consents($connection, $customer);
        $token = $this->visitor->token($idShop, $consents);
        if ($token !== null && (preg_match(self::VISITOR_TOKEN, $token) !== 1 || (!$consents && strpos($token, 'vs_') === 0))) {
            $token = null;
        }
        if (!$consents && $token === null) {
            return;
        }

        $payload = ['id_customer' => $idCustomer, 'merge_reason' => $reason, 'merge_ts' => gmdate('Y-m-d\TH:i:s\Z', ($this->now)())];
        if ($token !== null) {
            $payload['visitor_token'] = $token;
        }
        if ($consents) {
            $payload['send_customer'] = true;
        }
        $language = $customer['id_lang'] > 0 ? \Language::getIsoById($customer['id_lang']) : false;
        $this->queue->enqueue(
            self::EVENT_TYPE,
            $idShop,
            $payload,
            ContactEntity::of($customer['email']),
            is_string($language) && $language !== '' ? strtolower($language) : null
        );
    }
}
