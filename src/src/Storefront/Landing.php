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

/**
 * The landing endpoint (front controller `land`): the store's server sets the cookies of a click on
 * a Smaily link. The landing script (`views/js/front/attribution.js`) reads the link's parameters
 * and posts them here; it writes the cookies itself only when this request fails. As the Magento
 * connector's Controller\Attribution\Land.
 *
 * The rules are the script's:
 *
 * - a well-formed recommendation id (`rec_id`, a UUID) sets the recommendation cookie, and sets the
 *   context cookie to a well-formed `context` or deletes it: the two cookies always describe the
 *   same landing (contract "Cookie names", context cookie rule);
 * - a visitor token is taken only in the engine's `vt_` form, never a store-created `vs_` one.
 *
 * Not gated on the tracking consent, as in every Smaily connector: these are first-party cookies
 * that record the click on the merchant's own email. A request gives no more than a link with the
 * same parameters does, so it needs no token.
 *
 * Answers: 405 unless a POST; 404 while the shop has no connection; 429 beyond LIMIT_PER_MINUTE
 * requests of one network address in a minute; 400 when nothing posted has its shape; 500 when a
 * cookie could not be sent; else 204.
 */
final class Landing
{
    public const LIMIT_PER_MINUTE = 30;

    private ActiveConnection $connections;

    private RateLimiter $limiter;

    public function __construct(ActiveConnection $connections, RateLimiter $limiter)
    {
        $this->connections = $connections;
        $this->limiter = $limiter;
    }

    /**
     * @param array<string, mixed> $post the posted fields `rec_id`, `context` and `visitor_token`
     *
     * @return int the HTTP status
     */
    public function handle(int $idShop, string $method, array $post, Cookies $cookies): int
    {
        if ($method !== 'POST') {
            return 405;
        }
        $connection = $this->connections->stored($idShop);
        if ($connection === null) {
            return 404;
        }
        if (!$this->limiter->allow('land', self::LIMIT_PER_MINUTE)) {
            return 429;
        }

        $names = new ClientConfig($connection);
        $recId = self::field($post, 'rec_id');
        $context = self::field($post, 'context');
        $token = self::field($post, 'visitor_token');

        // [cookie, value ('' deletes it)]
        $set = [];
        if (Shape::isRecId($recId)) {
            $set[] = [ClientConfig::REC_ID, $recId];
            $set[] = [ClientConfig::CONTEXT, Shape::isContext($context) ? $context : ''];
        }
        if (Shape::isEngineVisitorToken($token)) {
            $set[] = [ClientConfig::VISITOR, $token];
        }
        if ($set === []) {
            return 400;
        }

        foreach ($set as [$cookie, $value]) {
            if (!$cookies->set($names->cookieName($cookie), $value, $names->days($cookie))) {
                return 500;
            }
        }

        return 204;
    }

    /**
     * @param array<string, mixed> $post
     */
    private static function field(array $post, string $name): string
    {
        return is_string($post[$name] ?? null) ? trim($post[$name]) : '';
    }
}
