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

namespace PrestaShop\Module\SmailyForPrestaShop\Contacts\Capture;

if (!defined('_PS_VERSION_')) {
    exit;
}

use PrestaShop\Module\SmailyForPrestaShop\Config\ContactsSettings;
use PrestaShop\Module\SmailyForPrestaShop\Queue\EventQueue;
use PrestaShop\Module\SmailyForPrestaShop\Queue\Flusher;
use PrestaShop\Module\SmailyForPrestaShop\Queue\Handler\ContactSyncHandler;
use PrestaShop\Module\SmailyForPrestaShop\Runtime\Log;
use PrestaShop\Module\SmailyForPrestaShop\Scheduler\Lock;

/**
 * The contact events of one request, written to the event queue as `contact.sync` rows when the
 * request ends.
 *
 * One request can fire several capture hooks for one address (an account edit fires the customer
 * update hooks and the account-update hook); it gets one row per shop and address, and the row
 * keeps what any of its events saw: a consent change or a checkout. A check registered with
 * atEnd() runs before the rows are written, so it can confirm what another module stored during the
 * request. A shop whose contact sync is not active gets no row: its handler would only skip it.
 *
 * At shutdown the rows are written; then, where the server can send the response before the
 * request ends (fastcgi_finish_request() or litespeed_finish_request()), the response goes out and
 * one batch of the event queue is flushed, under the tick's lock, so that a contact reaches Smaily
 * at once and not at the next tick. A flush never runs while a tick holds the lock, and nothing is
 * flushed where no finish function exists, so a shopper never waits for Smaily.
 */
final class ContactEvents
{
    private EventQueue $queue;

    private ContactsSettings $settings;

    private Lock $lock;

    /** @var callable(): Flusher */
    private $flusher;

    /** @var (callable(): mixed)|null */
    private $finishRequest;

    /** @var callable(callable): void */
    private $onShutdown;

    /** @var array<string, array{id_shop: int, language: ?string, payload: array<string, mixed>}> */
    private array $events = [];

    /** @var list<callable(): void> */
    private array $checks = [];

    private bool $scheduled = false;

    /**
     * @param callable(): Flusher $flusher
     * @param (callable(): mixed)|null $finishRequest sends the response before the request ends;
     *                                                null where the server has no such function
     * @param callable(callable): void $onShutdown registers a function to run at shutdown
     */
    public function __construct(EventQueue $queue, ContactsSettings $settings, Lock $lock, callable $flusher, ?callable $finishRequest, callable $onShutdown)
    {
        $this->queue = $queue;
        $this->settings = $settings;
        $this->lock = $lock;
        $this->flusher = $flusher;
        $this->finishRequest = $finishRequest;
        $this->onShutdown = $onShutdown;
    }

    /**
     * Records a contact event of shop $idShop in the language $idLang.
     *
     * @param array<string, mixed> $payload a `contact.sync` payload (ContactSyncHandler)
     */
    public function add(int $idShop, int $idLang, array $payload): void
    {
        if (!$this->settings->syncActive($idShop)) {
            return;
        }

        $key = $idShop . ':' . self::address((string) $payload['email']);
        $this->events[$key] = [
            'id_shop' => $idShop,
            'language' => self::language($idLang),
            'payload' => isset($this->events[$key]) ? self::merge($this->events[$key]['payload'], $payload) : $payload,
        ];
        $this->schedule();
    }

    /**
     * Runs $check when the request ends, before the rows are written; it may add() events.
     *
     * @param callable(): void $check
     */
    public function atEnd(callable $check): void
    {
        $this->checks[] = $check;
        $this->schedule();
    }

    /**
     * Runs the checks and writes one row per shop and address.
     *
     * @return int the number of rows written
     */
    public function commit(): int
    {
        $checks = $this->checks;
        $this->checks = [];
        foreach ($checks as $check) {
            try {
                $check();
            } catch (\Throwable $error) {
                Log::failure('a contact capture check', $error);
            }
        }

        $events = $this->events;
        $this->events = [];
        foreach ($events as $event) {
            $this->queue->enqueue(
                ContactSyncHandler::EVENT_TYPE,
                $event['id_shop'],
                $event['payload'],
                self::entityId((string) $event['payload']['email']),
                $event['language']
            );
        }

        return count($events);
    }

    /**
     * Writes the rows, then sends the response and flushes one batch of the queue.
     */
    public function afterResponse(): void
    {
        $this->scheduled = false;
        try {
            $written = $this->commit();
        } catch (\Throwable $error) {
            Log::failure('the contact capture', $error);

            return;
        }
        if ($written === 0 || $this->finishRequest === null) {
            return;
        }

        ignore_user_abort(true);
        ($this->finishRequest)();
        if (!$this->lock->acquire()) {
            return;
        }
        try {
            ($this->flusher)()->flush();
        } catch (\Throwable $error) {
            Log::failure('the enqueue-time flush', $error);
        } finally {
            $this->lock->release();
        }
    }

    /**
     * One event that holds what both events of an address saw: the later event's consent and
     * profile, and a consent change or a checkout of either.
     *
     * @param array<string, mixed> $earlier
     * @param array<string, mixed> $later
     *
     * @return array<string, mixed>
     */
    public static function merge(array $earlier, array $later): array
    {
        $merged = $later;
        $merged['consent_changed'] = (bool) $earlier['consent_changed'] || (bool) $later['consent_changed'];
        $merged['checkout'] = (bool) ($earlier['checkout'] ?? false) || (bool) ($later['checkout'] ?? false);

        return $merged;
    }

    private function schedule(): void
    {
        if ($this->scheduled) {
            return;
        }
        $this->scheduled = true;
        ($this->onShutdown)([$this, 'afterResponse']);
    }

    private static function address(string $email): string
    {
        return strtolower(trim($email));
    }

    /**
     * The row's entity id: the address, or null when it is longer than the column holds.
     */
    private static function entityId(string $email): ?string
    {
        $address = self::address($email);

        return strlen($address) <= 64 ? $address : null;
    }

    private static function language(int $idLang): ?string
    {
        $iso = $idLang > 0 ? \Language::getIsoById($idLang) : false;

        return is_string($iso) && $iso !== '' ? strtolower($iso) : null;
    }
}
