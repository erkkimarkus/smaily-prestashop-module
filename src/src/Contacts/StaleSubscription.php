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
use PrestaShop\Module\SmailyForPrestaShop\Queue\EventQueue;
use PrestaShop\Module\SmailyForPrestaShop\Queue\Handler\ContactSyncHandler;

/**
 * Finds the `contact.sync` rows that would put a stale subscription into Smaily.
 *
 * A row carries the consent that the store saw when the row was queued, and rows do not always go
 * in the order they were queued: a row that failed waits for its retry while a later row of the
 * same contact is delivered. A row that would mark the contact subscribed (its payload has
 * `consent_changed` and `subscribed`) is stale once a later row of the same contact in the same
 * shop, with a consent change of its own, was delivered: the later row is the contact's newer
 * choice, and sending the older one would undo it. A subscription status is sent only when it
 * changes, and nothing subscribes a contact again (CLAUDE.md, rule 4).
 *
 * A row that unsubscribes is never stale: sent late, it can only narrow who is subscribed. A later
 * row that is not delivered (it waits, failed or was skipped) makes nothing stale. Rows of one
 * contact match by shop and entity (Queue\ContactEntity), whatever their source: a customer, a
 * guest-checkout customer and a newsletter-block subscriber with one address are one Smaily contact.
 *
 * The one answer for the queue's handler, which closes a stale row as skipped at every attempt, an
 * automatic retry included, and for the Log's "Send again" and "Retry" (Log\ResendGuard).
 */
final class StaleSubscription
{
    /**
     * The ids of the stale rows among $rows: one read of the queue for all of them, through its
     * (`entity_id`, `event_type`, `status`) index, without a lock.
     *
     * @param array<int, array<string, mixed>> $rows `contact.sync` rows by id, with `id_shop`,
     *                                               `entity_id` and `payload` as stored
     *
     * @return list<int>
     */
    public static function among(array $rows): array
    {
        $subscribing = [];
        foreach ($rows as $id => $row) {
            $payload = json_decode((string) ($row['payload'] ?? ''), true);
            $entity = (string) ($row['entity_id'] ?? '');
            if ($entity !== '' && ($payload['consent_changed'] ?? false) === true && ($payload['subscribed'] ?? false) === true) {
                $subscribing[(int) $id] = [(int) $row['id_shop'], mb_strtolower($entity)];
            }
        }
        if ($subscribing === []) {
            return [];
        }

        $quote = static fn (string $value): string => '\'' . pSQL($value, true) . '\'';
        $later = Sql::rows(
            'SELECT `id`, `id_shop`, `entity_id`, `payload` FROM `' . _DB_PREFIX_ . EventQueue::TABLE . '`'
            . ' WHERE `entity_id` IN (' . implode(', ', array_unique(array_map($quote, array_column($subscribing, 1)))) . ')'
            . ' AND `event_type` = ' . $quote(ContactSyncHandler::EVENT_TYPE)
            . ' AND `status` = ' . $quote(EventQueue::STATUS_SENT)
            . ' AND `id` > ' . min(array_keys($subscribing))
        );

        $stale = [];
        foreach ($later as $other) {
            if ((json_decode((string) $other['payload'], true)['consent_changed'] ?? false) !== true) {
                continue;
            }
            foreach ($subscribing as $id => [$idShop, $entity]) {
                if ((int) $other['id'] > $id && (int) $other['id_shop'] === $idShop && mb_strtolower((string) $other['entity_id']) === $entity) {
                    $stale[$id] = true;
                }
            }
        }

        return array_keys($stale);
    }
}
