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

namespace PrestaShop\Module\SmailyForPrestaShop\Automation\AbandonedCart;

if (!defined('_PS_VERSION_')) {
    exit;
}

use PrestaShop\Module\SmailyForPrestaShop\Database\Sql;
use PrestaShop\Module\SmailyForPrestaShop\Queue\EventQueue;

/**
 * The reminder state of each cart, one row per cart in `smaily_connect_cart`:
 *  - `open`: the cart's reminder is not done. Without `event_id`, a row holds only an address that
 *    a checkout recorded (capture()); with it, the reminder waits in the event queue, or it failed
 *    there and was parked: the row then stays `open`, as the state that the Log's "Send again" of
 *    that queue row moves on.
 *  - `mailed`: Smaily took the reminder at `mailed_at` (also every reminder that 2.x sent, which
 *    the upgrade carried over with `email` NULL).
 *  - `skipped`: no reminder goes for the cart; `skip_reason` says why.
 * A cart that has a `mailed` or `skipped` row is not reminded. Such a row is deleted
 * RETENTION_SECONDS after the cart's last change (deleteClosed()); a cart that its shopper changes
 * after that has no row, and is reminded again.
 *
 * Times are the shop's local time, as PrestaShop writes `cart.date_upd` and as the upgrade carried
 * 2.x's `date_sent` over. Every write names its rows by primary key.
 */
class CartTracker
{
    public const TABLE = 'smaily_connect_cart';

    public const OPEN = 'open';

    public const MAILED = 'mailed';

    public const SKIPPED = 'skipped';

    /** One reminder per address in this time, and no reminder for a cart older than it. */
    public const WINDOW_SECONDS = 86400;

    /** A `mailed` or `skipped` row is kept this long after its cart's last change: 30 days, as Magento. */
    public const RETENTION_SECONDS = 30 * 86400;

    /** The most a change of the clocks moves the shop's time (daylight saving). */
    private const CLOCK_SHIFT_SECONDS = 3600;

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
     * Records the address typed in a cart's checkout before the cart has a customer, and whether
     * its newsletter box is ticked, for the cart's reminder. A cart whose reminder is queued or
     * done keeps its row as it is.
     */
    public function capture(int $idCart, int $idShop, int $idLang, string $email, bool $newsletterTicked): void
    {
        $fresh = '`status` = \'' . self::OPEN . '\' AND `event_id` IS NULL';
        Sql::write(sprintf(
            'INSERT INTO %s (`id_cart`, `id_shop`, `email`, `id_lang`, `status`, `last_activity_at`, `checkout_optin`)'
            . ' VALUES (%d, %d, %s, %d, \'%s\', %s, %d)',
            self::table(),
            $idCart,
            $idShop,
            self::quote(trim($email)),
            $idLang,
            self::OPEN,
            $this->time(0),
            $newsletterTicked ? 1 : 0
        ) . ' ON DUPLICATE KEY UPDATE `email` = IF(' . $fresh . ', VALUES(`email`), `email`),'
            . ' `checkout_optin` = IF(' . $fresh . ', VALUES(`checkout_optin`), `checkout_optin`),'
            . ' `last_activity_at` = IF(' . $fresh . ', VALUES(`last_activity_at`), `last_activity_at`)');
    }

    /**
     * How many addresses the checkouts of every shop recorded in the last $seconds, counted no
     * further than $limit in each of its two parts:
     *  - the recorded addresses of that time that the sweep has not taken yet: capture() stamps
     *    the row with the time of the capture;
     *  - the recorded addresses that the sweep took in that time. The sweep replaces a row's time
     *    with the cart's last change, so such an address counts by the queue row that its tracker
     *    row names (`event_id`), for $seconds from the sweep. A tracker row holds a recorded
     *    address when it names no customer: the sweep writes the customer of every cart whose
     *    address is a customer's (CartAddress::of()).
     * An address recorded in the last $seconds is in one of the two, unless its cart was ordered,
     * got a customer of its own before the sweep (the reminder then goes to the customer's
     * address), or the sweep closed it without a queue row. Nothing else counts: not the reminder
     * of a customer's cart, with or without a skip, and not a row that EventQueue::note() added.
     *
     * The second part reads the tracker rows of the carts that can have been swept in that time,
     * through the index on `status` and `last_activity_at` (the sweep takes a cart within
     * WINDOW_SECONDS of its last change), and each one's queue row by primary key.
     */
    public function recorded(int $seconds, int $limit): int
    {
        return Sql::count(sprintf(
            'SELECT (SELECT COUNT(*) FROM (SELECT 1 FROM %1$s WHERE `status` = \'%2$s\' AND `last_activity_at` >= %3$s'
            . ' AND `event_id` IS NULL LIMIT %4$d) `waiting`)'
            . ' + (SELECT COUNT(*) FROM (SELECT 1 FROM %1$s t STRAIGHT_JOIN `%5$s` q ON q.`id` = t.`event_id`'
            . ' WHERE t.`status` IN (\'%2$s\', \'%6$s\', \'%7$s\') AND t.`last_activity_at` >= %8$s AND t.`id_customer` IS NULL'
            . ' AND q.`created_at` >= %9$s LIMIT %4$d) `taken`)',
            self::table(),
            self::OPEN,
            $this->time(-$seconds),
            $limit,
            _DB_PREFIX_ . EventQueue::TABLE,
            self::MAILED,
            self::SKIPPED,
            $this->time(-$seconds - self::WINDOW_SECONDS - self::CLOCK_SHIFT_SECONDS),
            self::quote(gmdate('Y-m-d H:i:s', ($this->clock)() - $seconds))
        ));
    }

