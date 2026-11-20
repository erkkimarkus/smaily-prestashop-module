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

namespace PrestaShop\Module\SmailyForPrestaShop\Automation\FirstOrder;

if (!defined('_PS_VERSION_')) {
    exit;
}

use PrestaShop\Module\SmailyForPrestaShop\Automation\AbandonedCart\CartAddress;
use PrestaShop\Module\SmailyForPrestaShop\Automation\MarketingConsent;
use PrestaShop\Module\SmailyForPrestaShop\Automation\Router;
use PrestaShop\Module\SmailyForPrestaShop\Automation\Trigger;
use PrestaShop\Module\SmailyForPrestaShop\Queue\ExchangeSource;
use PrestaShop\Module\SmailyForPrestaShop\Queue\Failure;
use PrestaShop\Module\SmailyForPrestaShop\Queue\Handler\AutomationHandler;
use PrestaShop\Module\SmailyForPrestaShop\Queue\HandlerInterface;
use PrestaShop\Module\SmailyForPrestaShop\Queue\Skipped;
use PrestaShop\Module\SmailyForPrestaShop\Smaily\Exception\SmailyClientException;

/**
 * Sends the `automation.first_order` rows, each after the consent check of the abandoned-cart
 * reminder (owner, 2026-11-20): the router's workflow and account (its reasons), then
 * MarketingConsent through that account: `unsubscribed_in_smaily`, `no_marketing_consent`, or a
 * failed read that sends nothing and leaves the row for a retry. The store's consent is the order's
 * customer's `newsletter = 1`, an active newsletter-block row of the address, and the box ticked in
 * the checkout of the order's cart (CartAddress). A row that passes goes to AutomationHandler.
 */
final class FirstOrderHandler implements HandlerInterface, ExchangeSource
{
    private AutomationHandler $rows;

    private Router $router;

    private CartAddress $addresses;

    private MarketingConsent $consent;

    /** @var array<int, array{request: array<int|string, mixed>, response: array{http_status: int, body: mixed}|null}> */
    private array $exchanges = [];

    public function __construct(AutomationHandler $rows, Router $router, CartAddress $addresses, MarketingConsent $consent)
    {
        $this->rows = $rows;
        $this->router = $router;
        $this->addresses = $addresses;
        $this->consent = $consent;
    }

    public function handle(array $events): array
    {
        $this->exchanges = [];
        $outcomes = [];
        foreach ($events as $event) {
            $id = (int) $event['id'];
            $outcome = $this->check($event);
            if ($outcome === null) {
                $outcome = $this->rows->handle([$event])[$id];
                $this->exchanges += $this->rows->exchanges();
            }
            $outcomes[$id] = $outcome;
        }

        return $outcomes;
    }

    public function exchanges(): array
    {
        return $this->exchanges;
    }

    /**
     * The row's outcome when the check stops it; null when it goes on to the send, also a row too
     * malformed to check, which the send fails.
     *
     * @param array<string, mixed> $event
     *
     * @return Skipped|Failure|null
     */
    private function check(array $event)
    {
        $payload = json_decode((string) $event['payload'], true);
        if (!is_array($payload) || !is_string($payload['email'] ?? null) || trim($payload['email']) === '') {
            return null;
        }
        $idShop = (int) $event['id_shop'];
        $match = $this->router->route(Trigger::FIRST_ORDER, $idShop, (string) ($event['language'] ?? ''));
        if ($match instanceof Skipped) {
            return $match;
        }
        $address = is_int($payload['id_cart'] ?? null) ? $this->addresses->read($payload['id_cart']) : null;
        try {
            $refusal = $this->consent->refusal(
                $match->account(),
                $payload['email'],
                $idShop,
                $address !== null && $address['newsletter'],
                $address !== null && $address['box_ticked']
            );
        } catch (SmailyClientException $error) {
            return Failure::of($error);
        }

        return $refusal !== null ? new Skipped($refusal) : null;
    }
}
