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

namespace PrestaShop\Module\SmailyForPrestaShop\Automation;

if (!defined('_PS_VERSION_')) {
    exit;
}

use PrestaShop\Module\SmailyForPrestaShop\Http\Transport;
use PrestaShop\Module\SmailyForPrestaShop\Queue\Failure;
use PrestaShop\Module\SmailyForPrestaShop\Queue\Skipped;
use PrestaShop\Module\SmailyForPrestaShop\Smaily\Client;
use PrestaShop\Module\SmailyForPrestaShop\Smaily\Exception\SmailyClientException;

/**
 * Posts one automation trigger to Smaily for one contact, as a queue handler sends one row: the
 * router picks the workflow and the account, and the trigger goes to `autoresponder.php` through
 * that account's credentials in 2.x's request shapes:
 *   {"autoresponder": <workflow id>, "addresses": [<address>], "force_opt_in": false}, or, where the
 *   welcome keeps 2.x's generic opt-in trigger, {"addresses": [<address>], "force_opt_in": false}.
 *
 * `force_opt_in` is always false, also where the address carries it as 2.x's abandoned-cart address
 * did: an automation never re-subscribes a contact who unsubscribed in Smaily (the rule of every
 * Smaily connector). The address is `email` and the caller's fields as
 * they are; where the shop's workflow branches on the contact's language (mode `c`), it also
 * carries `language`. The caller adds the trigger's marker field (Trigger::marker()) when the event
 * is queued, as the sibling connectors do.
 *
 * lastExchange() holds the request body of the last send() and the answer to it, for the Log's
 * Details; null when that send posted nothing.
 */
final class TriggerSender
{
    private Router $router;

    private Transport $transport;

    /** @var array{request: array<int|string, mixed>, response: array{http_status: int, body: mixed}|null}|null */
    private ?array $lastExchange = null;

    public function __construct(Router $router, Transport $transport)
    {
        $this->router = $router;
        $this->transport = $transport;
    }

    /**
     * @param int $idShop the shop of the contact's record (Scope::idShop())
     * @param string $language the contact's two-letter ISO code (Scope::language()), '' when unknown
     * @param array<string, mixed> $fields the address fields besides `email`
     *
     * @return true|Skipped|Failure true when Smaily took the trigger; a skip with the router's reason
     *                              when nothing is sent; else the queue's failure of the send
     */
    public function send(string $trigger, int $idShop, string $language, string $email, array $fields)
    {
        $this->lastExchange = null;
        $match = $this->router->route($trigger, $idShop, $language);
        if ($match instanceof Skipped) {
            return $match;
        }

        $address = ['email' => $email] + $fields;
        if (array_key_exists('force_opt_in', $address)) {
            // 2.x's abandoned-cart address carried it too.
            $address['force_opt_in'] = false;
        }
        if ($match->branchesOnLanguage() && $match->language() !== '' && !isset($address['language'])) {
            $address['language'] = $match->language();
        }
        $payload = $match->isOptInTrigger() ? [] : ['autoresponder' => $match->workflowId()];
        $payload['addresses'] = [$address];
        $payload['force_opt_in'] = false;

        $account = $match->account();
        $client = new Client($this->transport, $account->subdomain(), $account->username(), $account->password());
        try {
            $client->post(Client::ENDPOINT_AUTORESPONDER, $payload);
        } catch (SmailyClientException $error) {
            return Failure::of($error);
        } finally {
            $this->lastExchange = $client->lastExchange();
        }

        return true;
    }

    /**
     * @return array{request: array<int|string, mixed>, response: array{http_status: int, body: mixed}|null}|null
     */
    public function lastExchange(): ?array
    {
        return $this->lastExchange;
    }
}
