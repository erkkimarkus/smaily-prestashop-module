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

namespace PrestaShop\Module\SmailyForPrestaShop\Contacts;

if (!defined('_PS_VERSION_')) {
    exit;
}

use PrestaShop\Module\SmailyForPrestaShop\Database\Sql;
use PrestaShop\Module\SmailyForPrestaShop\Log\Resend;
use PrestaShop\Module\SmailyForPrestaShop\Queue\EventQueue;
use PrestaShop\Module\SmailyForPrestaShop\Queue\Handler\ContactSyncHandler;

/**
 * Finds the `contact.sync` rows that a newer change of the same contact, already delivered to
 * Smaily, has overtaken (owner, 2026-11-26; Linear CON-397, CON-433).
 *
 * A row carries what the store saw when the row was queued, and rows do not always go in the
 * order they were queued: a row that failed waits for its retry while a newer row of the same
 * contact is delivered. Sending the older row after that would undo the newer change in Smaily;
 * the older row is closed instead. Newer means recorded later: a row's place is its id, and a row
 * that the Log's "Send again" queued takes the place of the row it copies (Log\Resend). Rows of
 * one contact match by shop and entity (Queue\ContactEntity), whatever their source: a customer, a
 * guest-checkout customer and a newsletter-block subscriber with one address are one contact.
 *
 * A newer delivered row overtakes an older row that changes no consent. An older row that changes
 * the consent (an unsubscribe or a subscription) is overtaken only by a newer delivered row that
 * changes the consent too: a newer row without a consent change sends no subscription status, so
 * closing the older row would lose the contact's choice. An unsubscribe is therefore never closed
 * in favour of an older subscription, nor of a newer profile save.
 *
 * The one answer for the queue's handler, which closes an overtaken row as skipped at every
 * attempt, an automatic retry included, and for the Log's "Send again" and "Retry"
 * (Log\ResendGuard).
 */
final class NewerChange
{
    /**
     * The ids of the overtaken rows among $rows: one read of the queue for all of them, through its
     * (`entity_id`, `event_type`, `status`) index, without a lock.
     *
     * @param array<int, array<string, mixed>> $rows `contact.sync` rows by id, with `id_shop`,
     *                                               `entity_id` and `payload` as stored
     *
     * @return list<int>
     */
    public static function among(array $rows): array
    {
        $candidates = [];
        foreach ($rows as $id => $row) {
            $entity = (string) ($row['entity_id'] ?? '');
            if ($entity !== '') {
                $payload = self::payload($row);
                $candidates[(int) $id] = [
                    (int) $row['id_shop'],
                    mb_strtolower($entity),
                    self::place((int) $id, $payload),
                    ($payload['consent_changed'] ?? false) === true,
                ];
            }
        }
        if ($candidates === []) {
            return [];
        }

        $quote = static fn (string $value): string => '\'' . pSQL($value, true) . '\'';
        $delivered = Sql::rows(
            'SELECT `id`, `id_shop`, `entity_id`, `payload` FROM `' . _DB_PREFIX_ . EventQueue::TABLE . '`'
            . ' WHERE `entity_id` IN (' . implode(', ', array_unique(array_map($quote, array_column($candidates, 1)))) . ')'
            . ' AND `event_type` = ' . $quote(ContactSyncHandler::EVENT_TYPE)
            . ' AND `status` = ' . $quote(EventQueue::STATUS_SENT)
            . ' AND `id` > ' . min(array_column($candidates, 2))
        );

        $overtaken = [];
        foreach ($delivered as $other) {
            $payload = self::payload($other);
            $place = self::place((int) $other['id'], $payload);
            $changesConsent = ($payload['consent_changed'] ?? false) === true;
            foreach ($candidates as $id => [$idShop, $entity, $own, $ownChangesConsent]) {
                if ($place > $own
                    && (int) $other['id_shop'] === $idShop
                    && mb_strtolower((string) $other['entity_id']) === $entity
                    && (!$ownChangesConsent || $changesConsent)
                ) {
                    $overtaken[$id] = true;
                }
            }
        }

        return array_keys($overtaken);
    }

    /**
     * Where a row stands in the order the store recorded its changes: the id of the row that first
     * held its event.
     *
     * @param array<int|string, mixed> $payload
     */
    private static function place(int $id, array $payload): int
    {
        $record = Resend::recordOf($payload);
        $place = $record === null ? 0 : ($record['origin'] ?: $record['of']);

        return $place > 0 ? $place : $id;
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<int|string, mixed>
     */
    private static function payload(array $row): array
    {
        $payload = json_decode((string) ($row['payload'] ?? ''), true);

        return is_array($payload) ? $payload : [];
    }
}
