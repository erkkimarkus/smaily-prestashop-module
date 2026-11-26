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

use PrestaShop\Module\SmailyForPrestaShop\Contacts\NewerChange;
use PrestaShop\Module\SmailyForPrestaShop\Database\Sql;
use PrestaShop\Module\SmailyForPrestaShop\Engine\Capture\IdentityCapture;
use PrestaShop\Module\SmailyForPrestaShop\Engine\Ingest\IngestQueue;
use PrestaShop\Module\SmailyForPrestaShop\Queue\EventQueue;
use PrestaShop\Module\SmailyForPrestaShop\Queue\Handler\ContactSyncHandler;

/**
 * Decides whether an event-queue row may be sent again: the one answer for the list (which rows
 * offer "Send again"), for Details (why a failed row does not), for the "Send again" endpoint
 * (refuse a stale page) and for the mass retry (skip the row and count it). Ported from the Magento
 * connector's Model\Log\ResendGuard.
 *
 * Only a failed row is sent again, and not when sending again would be wrong. The refusals are
 * Magento's (owner, 2026-11-21), and the rule of all four connectors for a contact change (owner,
 * 2026-11-26; Linear CON-433):
 *  - `withdrawn`: the reminder was closed because the shopper ordered;
 *  - `erased`: the contact's data was erased; the erasure leaves ERASED_ENTITY as the row's entity
 *    (the privacy Story writes it, ARCHITECTURE 11.2);
 *  - `superseded`: a later row of the same automation for the same contact in the same shop was
 *    delivered, so the contact has a later message of the same kind;
 *  - `newer_change_sent`: a contact sync that a newer delivered change of the same contact
 *    overtook. Contacts\NewerChange decides that, for this guard and for the queue's handler
 *    alike, so a row that the guard lets through is not skipped for it when it is sent.
 * An identity merge repeats a state: a failed one may always go again. A row whose event waits in
 * the queue again (a "Send again" of it, not sent yet) may go again too. A row without an entity
 * cannot be compared with later rows and gets neither of the last two refusals; a contact's row
 * always has one (Queue\ContactEntity).
 *
 * A row of the Campaign Intelligence ingest queue has refusals of its own (ingestRefusals()).
 */
final class ResendGuard
{
    public const REASON_WITHDRAWN = 'withdrawn';

    public const REASON_SUPERSEDED = 'superseded';

    public const REASON_NEWER_CHANGE = 'newer_change_sent';

    public const REASON_ERASED = 'erased';

    public const REASON_NOT_FAILED = 'not_failed';

    /** A nightly product list of Campaign Intelligence: the next night sends a new one. */
    public const REASON_NIGHTLY = 'nightly';

    /** The entity of a row whose contact was erased. */
    public const ERASED_ENTITY = '[erased]';

    /**
     * The merchant's sentence of each refusal, in Magento's words; Magento has no refusal of a
     * newer change, and that sentence follows the one before it.
     */
    private const MESSAGES = [
        self::REASON_WITHDRAWN => 'This reminder was withdrawn because the shopper completed the purchase; it cannot be re-sent.',
        self::REASON_SUPERSEDED => 'A later message of this kind already reached this contact; sending again would deliver it twice.',
        self::REASON_NEWER_CHANGE => 'A newer change of this contact was already sent to Smaily; sending this older one again would undo it.',
        self::REASON_ERASED => 'This contact\'s data was erased; the event cannot be re-sent.',
        self::REASON_NOT_FAILED => 'Only a failed event can be sent again.',
        self::REASON_NIGHTLY => 'The product list is built afresh each night, so this one is not sent again.',
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
        $contactSyncs = [];
        foreach ($rows as $id => $row) {
            $entity = (string) ($row['entity_id'] ?? '');
            if (LogStatus::ofQueueRow((string) $row['status'], (string) $row['event_type'], $row['last_error'] ?? null) === LogStatus::WITHDRAWN) {
                $refused[$id] = self::REASON_WITHDRAWN;
            } elseif ($entity === self::ERASED_ENTITY) {
                $refused[$id] = self::REASON_ERASED;
            } elseif ((string) $row['status'] !== EventQueue::STATUS_FAILED) {
                $refused[$id] = self::REASON_NOT_FAILED;
            } elseif ((string) $row['event_type'] === ContactSyncHandler::EVENT_TYPE) {
                $contactSyncs[$id] = $row;
            } elseif ($entity !== '' && (string) $row['event_type'] !== IdentityCapture::EVENT_TYPE) {
                $candidates[$id] = $row;
            }
        }
        foreach (NewerChange::among($contactSyncs) as $id) {
            $refused[$id] = self::REASON_NEWER_CHANGE;
        }

        return $refused + $this->superseded($candidates);
    }

    /**
     * Why each row of the Campaign Intelligence ingest queue may not be sent again; a row that may
     * is not in the answer. Magento's refusals for an engine row: an erased one, one that did not
     * fail, and a nightly product list, which is built afresh each night. Every other failed row
     * may go again: it sends the store's state, and the engine takes a repeat as an update.
     *
     * @param array<int, array<string, mixed>> $rows ingest-queue rows by id, with `domain`,
     *                                               `entity_key` and `status` as stored
     *
     * @return array<int, string> the reason by row id
     */
    public function ingestRefusals(array $rows): array
    {
        $refused = [];
        foreach ($rows as $id => $row) {
            if ((string) $row['domain'] === IngestQueue::DOMAIN_MANIFEST) {
                $refused[$id] = self::REASON_NIGHTLY;
            } elseif ((string) ($row['entity_key'] ?? '') === self::ERASED_ENTITY) {
                $refused[$id] = self::REASON_ERASED;
            } elseif ((string) $row['status'] !== EventQueue::STATUS_FAILED) {
                $refused[$id] = self::REASON_NOT_FAILED;
            }
        }

        return $refused;
    }

    /**
     * Why a row of the Log may not be sent again, or null when it may: the answer above for a row
     * of either queue; an import job is never sent again.
     *
     * @param array<string, mixed> $row a row of LogRows::find()
     */
    public function refusalOf(array $row): ?string
    {
        $id = (int) $row['id'];
        switch ($row['kind']) {
            case LogRows::SMAILY:
                return $this->refusals([$id => $row])[$id] ?? null;
            case LogRows::INTELLIGENCE:
                return $this->ingestRefusals([$id => $row])[$id] ?? null;
            default:
                return self::REASON_NOT_FAILED;
        }
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
