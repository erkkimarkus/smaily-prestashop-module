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

use PrestaShop\Module\SmailyForPrestaShop\Log\PayloadRedactor;

/**
 * The durable queue of marketing events to Smaily, in the table smaily_connect_event_queue.
 *
 * A flush claims due rows with a token of its own: it reads the ids of due rows, moves those that
 * are still pending to `processing` under its token in one UPDATE, and then reads back only the rows
 * that carry the token. Two flushes that start at once therefore never hold the same row, without
 * SKIP LOCKED (which needs MySQL 8 or MariaDB 10.6); the one that comes second claims fewer rows. A
 * claim older than 10 minutes belongs to a flush that died, and goes back to `pending`. An outcome
 * is recorded only on a row that still carries the token it was claimed with.
 *
 * Every write names its rows by primary key and reaches them through it, so concurrent flushes lock
 * only those rows, in one order, and do not deadlock each other. The claim and the stale-claim return
 * force the primary key: when fewer rows are due than ids are named, MySQL and MariaDB otherwise walk
 * the status index and lock its entries of rows that other flushes are closing.
 *
 * `last_request` and `last_response` hold what the last attempt sent and what came back, for the
 * Log's Details: JSON without secrets (Log\PayloadRedactor), with the contact's data as it was sent.
 *
 * Times are UTC, read from the clock this queue is given.
 */
final class EventQueue
{
    public const TABLE = 'smaily_connect_event_queue';

    public const STATUS_PENDING = 'pending';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_SENT = 'sent';

    public const STATUS_FAILED = 'failed';

    public const STATUS_SKIPPED = 'skipped';

    public const CLAIM_TIMEOUT_SECONDS = 600;

    /**
     * The width of the column last_error.
     */
    private const ERROR_LENGTH = 255;

    /**
     * The bytes that the column last_response holds (TEXT).
     */
    private const RESPONSE_BYTES = 65535;

    /** @var callable(): int */
    private $clock;

    /**
     * @param (callable(): int)|null $clock the current Unix time; time() when null
     */
    public function __construct(?callable $clock = null)
    {
        $this->clock = $clock ?? 'time';
    }

    /**
     * Adds an event that the next flush sends.
     *
     * @param array<mixed> $payload stored as JSON
     *
     * @return int the row id
     */
    public function enqueue(string $eventType, int $idShop, array $payload, ?string $entityId = null, ?string $language = null): int
    {
        $now = $this->time(0);
        $sql = sprintf(
            'INSERT INTO %s (`id_shop`, `event_type`, `entity_id`, `language`, `payload`, `status`, `attempts`, `created_at`, `updated_at`)'
            . ' VALUES (%d, %s, %s, %s, %s, %s, 0, %s, %s)',
            self::table(),
            $idShop,
            self::quote($eventType),
            self::quote($entityId),
            self::quote($language),
            self::quote(self::json($payload)),
            self::quote(self::STATUS_PENDING),
            $now,
            $now
        );
        if (!\Db::getInstance()->execute($sql)) {
            throw new \RuntimeException('The event queue refused a row.');
        }

        return (int) \Db::getInstance()->Insert_ID();
    }

    /**
     * Adds a row that is closed as skipped with $reason at once: a line for the Log about events
     * that were never queued. Adds none while a row of that type and reason is younger than
     * $quietSeconds.
     *
     * @return bool whether a row was added
     */
    public function note(string $eventType, int $idShop, string $reason, int $quietSeconds): bool
    {
        $noted = \Db::getInstance()->getValue(sprintf(
            'SELECT 1 FROM %s WHERE `created_at` >= %s AND `event_type` = %s AND `last_error` = %s',
            self::table(),
            $this->time(-$quietSeconds),
            self::quote($eventType),
            self::quote(self::error($reason))
        ), false);
        if ($noted) {
            return false;
        }
        $now = $this->time(0);

        return \Db::getInstance()->execute(sprintf(
            'INSERT INTO %s (`id_shop`, `event_type`, `payload`, `status`, `attempts`, `last_error`, `created_at`, `updated_at`)'
            . ' VALUES (%d, %s, \'[]\', %s, 0, %s, %s, %s)',
            self::table(),
            $idShop,
            self::quote($eventType),
            self::quote(self::STATUS_SKIPPED),
            self::quote(self::error($reason)),
            $now,
            $now
        ));
    }

