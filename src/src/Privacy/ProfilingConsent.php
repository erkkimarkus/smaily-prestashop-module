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

namespace PrestaShop\Module\SmailyForPrestaShop\Privacy;

if (!defined('_PS_VERSION_')) {
    exit;
}

use PrestaShop\Module\SmailyForPrestaShop\Engine\Connection;
use PrestaShop\Module\SmailyForPrestaShop\Engine\Connections;
use PrestaShop\Module\SmailyForPrestaShop\Queue\ContactEntity;
use PrestaShop\Module\SmailyForPrestaShop\Queue\EventQueue;

/**
 * A shopper's consent to personalization (profiling), as the Magento connector's
 * Model\Privacy\ProfilingConsent: an opt-out model. A shopper is profiled unless the store holds
 * an opt-out for the address (ProfilingRecord).
 *
 * A choice is written to the store's record first and then queued for Campaign Intelligence
 * (contract §10), one `engine.profiling_consent` row per connection, so the engine hears it on the
 * queue's retry ladder (ProfilingConsentHandler). The record holds the newest choice; the handler
 * sends a row only while it still says what the record says, so the engine ends at the newest
 * choice too.
 *
 * Leaving marketing also stops profiling, and joining it again starts profiling again, unless the
 * shopper opted out of profiling on the account page: that choice stays (Magento's
 * optOutOnUnsubscribe() and optInOnResubscribe()). Both act only in a shop that has a connection.
 *
 * The engine keeps an opt-out only for a shopper it holds. An opt-out is therefore sent again to a
 * connection whose engine store confirmed a customer or an order of the shopper
 * (resendOptOuts(), Magento's OptOutReplay).
 *
 * Unlike Magento, nothing is written to the Smaily contact (`smaily_rec_profiling`) and nothing is
 * read back from it: the design keeps the choice in the store and at the engine (ARCHITECTURE 11.2).
 */
class ProfilingConsent
{
    public const EVENT_TYPE = 'engine.profiling_consent';

    private ProfilingRecord $record;

    private Connections $connections;

    private EventQueue $queue;

    public function __construct(ProfilingRecord $record, Connections $connections, EventQueue $queue)
    {
        $this->record = $record;
        $this->connections = $connections;
        $this->queue = $queue;
    }

    /**
     * Whether the shopper may be profiled: true unless the store holds an opt-out for the address.
     */
    public function isAllowed(string $email): bool
    {
        return trim($email) === '' || !$this->record->optedOut($email);
    }

    /**
     * Records the shopper's own choice from the account page and queues it for the engine.
     *
     * @param int $idShop the shop the choice was made in
     */
    public function setAllowed(string $email, bool $allowed, int $idShop): void
    {
        if (trim($email) === '') {
            return;
        }
        $at = $this->record->write($email, !$allowed, ProfilingRecord::SOURCE_ACCOUNT);
        $this->queueForEngine($email, !$allowed, $at, $idShop);
    }

    /**
     * A contact event of the request (Contacts\Capture\ContactEvents::listen()): a consent to
     * marketing that was lost stops profiling, and one that was gained lifts an opt-out that came
     * only from losing it.
     *
     * @param string|null $language unused; the listener's signature
     * @param array<string, mixed> $payload the event's `contact.sync` payload
     *
     * @return int the number of rows queued
     */
    public function onContactEvent(int $idShop, ?string $language, array $payload): int
    {
        $email = (string) ($payload['email'] ?? '');
        if (!($payload['consent_changed'] ?? false) || trim($email) === '' || $this->connections->forShop($idShop) === null) {
            return 0;
        }
        $record = $this->record->find($email);
        $ownOptOut = $record !== null && $record['opted_out'] && $record['source'] !== ProfilingRecord::SOURCE_UNSUBSCRIBE;
        if ($payload['subscribed'] ?? false) {
            if ($record === null || !$record['opted_out'] || $ownOptOut) {
                return 0;
            }
            $at = $this->record->write($email, false, ProfilingRecord::SOURCE_SUBSCRIBE);

            return $this->queueForEngine($email, false, $at, $idShop);
        }
        if ($ownOptOut) {
            return 0;
        }
        $at = $this->record->write($email, true, ProfilingRecord::SOURCE_UNSUBSCRIBE);

        return $this->queueForEngine($email, true, $at, $idShop);
    }

    /**
     * The engine store of $connection confirmed a customer or an order of these shoppers (the
     * ingest flush). An opt-out made before the engine knew the shopper did not stay there, and a
     * customer or an order carries no consent: the stored opt-out of each shopper who opted out is
     * queued again for that connection, with its moment, so the newest choice still wins when the
     * row is sent. Not for a shopper whose opt-out already waits for the connection: many orders
     * make one row.
     *
     * @param list<string> $emails
     * @param int $idShop the shop of the rows of the installation-wide connection
     *
     * @return int the number of rows queued
     */
    public function resendOptOuts(array $emails, Connection $connection, int $idShop): int
    {
        $addresses = array_values(array_unique(array_filter(array_map([ContactEntity::class, 'address'], $emails))));
        $optOuts = $this->record->optOuts($addresses);
        if ($optOuts === []) {
            return 0;
        }
        // The shoppers whose opt-out already waits for the connection, by keyed hash.
        $waiting = [];
        foreach ($this->queue->waitingOfEntities(array_map('strval', array_keys($optOuts)), self::EVENT_TYPE) as $row) {
            $payload = json_decode((string) $row['payload'], true);
            if (is_array($payload) && (int) ($payload['connection'] ?? 0) === $connection->id() && ($payload['opt_out'] ?? null) === true) {
                $waiting[strtolower((string) $row['entity_id'])] = true;
            }
        }
        $queued = 0;
        foreach ($addresses as $address) {
            $key = ContactEntity::key($address);
            if (!isset($optOuts[$key]) || isset($waiting[$key])) {
                continue;
            }
            $this->queue->enqueue(
                self::EVENT_TYPE,
                $connection->idShop() > 0 ? $connection->idShop() : $idShop,
                ['connection' => $connection->id(), 'email' => $address, 'opt_out' => true, 'opted_out_at' => str_replace(' ', 'T', $optOuts[$key]) . 'Z'],
                $key
            );
            ++$queued;
        }

        return $queued;
    }

    /**
     * Queues the choice for every connection: an address has one choice in the whole installation.
     * The row's entity is the address's keyed hash; only the payload names the shopper. A row
     * belongs to its connection's shop, and a row of the installation-wide connection to the shop
     * the choice was made in, so the Log shows it there.
     *
     * @return int the number of rows queued
     */
    private function queueForEngine(string $email, bool $optOut, string $at, int $idShop): int
    {
        $address = ContactEntity::address($email);
        $queued = 0;
        foreach ($this->connections->all() as $connection) {
            $payload = ['connection' => $connection->id(), 'email' => $address, 'opt_out' => $optOut];
            if ($optOut) {
                $payload['opted_out_at'] = $at;
            }
            $this->queue->enqueue(
                self::EVENT_TYPE,
                $connection->idShop() > 0 ? $connection->idShop() : $idShop,
                $payload,
                ContactEntity::key($address)
            );
            ++$queued;
        }

        return $queued;
    }
}
