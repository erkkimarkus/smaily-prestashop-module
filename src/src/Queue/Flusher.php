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

namespace PrestaShop\Module\SmailyForPrestaShop\Queue;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Drains the event queue: claims due rows, hands the rows of each event type to that type's
 * handler, and records each row's outcome.
 *
 * A handler answers per row: true (sent), a Skipped, a Pending, a Deferred, a Failure, or a
 * \Throwable (a failed send handed back whole; Failure::of() reads it). A row without an answer fails and takes
 * the retry ladder. One poison row never owns the pass: when a handler throws for a batch, each row
 * of the batch is handed to it again alone, so only the row that throws fails. A handler therefore
 * reports a failure of the wire as that row's outcome and throws only on a fault of its own.
 *
 * Where the handler tells what it sent for a row (ExchangeSource), the row keeps that request and
 * its answer, whatever the outcome.
 *
 * A database statement that fails throws (Database\Sql) and ends the flush; the tick logs it. The
 * rows that went out are still recorded as sent where that write passes. A row that went out and
 * could not be recorded stays claimed and is sent again once its claim is stale (ARCHITECTURE 4.3).
 */
final class Flusher
{
    public const BATCH_SIZE = 200;

    /*
     * The messages of the flusher's own failures, as English source text with %name% placeholders:
     * the Log reads them back by their wording (Log\FailureMessage::MESSAGES).
     */
    public const MESSAGE_NO_HANDLER = 'No handler takes the event type "%type%"';
    public const MESSAGE_NO_OUTCOME = 'The handler returned no outcome for the row';

    private EventQueue $queue;

    /** @var callable(string): ?HandlerInterface */
    private $handlers;

    /**
     * @param callable(string): ?HandlerInterface $handlers the handler of an event type, or null
     *                                                      when none takes it
     */
    public function __construct(EventQueue $queue, callable $handlers)
    {
        $this->queue = $queue;
        $this->handlers = $handlers;
    }

    /**
     * Sends one batch of due rows.
     *
     * @return int the number of rows claimed; 0 when nothing was due, or when a concurrent flush
     *             claimed the due rows first
     */
    public function flush(int $limit = self::BATCH_SIZE): int
    {
        $this->queue->requeueStale();
        $rows = $this->queue->claim($limit);

        $byType = [];
        foreach ($rows as $row) {
            $byType[(string) $row['event_type']][] = $row;
        }
        foreach ($byType as $eventType => $typeRows) {
            $this->dispatch((string) $eventType, $typeRows);
        }

        return count($rows);
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    private function dispatch(string $eventType, array $rows): void
    {
        try {
            $handler = ($this->handlers)($eventType);
        } catch (\Throwable $error) {
            $this->failAll($rows, Failure::of($error));

            return;
        }
        if ($handler === null) {
            // Retrying cannot make a handler appear.
            $this->failAll($rows, Failure::permanent(strtr(self::MESSAGE_NO_HANDLER, ['%type%' => $eventType])));

            return;
        }

        $this->send($handler, $rows);
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    private function send(HandlerInterface $handler, array $rows): void
    {
        try {
            $outcomes = $handler->handle($rows);
        } catch (\Throwable $error) {
            if (count($rows) === 1) {
                $this->queue->markFailed($rows[0], Failure::of($error));

                return;
            }
            foreach ($rows as $row) {
                $this->send($handler, [$row]);
            }

            return;
        }

        $exchanges = $handler instanceof ExchangeSource ? $handler->exchanges() : [];
        // First of all the rows that went out: a write that fails below must not leave one of them
        // to be sent again.
        $sent = array_values(array_filter($rows, static fn (array $row): bool => ($outcomes[(int) $row['id']] ?? null) === true));
        $waiting = [];
        try {
            foreach ($rows as $row) {
                $outcome = $outcomes[(int) $row['id']] ?? null;
                $exchange = $exchanges[(int) $row['id']] ?? null;
                if ($exchange !== null) {
                    $this->queue->recordExchange($row, $exchange['request'], $exchange['response']);
                }
                if ($outcome === true) {
                    continue;
                }
                if ($outcome instanceof Pending) {
                    $waiting[] = $row;
                } elseif ($outcome instanceof Deferred) {
                    $this->queue->defer($row, $outcome);
                } elseif ($outcome instanceof Skipped) {
                    $this->queue->markSkipped($row, $outcome->reason());
                } elseif ($outcome instanceof Failure) {
                    $this->queue->markFailed($row, $outcome);
                } elseif ($outcome instanceof \Throwable) {
                    $this->queue->markFailed($row, Failure::of($outcome));
                } else {
                    $this->queue->markFailed($row, Failure::temporary(self::MESSAGE_NO_OUTCOME));
                }
            }
        } finally {
            $this->queue->markSent($sent);
        }
        $this->queue->release($waiting);
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    private function failAll(array $rows, Failure $failure): void
    {
        foreach ($rows as $row) {
            $this->queue->markFailed($row, $failure);
        }
    }
}