    /**
     * Claims up to $limit due rows, oldest first, for one flush.
     *
     * @return list<array<string, string|null>> the claimed rows as stored, status `processing`
     */
    public function claim(int $limit): array
    {
        $now = $this->time(0);
        $condition = sprintf(
            '`status` = %s AND (`next_retry_at` IS NULL OR `next_retry_at` <= %s)',
            self::quote(self::STATUS_PENDING),
            $now
        );
        $ids = $this->ids($condition . ' ORDER BY `id` LIMIT ' . $limit);
        if ($ids === []) {
            return [];
        }

        $token = self::quote(bin2hex(random_bytes(16)));
        \Db::getInstance()->execute(sprintf(
            'UPDATE %s FORCE INDEX (PRIMARY) SET `status` = %s, `claim_token` = %s, `updated_at` = %s WHERE `id` IN (%s) AND %s',
            self::table(),
            self::quote(self::STATUS_PROCESSING),
            $token,
            $now,
            implode(', ', $ids),
            $condition
        ));

        $rows = \Db::getInstance()->executeS(
            sprintf('SELECT * FROM %s WHERE `claim_token` = %s ORDER BY `id`', self::table(), $token),
            true,
            false
        );

        return is_array($rows) ? $rows : [];
    }

    /**
     * Returns the rows whose claim is older than CLAIM_TIMEOUT_SECONDS to `pending`.
     */
    public function requeueStale(): void
    {
        $condition = sprintf(
            '`status` = %s AND `updated_at` <= %s',
            self::quote(self::STATUS_PROCESSING),
            $this->time(-self::CLAIM_TIMEOUT_SECONDS)
        );
        $ids = $this->ids($condition);
        if ($ids === []) {
            return;
        }

        \Db::getInstance()->execute(sprintf(
            'UPDATE %s FORCE INDEX (PRIMARY) SET `status` = %s, `claim_token` = NULL WHERE `id` IN (%s) AND %s',
            self::table(),
            self::quote(self::STATUS_PENDING),
            implode(', ', $ids),
            $condition
        ));
    }

    /**
     * Stores on a claimed row what this attempt sent and what came back, without secrets. A null
     * $response (no answer arrived) keeps the answer of an earlier attempt. An answer too long for
     * its column keeps its HTTP status only.
     *
     * @param array<string, mixed> $row
     * @param array<int|string, mixed> $request the row's own part of the request body
     * @param array{http_status: int, body: mixed}|null $response
     */
    public function recordExchange(array $row, array $request, ?array $response): void
    {
        $assignments = '`last_request` = ' . self::quote(self::json(PayloadRedactor::redact($request)));
        if ($response !== null) {
            $stored = self::json(PayloadRedactor::redact($response));
            if (strlen($stored) > self::RESPONSE_BYTES) {
                $stored = self::json(['http_status' => $response['http_status'], 'body' => null]);
            }
            $assignments .= ', `last_response` = ' . self::quote($stored);
        }
        \Db::getInstance()->execute(sprintf(
            'UPDATE %s SET %s WHERE `id` = %d AND `claim_token` = %s',
            self::table(),
            $assignments,
            (int) $row['id'],
            self::quote((string) $row['claim_token'])
        ));
    }

    /**
     * Replaces the stored payload of a claimed row: what a handler adds to the row before it sends
     * it (an automation's discount code), so that a retry and the Log's "Send again" send the
     * same. Nothing else of the row changes. Only the flush that still holds the row's claim
     * writes: a flush whose claim went stale, and whose row another flush took over
     * (requeueStale()), writes nothing.
     *
     * @param array<string, mixed> $row the row as it was claimed
     * @param array<mixed> $payload stored as JSON
     *
     * @return bool whether the row still carries the claim it was read with, so the payload is
     *              stored; false when the row is another flush's now
     */
    public function updatePayload(array $row, array $payload): bool
    {
        $claimed = sprintf('`id` = %d AND `claim_token` = %s', (int) $row['id'], self::quote((string) $row['claim_token']));
        if (!\Db::getInstance()->execute(sprintf('UPDATE %s SET `payload` = %s WHERE %s', self::table(), self::quote(self::json($payload)), $claimed))) {
            throw new \RuntimeException('The event queue refused a payload.');
        }

        // The database counts no row whose payload was that already: then the claim itself answers.
        return \Db::getInstance()->Affected_Rows() > 0
            || (bool) \Db::getInstance()->getValue(sprintf('SELECT 1 FROM %s WHERE %s', self::table(), $claimed), false);
    }

    /**
     * Returns failed rows to `pending` with their attempts to spend anew: the Log's manual retry. A
     * row that is not failed stays as it is.
     *
     * @param list<int> $ids
     *
     * @return int the number of rows returned to pending
     */
    public function retry(array $ids): int
    {
        if ($ids === []) {
            return 0;
        }
        \Db::getInstance()->execute(sprintf(
            'UPDATE %s FORCE INDEX (PRIMARY) SET `status` = %s, `attempts` = 0, `next_retry_at` = NULL, `updated_at` = %s'
            . ' WHERE `id` IN (%s) AND `status` = %s',
            self::table(),
            self::quote(self::STATUS_PENDING),
            $this->time(0),
            implode(', ', array_map('intval', $ids)),
            self::quote(self::STATUS_FAILED)
        ));

        return (int) \Db::getInstance()->Affected_Rows();
    }

