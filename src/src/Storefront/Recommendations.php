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
use PrestaShop\Module\SmailyForPrestaShop\Engine\Exception\EngineTransportException;

/**
 * A shopper's current recommendations from the engine (contract §15: the same products as in the
 * shopper's Smaily contact fields), for the recommendations block. As the Magento connector's
 * StorefrontRecommendations.
 *
 * - The request names the shopper by the store's customer id or by the visitor token, never by an
 *   address or a name, and asks for LIMIT products. It is one attempt of at most 10 seconds.
 * - The answer, an empty one too (§15: "do not retry"), is kept for an hour in the store's own
 *   cache (Store), under a hash of the connection, the engine store, the shop and the identifier.
 * - A failure is kept as an empty answer for 10 minutes. No answer at all (a timeout, a network
 *   failure) and an engine error (5xx) also pause every shopper's request of that connection for
 *   2 minutes, so an engine that hangs cannot hold one PHP worker per visitor.
 * - A slot is usable with a well-formed recommendation id and an `external_id`, the store's own
 *   product id that the catalog sends. On a connection with a code prefix a slot is this
 *   connection's only when its `sku` starts with the prefix: another names a product of another
 *   shop of the same engine store (§15, v1.33.0).
 */
final class Recommendations
{
    /** Products per block, as in the WooCommerce, Magento and Shopify connectors. */
    public const LIMIT = 4;

    public const BY_CUSTOMER = 'customer_external_id';

    public const BY_VISITOR_TOKEN = 'smaily_visitor_token';

    private const CACHE_SECONDS = 3600;

    private const FAILURE_SECONDS = 600;

    private const PAUSE_SECONDS = 120;

    private Client $client;

    private Store $store;

    private string $secret;

    /**
     * @param string $secret the key of the hash under which an answer is kept
     */
    public function __construct(Client $client, Store $store, string $secret)
    {
        $this->client = $client;
        $this->store = $store;
        $this->secret = $secret;
    }

    /**
     * The shopper's recommendations in the engine's order; [] whenever there are none, the engine
     * fails or is paused.
     *
     * @param string $by BY_CUSTOMER or BY_VISITOR_TOKEN
     * @param string $identifier the customer id or the visitor token
     *
     * @return list<array{rec_id: string, external_id: string}>
     */
    public function slots(Connection $connection, int $idShop, string $by, string $identifier): array
    {
        $scope = $connection->id() . '|' . $connection->tenantId();
        $key = 'recs:' . hash_hmac('sha256', $scope . '|' . $idShop . '|' . $by . '|' . $identifier, $this->secret);
        $cached = $this->store->get($key);
        if ($cached !== null) {
            $slots = json_decode($cached, true);

            return is_array($slots) ? $slots : [];
        }
        $pause = 'recs-paused:' . $scope;
        if ($this->store->get($pause) !== null) {
            return [];
        }

        try {
            $answer = $this->client->recommendations($connection, [$by => $identifier], self::LIMIT);
        } catch (EngineException $failure) {
            $this->store->set($key, '[]', self::FAILURE_SECONDS);
            if ($failure instanceof EngineTransportException && ($failure->httpStatus() === 0 || $failure->httpStatus() >= 500)) {
                $this->store->set($pause, '1', self::PAUSE_SECONDS);
            }

            return [];
        }

        $slots = self::usable($answer, $connection->prefix());
        $this->store->set($key, (string) json_encode($slots), self::CACHE_SECONDS);

        return $slots;
    }

    /**
     * @param array<string, mixed> $answer the §15 answer
     *
     * @return list<array{rec_id: string, external_id: string}>
     */
    private static function usable(array $answer, string $prefix): array
    {
        $usable = [];
        foreach (is_array($answer['slots'] ?? null) ? $answer['slots'] : [] as $slot) {
            if (!is_array($slot)) {
                continue;
            }
            $recId = is_string($slot['rec_id'] ?? null) ? $slot['rec_id'] : '';
            $externalId = is_scalar($slot['external_id'] ?? null) ? trim((string) $slot['external_id']) : '';
            $sku = is_string($slot['sku'] ?? null) ? $slot['sku'] : '';
            if (!Shape::isRecId($recId) || $externalId === '' || ($prefix !== '' && strpos($sku, $prefix) !== 0)) {
                continue;
            }
            $usable[] = ['rec_id' => $recId, 'external_id' => $externalId];
        }

        return array_slice($usable, 0, self::LIMIT);
    }
}
