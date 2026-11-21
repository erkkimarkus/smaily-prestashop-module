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

use PrestaShop\Module\SmailyForPrestaShop\Database\Sql;
use PrestaShop\Module\SmailyForPrestaShop\Queue\EventQueue;
use PrestaShop\Module\SmailyForPrestaShop\Queue\Handler\ContactSyncHandler;

/**
 * Decides whether an event-queue row may be sent again: the one answer for the list (which rows
 * offer "Send again"), for Details (why a failed row does not), for the "Send again" endpoint
 * (refuse a stale page) and for the mass retry (skip the row and count it). Ported from the Magento
 * connector's Model\Log\ResendGuard.
 *
 * Only a failed row is sent again, and not when sending again would be wrong. The refusals are
 * Magento's, and no other (owner, 2026-11-21):
 *  - `withdrawn`: the reminder was closed because the shopper ordered;
 *  - `erased`: the contact's data was erased; the erasure leaves ERASED_ENTITY as the row's entity
 *    (the privacy Story writes it, ARCHITECTURE 11.2);
 *  - `superseded`: a later row of the same automation for the same contact in the same shop was
 *    delivered, so the contact has a later message of the same kind.
 * A contact sync repeats a state, not a message: a failed one may always go again, as stored,
 * whatever later row of the contact was delivered. A row whose event waits in the queue again (a
 * "Send again" of it, not sent yet) may go again too. An automation row without an entity cannot be
 * compared with later rows and is never superseded; a contact's row always has one
 * (Queue\ContactEntity).
 */
final class ResendGuard
{
    public const REASON_WITHDRAWN = 'withdrawn';

    public const REASON_SUPERSEDED = 'superseded';

    public const REASON_ERASED = 'erased';

    public const REASON_NOT_FAILED = 'not_failed';

    /** The entity of a row whose contact was erased. */
    public const ERASED_ENTITY = '[erased]';

    /** The merchant's sentence of each refusal, in Magento's words. */
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
     *                                               `event_type`, `entity_id`, `status` and
     *                                               `last_error` as stored
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
            } elseif ($entity !== '' && (string) $row['event_type'] !== ContactSyncHandler::EVENT_TYPE) {
                $candidates[$id] = $row;
            }
        }

        return $refused + $this->superseded($candidates);
    }

    /**
     * The EN source of the merchant's sentence for a refusal, or '' for an unknown reason.
     */
    public static function message(string $reason): string
    {
        return self::MESSAGES[$reason] ?? '';
    }

    /**
     * The failed automation rows that a later delivered row of their kind superseded: one read of
     * the queue's (`entity_id`, `event_type`, `status`) index for all of them.
     *
     * @param array<int, array<string, mixed>> $rows
     *
     * @return array<int, string>
     */
    private function superseded(array $rows): array
    {
        if ($rows === []) {
            return [];
        }
        $quote = static fn ($value): string => '\'' . pSQL((string) $value, true) . '\'';
        $later = Sql::rows(
            'SELECT `id`, `id_shop`, `event_type`, `entity_id` FROM `' . _DB_PREFIX_ . EventQueue::TABLE . '`'
            . ' WHERE `entity_id` IN (' . implode(', ', array_unique(array_map($quote, array_column($rows, 'entity_id')))) . ')'
            . ' AND `event_type` IN (' . implode(', ', array_unique(array_map($quote, array_column($rows, 'event_type')))) . ')'
            . ' AND `status` = ' . $quote(EventQueue::STATUS_SENT)
        );

        $refused = [];
        foreach ($later as $other) {
            foreach ($rows as $id => $row) {
                if ((int) $other['id'] > $id
                    && (int) $other['id_shop'] === (int) $row['id_shop']
                    && $other['event_type'] === $row['event_type']
                    && mb_strtolower((string) $other['entity_id']) === mb_strtolower((string) $row['entity_id'])
                ) {
                    $refused[$id] = self::REASON_SUPERSEDED;
                }
            }
        }

        return $refused;
    }
}