    /**
     * @return array<string, string|null>|null the cart's row, or null when it has none
     */
    public function row(int $idCart): ?array
    {
        return Sql::rows(
            sprintf('SELECT * FROM %s WHERE `id_cart` = %d', self::table(), $idCart)
        )[0] ?? null;
    }

    /**
     * Records the cart as open with its address before its reminder is queued.
     *
     * @param string $lastActivity the cart's last change, `Y-m-d H:i:s` in the shop's time
     */
    public function open(int $idCart, int $idShop, ?int $idCustomer, string $email, int $idLang, string $lastActivity): void
    {
        Sql::write(sprintf(
            'INSERT INTO %s (`id_cart`, `id_shop`, `id_customer`, `email`, `id_lang`, `status`, `last_activity_at`)'
            . ' VALUES (%d, %d, %s, %s, %d, \'%s\', %s)'
            . ' ON DUPLICATE KEY UPDATE `id_shop` = VALUES(`id_shop`), `id_customer` = VALUES(`id_customer`),'
            . ' `email` = VALUES(`email`), `id_lang` = VALUES(`id_lang`), `status` = VALUES(`status`),'
            . ' `last_activity_at` = VALUES(`last_activity_at`), `event_id` = NULL, `skip_reason` = NULL',
            self::table(),
            $idCart,
            $idShop,
            $idCustomer === null ? 'NULL' : (string) $idCustomer,
            self::quote($email),
            $idLang,
            self::OPEN,
            self::quote($lastActivity)
        ));
    }

    /**
     * Records the queue row that carries the cart's reminder.
     */
    public function queued(int $idCart, int $eventId): void
    {
        Sql::write(sprintf(
            'UPDATE %s SET `event_id` = %d WHERE `id_cart` = %d',
            self::table(),
            $eventId,
            $idCart
        ));
    }

    /**
     * Closes an open cart without a reminder.
     */
    public function skip(int $idCart, string $reason): void
    {
        Sql::write(sprintf(
            'UPDATE %s SET `status` = \'%s\', `skip_reason` = %s WHERE `id_cart` = %d AND `status` = \'%s\'',
            self::table(),
            self::SKIPPED,
            self::quote($reason),
            $idCart,
            self::OPEN
        ));
    }

    /**
     * Closes an open cart without a reminder and removes its address from the row: for a cart whose
     * address must not be kept, as its customer is gone.
     */
    public function forget(int $idCart, string $reason): void
    {
        Sql::write(sprintf(
            'UPDATE %s SET `status` = \'%s\', `skip_reason` = %s, `email` = NULL WHERE `id_cart` = %d AND `status` = \'%s\'',
            self::table(),
            self::SKIPPED,
            self::quote($reason),
            $idCart,
            self::OPEN
        ));
    }

    /**
     * Records that Smaily took the open cart's reminder now.
     */
    public function mailed(int $idCart): void
    {
        Sql::write(sprintf(
            'UPDATE %s SET `status` = \'%s\', `mailed_at` = %s WHERE `id_cart` = %d AND `status` = \'%s\'',
            self::table(),
            self::MAILED,
            $this->time(0),
            $idCart,
            self::OPEN
        ));
    }

    /**
     * Closes every open cart of $email, and the open row of the ordered cart, with $reason: an
     * order withdraws the reminders that are not sent yet, also one already queued.
     *
     * @return int the number of carts closed
     */
    public function withdraw(string $email, int $idCart, string $reason): int
    {
        $rows = Sql::rows(
            sprintf(
                'SELECT `id_cart` FROM %s WHERE `email` = %s AND `status` = \'%s\' UNION SELECT `id_cart` FROM %s WHERE `id_cart` = %d AND `status` = \'%s\'',
                self::table(),
                self::quote(trim($email)),
                self::OPEN,
                self::table(),
                $idCart,
                self::OPEN
            )
        );
        $ids = array_map(static fn (array $row): int => (int) $row['id_cart'], $rows);
        foreach ($ids as $id) {
            $this->skip($id, $reason);
        }

        return count($ids);
    }

