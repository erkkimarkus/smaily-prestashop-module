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

namespace PrestaShop\Module\SmailyForPrestaShop\Log;

if (!defined('_PS_VERSION_')) {
    exit;
}

use PrestaShop\Module\SmailyForPrestaShop\Automation\AbandonedCart\AbandonedCartHandler;
use PrestaShop\Module\SmailyForPrestaShop\Automation\AbandonedCart\CartTracker;
use PrestaShop\Module\SmailyForPrestaShop\Queue\EventQueue;

/**
 * The Log's "Send again" of one failed event-queue row, as the Magento connector does it: a new
 * pending row carries the same event, and the failed row stays untouched as the history of what
 * went wrong. The new row's payload records the decision under the reserved key `_resend`:
 * `{"of": <the failed row's id>, "by": <the employee>, "at": <UTC time>}`. Every handler reads the
 * keys of its own payload only, so the record never reaches Smaily.
 *
 * The caller asks ResendGuard first. An abandoned-cart reminder is sent only by the queue row that
 * its cart's tracker row names (AbandonedCartHandler), so the tracker row follows the new row.
 */
final class Resend
{
    public const PAYLOAD_KEY = '_resend';

    private EventQueue $queue;

    private CartTracker $carts;

    public function __construct(EventQueue $queue, CartTracker $carts)
    {
        $this->queue = $queue;
        $this->carts = $carts;
    }

    /**
     * @param array<string, mixed> $row the failed row as stored
     * @param string $employee who asked for it
     *
     * @return int the id of the new row
     */
    public function resend(array $row, string $employee): int
    {
        $payload = json_decode((string) $row['payload'], true);
        $payload = is_array($payload) ? $payload : [];
        $payload[self::PAYLOAD_KEY] = ['of' => (int) $row['id'], 'by' => $employee, 'at' => gmdate('Y-m-d\TH:i:s\Z')];

        $id = $this->queue->enqueue(
            (string) $row['event_type'],
            (int) $row['id_shop'],
            $payload,
            $row['entity_id'] === null ? null : (string) $row['entity_id'],
            $row['language'] === null ? null : (string) $row['language']
        );
        if ($row['event_type'] === AbandonedCartHandler::EVENT_TYPE && is_int($payload['id_cart'] ?? null)) {
            $cart = $this->carts->row($payload['id_cart']);
            if ($cart !== null && (int) $cart['event_id'] === (int) $row['id']) {
                $this->carts->queued($payload['id_cart'], $id);
            }
        }

        return $id;
    }

    /**
     * The record of a row that "Send again" queued, or null for any other row.
     *
     * @param array<int|string, mixed> $payload the row's decoded payload
     *
     * @return array{of: int, by: string, at: int}|null `at` is a Unix time
     */
    public static function recordOf(array $payload): ?array
    {
        $record = $payload[self::PAYLOAD_KEY] ?? null;
        if (!is_array($record)) {
            return null;
        }

        return [
            'of' => (int) ($record['of'] ?? 0),
            'by' => is_string($record['by'] ?? null) ? $record['by'] : '',
            'at' => (int) strtotime(is_string($record['at'] ?? null) ? $record['at'] : ''),
        ];
    }
}
