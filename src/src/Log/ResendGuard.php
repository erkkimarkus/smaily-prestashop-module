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

use PrestaShop\Module\SmailyForPrestaShop\Queue\EventQueue;
use PrestaShop\Module\SmailyForPrestaShop\Queue\Handler\ContactSyncHandler;

/**
 * Decides whether an event-queue row may be sent again: the one answer for the list (which rows
 * offer "Send again"), for Details (why a failed row does not), for the "Send again" endpoint
 * (refuse a stale page) and for the mass retry (skip the row and count it). Ported from the Magento
 * connector's Model\Log\ResendGuard.
 *
 * Only a failed row is sent again, and not when sending again would be wrong:
 *  - `withdrawn`: the reminder was closed because the shopper ordered;
 *  - `erased`: the contact's data was erased; the erasure leaves ERASED_ENTITY as the row's entity
 *    (the privacy Story writes it, ARCHITECTURE 11.2);
 *  - `superseded`: a later row of the same event type for the same contact in the same shop was
 *    delivered. For an automation that is a later message of the same kind. A contact sync repeats
 *    a state and may go again, with one exception: a row that would mark the contact subscribed
 *    (its payload has `consent_changed` and `subscribed`) is not sent after a later delivered row
 *    that carried a consent change, because it would put a stale subscription into Smaily. A row
 *    that unsubscribes is never refused for this;
 *  - `queued`: such a later row waits in the queue, for example the one a "Send again" of this row
 *    added. The contact gets that row, so this one is not queued a second time. It has no sentence
 *    of its own.
 * A row without an entity (an address longer than the entity column) cannot be compared with later
 * rows and gets neither of the last two refusals.
 */
final class ResendGuard
{
    public const REASON_WITHDRAWN = 'withdrawn';

    public const REASON_SUPERSEDED = 'superseded';

    public const REASON_ERASED = 'erased';

    public const REASON_NOT_FAILED = 'not_failed';

    public const REASON_QUEUED = 'queued';

    /** The entity of a row whose contact was erased. */
    public const ERASED_ENTITY = '[erased]';

    /** The merchant's sentence of each refusal that has one, in Magento's words. */
    private const MESSAGES = [
        self::REASON_WITHDRAWN => 'This reminder was withdrawn because the shopper completed the purchase; it cannot be re-sent.',
        self::REASON_SUPERSEDED => 'A later message of this kind already reached this contact; sending again would deliver it twice.',
        self::REASON_ERASED => 'This contact\'s data was erased; the event cannot be re-sent.',
        self::REASON_NOT_FAILED => 'Only a failed event can be sent again.',
    ];

    /**
     * Why each row may not be sent again; a row that may is not in the answer.
     *
     * @param array<int, array<string, mixed>> $rows event-queue rows by id, with `id_shop`,
     *                                               `event_type`, `entity_id`, `status`,
     *                                               `last_error` and `payload` as stored
     *
     * @return array<int, string> the reason by row id
     */
    public function refusals(array $rows): array
    {
        $refused = [];
        $candidates = [];
        foreach ($rows as $id => $row) {
            $entity = (string) ($row['entity_id'] ?? '');
            if (LogStatus::ofQueueRow((string) $row['status'], (string) $row['event_type'], $row['last_error'] ?? null) === LogStatus::WITHDRAWN) {
                $refused[$id] = self::REASON_WITHDRAWN;
            } elseif ($entity === self::ERASED_ENTITY) {
                $refused[$id] = self::REASON_ERASED;
            } elseif ((string) $row['status'] !== EventQueue::STATUS_FAILED) {
                $refused[$id] = self::REASON_NOT_FAILED;
            } elseif ($entity !== '') {
                $candidates[$id] = $row;
            }
        }

        return $refused + $this->laterRows($candidates);
    }

    /**
     * The EN source of the merchant's sentence for a refusal, or '' when it has none.
     */
    public static function message(string $reason): string
    {
        return self::MESSAGES[$reason] ?? '';
    }

    /**
     * The failed rows that a later row of their kind stands in the way of: one read of the queue's
     * (`entity_id`, `event_type`, `status`) index for all of them.
     *
     * @param array<int, array<string, mixed>> $rows
     *
     * @return array<int, string>
     */
    private function laterRows(array $rows): array
    {
        if ($rows === []) {
            return [];
        }
        $quote = static fn ($value): string => '\'' . pSQL((string) $value, true) . '\'';
        $later = \Db::getInstance()->executeS(
            'SELECT `id`, `id_shop`, `event_type`, `entity_id`, `status`, `payload` FROM `' . _DB_PREFIX_ . EventQueue::TABLE . '`'
            . ' WHERE `entity_id` IN (' . implode(', ', array_unique(array_map($quote, array_column($rows, 'entity_id')))) . ')'
            . ' AND `event_type` IN (' . implode(', ', array_unique(array_map($quote, array_column($rows, 'event_type')))) . ')'
            . ' AND `status` IN (' . implode(', ', array_map($quote, [EventQueue::STATUS_SENT, EventQueue::STATUS_PENDING, EventQueue::STATUS_PROCESSING])) . ')',
            true,
            false
        );

        $refused = [];
        foreach (is_array($later) ? $later : [] as $other) {
            foreach ($rows as $id => $row) {
                if ((int) $other['id'] <= $id
                    || (int) $other['id_shop'] !== (int) $row['id_shop']
                    || $other['event_type'] !== $row['event_type']
                    || mb_strtolower((string) $other['entity_id']) !== mb_strtolower((string) $row['entity_id'])
                ) {
                    continue;
                }
                if ($other['status'] !== EventQueue::STATUS_SENT) {
                    $refused[$id] ??= self::REASON_QUEUED;
                } elseif (self::supersedes($row, $other)) {
                    $refused[$id] = self::REASON_SUPERSEDED;
                }
            }
        }

        return $refused;
    }

    /**
     * Whether the delivered later row $other makes sending $row again wrong.
     *
     * @param array<string, mixed> $row
     * @param array<string, mixed> $other
     */
    private static function supersedes(array $row, array $other): bool
    {
        if ($row['event_type'] !== ContactSyncHandler::EVENT_TYPE) {
            return true;
        }
        $payload = json_decode((string) ($row['payload'] ?? ''), true);
        $later = json_decode((string) ($other['payload'] ?? ''), true);

        return ($payload['consent_changed'] ?? false) === true
            && ($payload['subscribed'] ?? false) === true
            && ($later['consent_changed'] ?? false) === true;
    }
}
