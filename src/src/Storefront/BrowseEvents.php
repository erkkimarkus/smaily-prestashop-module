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
 * Turns the browse events that a browser posted into the shape of contract §6, as the Magento
 * connector's BrowseEventValidator.
 *
 * - A browser asserts no identity. A batch in which any event names the shopper (IDENTITY_FIELDS)
 *   is refused as a whole: the module's own script sends none of them. Identity reaches the engine
 *   from the server only: the relay adds the visitor token of the request's cookie.
 * - An event is kept when it has a UUID v4 `event_id`, a `session_id` in the session cookie's shape
 *   and one of the nine event types of §6. Any other event is dropped.
 * - Of the event's own fields only `category_path`, `search_query` and `dwell_seconds` are kept;
 *   every other key is dropped. The recommendation id and context are not accepted: the engine
 *   ignores them on a browse event (§6, v1.7.0), and a malformed id would cost the event.
 * - The server stamps `event_ts`: a browser cannot set the time.
 * - The product comes as the store's own ids (`product_id`, `product_attribute_id`), never as a
 *   key that the browser made up; the relay turns them into the engine's `sku`.
 */
final class BrowseEvents
{
    /** Events per request (the contract recommends 100). */
    public const MAX_EVENTS = 100;

    public const IDENTITY_FIELDS = [
        'customer_email',
        'email',
        'external_id',
        'customer_external_id',
        'customer_id',
        'smaily_visitor_token',
        'visitor_token',
    ];

    private const EVENT_TYPES = [
        'product_view',
        'category_view',
        'search',
        'cart_add',
        'cart_remove',
        'wishlist_add',
        'wishlist_remove',
        'checkout_start',
        'checkout_complete',
    ];

    private const UUID_V4 = '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/iD';

    private const MAX_TEXT = 255;

    /**
     * Whether any of the posted events names the shopper.
     *
     * @param array<mixed> $events
     */
    public static function nameIdentity(array $events): bool
    {
        foreach ($events as $event) {
            if (is_array($event) && array_intersect_key($event, array_flip(self::IDENTITY_FIELDS)) !== []) {
                return true;
            }
        }

        return false;
    }

    /**
     * The event in the contract's shape, and the product it names.
     *
     * @param array<mixed> $event one posted event
     * @param string $now the event's time, ISO 8601 in UTC
     *
     * @return array{event: array<string, mixed>, product: array{int, int}|null}|null null for an
     *                                                                                event that is dropped
     */
    public static function clean(array $event, string $now): ?array
    {
        $eventId = self::text($event, 'event_id');
        $sessionId = self::text($event, 'session_id');
        $type = self::text($event, 'event_type');
        if (preg_match(self::UUID_V4, $eventId) !== 1 || !Shape::isSessionId($sessionId) || !in_array($type, self::EVENT_TYPES, true)) {
            return null;
        }

        $clean = [
            'event_id' => strtolower($eventId),
            'session_id' => $sessionId,
            'event_type' => $type,
            'event_ts' => $now,
        ];
        foreach (['category_path', 'search_query'] as $field) {
            $value = self::text($event, $field);
            if ($value !== '' && strlen($value) <= self::MAX_TEXT) {
                $clean[$field] = $value;
            }
        }
        if (isset($event['dwell_seconds']) && is_numeric($event['dwell_seconds'])) {
            $clean['dwell_seconds'] = max(0, (int) $event['dwell_seconds']);
        }

        $idProduct = self::id($event, 'product_id');

        return [
            'event' => $clean,
            'product' => $idProduct > 0 ? [$idProduct, self::id($event, 'product_attribute_id')] : null,
        ];
    }

    /**
     * A field as trimmed text; a field that is no scalar counts as empty.
     *
     * @param array<mixed> $event
     */
    private static function text(array $event, string $field): string
    {
        $value = $event[$field] ?? '';

        return is_scalar($value) ? trim((string) $value) : '';
    }

    /**
     * A field as a positive id, or 0.
     *
     * @param array<mixed> $event
     */
    private static function id(array $event, string $field): int
    {
        $value = self::text($event, $field);

        return ctype_digit($value) && strlen($value) <= 10 ? (int) $value : 0;
    }
}
