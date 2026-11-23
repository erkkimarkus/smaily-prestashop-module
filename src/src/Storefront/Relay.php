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

namespace PrestaShop\Module\SmailyForPrestaShop\Storefront;

if (!defined('_PS_VERSION_')) {
    exit;
}

use PrestaShop\Module\SmailyForPrestaShop\Engine\Client;
use PrestaShop\Module\SmailyForPrestaShop\Engine\Connection;
use PrestaShop\Module\SmailyForPrestaShop\Engine\Exception\EngineException;

/**
 * The browse relay (front controller `relay`): the storefront's tracker posts its event batches
 * here, and the store forwards them to the engine (contract §6) with the connection's key, which so
 * never reaches a browser. As the Magento connector's Controller\Relay\Index.
 *
 * A request is answered, in this order:
 *
 * - 405 unless it is a POST;
 * - 404 while the shop has no connection that the engine takes;
 * - 429 beyond LIMIT_PER_MINUTE requests of one network address in a minute;
 * - 400 without a JSON body that holds an `events` list, when an event names the shopper
 *   (`identity_not_accepted`: nothing of the batch is forwarded), or when no event is valid
 *   (BrowseEvents);
 * - 200 with the number of events forwarded.
 *
 * What is forwarded is what §6 defines and nothing else: the cleaned events, each with the visitor
 * token of the request's own cookie when there is one that may be sent (Attribution::read()), and
 * with the engine's key of its product when the store has one for it (the product key below).
 * Without a token the events are anonymous.
 *
 * Browse events are loss-tolerant: the forward is one attempt of 3 seconds, a failure is neither
 * retried nor queued nor logged, and the answer is 200 all the same.
 */
final class Relay
{
    public const LIMIT_PER_MINUTE = 30;

    private ActiveConnection $connections;

    private RateLimiter $limiter;

    private Attribution $attribution;

    private Client $client;

    /** @var callable(Connection, int, int): ?string */
    private $productKey;

    /** @var callable(): int */
    private $clock;

    /**
     * @param callable(Connection, int, int): ?string $productKey the engine's `sku` of a product
     *                                                            and its combination (0 for none)
     *                                                            on a connection, or null
     * @param callable(): int $clock the current Unix time
     */
    public function __construct(ActiveConnection $connections, RateLimiter $limiter, Attribution $attribution, Client $client, callable $productKey, callable $clock)
    {
        $this->connections = $connections;
        $this->limiter = $limiter;
        $this->attribution = $attribution;
        $this->client = $client;
        $this->productKey = $productKey;
        $this->clock = $clock;
    }

    /**
     * @param string $body the request body
     *
     * @return array{int, array<string, mixed>} the HTTP status and the JSON answer
     */
    public function handle(int $idShop, string $method, string $body, Cookies $cookies): array
    {
        if ($method !== 'POST') {
            return [405, ['ok' => false]];
        }
        $connection = $this->connections->sending($idShop);
        if ($connection === null) {
            return [404, ['ok' => false]];
        }
        if (!$this->limiter->allow('relay', self::LIMIT_PER_MINUTE)) {
            return [429, ['ok' => false, 'error' => 'rate_limited']];
        }

        $posted = json_decode($body, true);
        $raw = is_array($posted) ? ($posted['events'] ?? null) : null;
        if (!is_array($raw) || $raw === []) {
            return [400, ['ok' => false, 'error' => 'events_required']];
        }
        if (BrowseEvents::nameIdentity($raw)) {
            return [400, ['ok' => false, 'error' => 'identity_not_accepted']];
        }

        $now = gmdate('Y-m-d\TH:i:s\Z', ($this->clock)());
        $token = $this->attribution->read($cookies, $connection)['visitor_token'];
        $events = [];
        foreach (array_slice(array_values($raw), 0, BrowseEvents::MAX_EVENTS) as $item) {
            $clean = is_array($item) ? BrowseEvents::clean($item, $now) : null;
            if ($clean === null) {
                continue;
            }
            $event = $clean['event'];
            $sku = $clean['product'] === null ? null : ($this->productKey)($connection, $clean['product'][0], $clean['product'][1]);
            if (is_string($sku) && $sku !== '') {
                $event['sku'] = $sku;
            }
            if ($token !== null) {
                $event['smaily_visitor_token'] = $token;
            }
            $events[] = $event;
        }
        if ($events === []) {
            return [400, ['ok' => false, 'error' => 'no_valid_events']];
        }

        try {
            $this->client->relayBrowse($connection, $events);
        } catch (EngineException $failure) {
            // Loss-tolerant by design: storefront traffic must not fill the log while the engine is away.
        }

        return [200, ['ok' => true, 'accepted' => count($events)]];
    }
}