    /**
     * @param list<array<string, mixed>> $rows claimed rows
     */
    public function markSent(array $rows): void
    {
        $now = $this->time(0);
        $this->close($rows, sprintf(
            '`status` = %s, `next_retry_at` = NULL, `last_error` = NULL, `sent_at` = %s, `updated_at` = %s',
            self::quote(self::STATUS_SENT),
            $now,
            $now
        ));
    }

    /**
     * Closes a claimed row without sending it.
     *
     * @param array<string, mixed> $row
     */
    public function markSkipped(array $row, string $reason): void
    {
        $this->close([$row], sprintf(
            '`status` = %s, `next_retry_at` = NULL, `last_error` = %s, `updated_at` = %s',
            self::quote(self::STATUS_SKIPPED),
            self::quote(self::error($reason)),
            $this->time(0)
        ));
    }

    /**
     * Records a failed attempt: the row waits for its next attempt, or is parked as `failed` when
     * the failure is permanent or the attempts are spent (RetryPolicy).
     *
     * @param array<string, mixed> $row
     */
    public function markFailed(array $row, Failure $failure): void
    {
        $attempts = (int) $row['attempts'] + 1;
        $parked = RetryPolicy::parks($attempts, $failure);
        $this->close([$row], sprintf(
            '`status` = %s, `attempts` = %d, `next_retry_at` = %s, `last_error` = %s, `updated_at` = %s',
            self::quote($parked ? self::STATUS_FAILED : self::STATUS_PENDING),
            $attempts,
            $parked ? 'NULL' : $this->time(RetryPolicy::delay($attempts, $failure->retryAfter())),
            self::quote(self::error($failure->reason())),
            $this->time(0)
        ));
    }

    /**
     * Gives claimed rows back as they were: pending, with no attempt spent.
     *
     * @param list<array<string, mixed>> $rows
     */
    public function release(array $rows): void
    {
        $this->close($rows, sprintf('`status` = %s', self::quote(self::STATUS_PENDING)));
    }

    /**
     * Deletes up to $limit rows, oldest first, that are in the closed status $status (sent, skipped
     * or failed) and last changed more than $ageSeconds ago.
     *
     * @return int the number of rows deleted
     */
    public function deleteClosed(string $status, int $ageSeconds, int $limit): int
    {
        if (!in_array($status, [self::STATUS_SENT, self::STATUS_SKIPPED, self::STATUS_FAILED], true)) {
            throw new \InvalidArgumentException(sprintf('The status "%s" is not a closed one.', $status));
        }

        $condition = sprintf(
            '`status` = %s AND `updated_at` < %s',
            self::quote($status),
            $this->time(-$ageSeconds)
        );
        $ids = $this->ids($condition . ' ORDER BY `id` LIMIT ' . $limit);
        if ($ids === []) {
            return 0;
        }

        \Db::getInstance()->execute(sprintf(
            'DELETE FROM %s WHERE `id` IN (%s) AND %s',
            self::table(),
            implode(', ', $ids),
            $condition
        ));

        return (int) \Db::getInstance()->Affected_Rows();
    }

    /**
     * Sets $assignments on the rows that still carry the claim they were read with, and releases
     * the claim.
     *
     * @param list<array<string, mixed>> $rows
     */
    private function close(array $rows, string $assignments): void
    {
        if ($rows === []) {
            return;
        }

        $ids = array_map(static fn (array $row): int => (int) $row['id'], $rows);
        $tokens = array_map(static fn (array $row): string => self::quote((string) $row['claim_token']), $rows);
        \Db::getInstance()->execute(sprintf(
            'UPDATE %s SET %s, `claim_token` = NULL WHERE `id` IN (%s) AND `claim_token` IN (%s)',
            self::table(),
            $assignments,
            implode(', ', $ids),
            implode(', ', array_unique($tokens))
        ));
    }

    /**
     * The ids of the rows that $condition selects, read without a lock.
     *
     * @return list<int>
     */
    private function ids(string $condition): array
    {
        $rows = \Db::getInstance()->executeS(
            sprintf('SELECT `id` FROM %s WHERE %s', self::table(), $condition),
            true,
            false
        );

        return is_array($rows) ? array_map(static fn (array $row): int => (int) $row['id'], $rows) : [];
    }

    /**
     * The clock's time plus $offset seconds, as a quoted SQL DATETIME in UTC.
     */
    private function time(int $offset): string
    {
        return self::quote(gmdate('Y-m-d H:i:s', ($this->clock)() + $offset));
    }

    /**
     * @param array<mixed> $value
     */
    private static function json(array $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private static function error(string $reason): string
    {
        return mb_substr($reason, 0, self::ERROR_LENGTH);
    }

    private static function table(): string
    {
        return '`' . _DB_PREFIX_ . self::TABLE . '`';
    }

    private static function quote(?string $value): string
    {
        return $value === null ? 'NULL' : '\'' . pSQL($value, true) . '\'';
    }
}