    /**
     * What blocks a new reminder now: the addresses (lower case) and the customers (2.x rows name
     * only a customer) of the reminders sent in the last WINDOW_SECONDS, and of the reminders in
     * the queue that can still go. A reminder goes within WINDOW_SECONDS of its cart's last change
     * or never (the handler's `too_old`), so a queued reminder whose cart changed longer ago blocks
     * nothing: one that failed and was parked does not hold its address back for good.
     *
     * @return array{emails: array<string, true>, customers: array<int, true>}
     */
    public function blocking(): array
    {
        // A reminder goes within WINDOW_SECONDS of the cart's last change, so the status index
        // finds every reminder that can still go among the carts changed in the last window, and
        // every reminder of the last window among the carts changed in the last two.
        $rows = Sql::rows(
            sprintf(
                'SELECT `email`, `id_customer`, `status`, `mailed_at`, `event_id` FROM %s'
                . ' WHERE `status` = \'%s\' AND `last_activity_at` >= %s'
                . ' UNION ALL SELECT `email`, `id_customer`, `status`, `mailed_at`, `event_id` FROM %s'
                . ' WHERE `status` = \'%s\' AND `last_activity_at` >= %s',
                self::table(),
                self::OPEN,
                $this->time(-self::WINDOW_SECONDS),
                self::table(),
                self::MAILED,
                $this->time(-2 * self::WINDOW_SECONDS)
            )
        );
        $since = $this->format(-self::WINDOW_SECONDS);
        $blocking = ['emails' => [], 'customers' => []];
        foreach ($rows as $row) {
            if ($row['status'] === self::OPEN ? $row['event_id'] === null : (string) $row['mailed_at'] < $since) {
                continue;
            }
            if ((string) $row['email'] !== '') {
                $blocking['emails'][mb_strtolower(trim((string) $row['email']))] = true;
            } elseif ((int) $row['id_customer'] > 0) {
                $blocking['customers'][(int) $row['id_customer']] = true;
            }
        }

        return $blocking;
    }

    /**
     * Removes the addresses that have no use left, so no address outlives its use:
     *  - deletes the recorded addresses that served no reminder and whose cart did not change for
     *    WINDOW_SECONDS;
     *  - clears the address of a cart whose queued reminder did not go in that time, because it
     *    failed and was parked or still waits: it can no longer go. The row stays, as the state of
     *    its queue row, whose payload holds what the Log's "Send again" sends;
     *  - clears the address of a closed cart once the per-address rule no longer reads it.
     *
     * @return int the number of rows deleted or cleared
     */
    public function purge(): int
    {
        $stale = $this->ids(sprintf(
            '`status` = \'%s\' AND `last_activity_at` < %s AND `event_id` IS NULL',
            self::OPEN,
            $this->time(-self::WINDOW_SECONDS)
        ));
        if ($stale !== []) {
            Sql::write(sprintf(
                'DELETE FROM %s WHERE `id_cart` IN (%s) AND `status` = \'%s\' AND `event_id` IS NULL',
                self::table(),
                implode(', ', $stale),
                self::OPEN
            ));
        }
        $unsent = $this->ids(sprintf(
            '`status` = \'%s\' AND `last_activity_at` < %s AND `event_id` IS NOT NULL AND `email` IS NOT NULL',
            self::OPEN,
            $this->time(-self::WINDOW_SECONDS)
        ));
        $closed = $this->ids(sprintf(
            '`status` IN (\'%s\', \'%s\') AND `last_activity_at` < %s AND `email` IS NOT NULL',
            self::MAILED,
            self::SKIPPED,
            $this->time(-2 * self::WINDOW_SECONDS)
        ));
        $cleared = array_merge($unsent, $closed);
        if ($cleared !== []) {
            Sql::write(sprintf('UPDATE %s SET `email` = NULL WHERE `id_cart` IN (%s)', self::table(), implode(', ', $cleared)));
        }

        return count($stale) + count($cleared);
    }

    /**
     * Deletes up to $limit closed rows (`mailed`, `skipped`) whose cart last changed more than
     * RETENTION_SECONDS ago, by primary key: the retention of Magento's janitor for its closed
     * rows. An `open` row stays whatever its age: it is the state of a reminder that waits, or
     * that failed and was parked.
     *
     * @return int the number of rows deleted
     */
    public function deleteClosed(int $limit): int
    {
        $condition = sprintf(
            '`status` IN (\'%s\', \'%s\') AND `last_activity_at` < %s',
            self::MAILED,
            self::SKIPPED,
            $this->time(-self::RETENTION_SECONDS)
        );
        $ids = $this->ids($condition . ' LIMIT ' . $limit);
        if ($ids === []) {
            return 0;
        }
        Sql::write(sprintf('DELETE FROM %s WHERE `id_cart` IN (%s) AND %s', self::table(), implode(', ', $ids), $condition));

        return Sql::affectedRows();
    }

    /**
     * The clock's time plus $offset seconds, `Y-m-d H:i:s` in the shop's time.
     */
    public function format(int $offset): string
    {
        return date('Y-m-d H:i:s', ($this->clock)() + $offset);
    }

    /**
     * @return list<int>
     */
    private function ids(string $condition): array
    {
        $rows = Sql::rows(
            sprintf('SELECT `id_cart` FROM %s WHERE %s', self::table(), $condition)
        );

        return array_map(static fn (array $row): int => (int) $row['id_cart'], $rows);
    }

    private function time(int $offset): string
    {
        return self::quote($this->format($offset));
    }

    private static function table(): string
    {
        return '`' . _DB_PREFIX_ . self::TABLE . '`';
    }

    private static function quote(string $value): string
    {
        return '\'' . pSQL($value) . '\'';
    }
}
