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

/**
 * The reminder state of each cart, one row per cart in `smaily_connect_cart`:
 *  - `open`: the cart's reminder is not done. Without `event_id`, a row holds only an address that
 *    a checkout recorded (capture()); with it, the reminder waits in the event queue.
 *  - `mailed`: Smaily took the reminder at `mailed_at` (also every reminder that 2.x sent, which
 *    the upgrade carried over with `email` NULL).
 *  - `skipped`: no reminder goes for the cart; `skip_reason` says why.
 * A cart that has a `mailed` or `skipped` row is never reminded.
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
        \Db::getInstance()->execute(sprintf(
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
     * @return array<string, string|null>|null the cart's row, or null when it has none
     */
    public function row(int $idCart): ?array
    {
        $rows = \Db::getInstance()->executeS(
            sprintf('SELECT * FROM %s WHERE `id_cart` = %d', self::table(), $idCart),
            true,
            false
        );

        return is_array($rows) && $rows !== [] ? $rows[0] : null;
    }

    /**
     * Records the cart as open with its address before its reminder is queued.
     *
     * @param string $lastActivity the cart's last change, `Y-m-d H:i:s` in the shop's time
     */
    public function open(int $idCart, int $idShop, ?int $idCustomer, string $email, int $idLang, string $lastActivity): void
    {
        \Db::getInstance()->execute(sprintf(
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
        \Db::getInstance()->execute(sprintf(
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
        \Db::getInstance()->execute(sprintf(
            'UPDATE %s SET `status` = \'%s\', `skip_reason` = %s WHERE `id_cart` = %d AND `status` = \'%s\'',
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
        \Db::getInstance()->execute(sprintf(
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
        $rows = \Db::getInstance()->executeS(
            sprintf(
                'SELECT `id_cart` FROM %s WHERE `email` = %s AND `status` = \'%s\' UNION SELECT `id_cart` FROM %s WHERE `id_cart` = %d AND `status` = \'%s\'',
                self::table(),
                self::quote(trim($email)),
                self::OPEN,
                self::table(),
                $idCart,
                self::OPEN
            ),
            true,
            false
        );
        $ids = is_array($rows) ? array_map(static fn (array $row): int => (int) $row['id_cart'], $rows) : [];
        foreach ($ids as $id) {
            $this->skip($id, $reason);
        }

        return count($ids);
    }

    /**
     * What blocks a new reminder now: the addresses (lower case) and the customers (2.x rows name
     * only a customer) of the reminders sent in the last WINDOW_SECONDS, and the addresses of the
     * reminders that wait in the queue.
     *
     * @return array{emails: array<string, true>, customers: array<int, true>}
     */
    public function blocking(): array
    {
        // A reminder goes within WINDOW_SECONDS of the cart's last change, so the status index
        // finds every reminder of the last window among the carts changed in the last two.
        $rows = \Db::getInstance()->executeS(
            sprintf(
                'SELECT `email`, `id_customer`, `status`, `mailed_at`, `event_id` FROM %s WHERE `status` = \'%s\''
                . ' UNION ALL SELECT `email`, `id_customer`, `status`, `mailed_at`, `event_id` FROM %s'
                . ' WHERE `status` = \'%s\' AND `last_activity_at` >= %s',
                self::table(),
                self::OPEN,
                self::table(),
                self::MAILED,
                $this->time(-2 * self::WINDOW_SECONDS)
            ),
            true,
            false
        );
        $since = $this->format(-self::WINDOW_SECONDS);
        $blocking = ['emails' => [], 'customers' => []];
        foreach (is_array($rows) ? $rows : [] as $row) {
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
     * Deletes the recorded addresses that served no reminder and whose cart did not change for
     * WINDOW_SECONDS, and clears the address of a closed cart once the per-address rule no longer
     * reads it, so no address outlives its use.
     *
     * @return int the number of rows deleted or cleared
     */
    public function purge(): int
    {
        $db = \Db::getInstance();
        $stale = $this->ids(sprintf(
            '`status` = \'%s\' AND `last_activity_at` < %s AND `event_id` IS NULL',
            self::OPEN,
            $this->time(-self::WINDOW_SECONDS)
        ));
        if ($stale !== []) {
            $db->execute(sprintf(
                'DELETE FROM %s WHERE `id_cart` IN (%s) AND `status` = \'%s\' AND `event_id` IS NULL',
                self::table(),
                implode(', ', $stale),
                self::OPEN
            ));
        }
        $closed = $this->ids(sprintf(
            '`status` IN (\'%s\', \'%s\') AND `last_activity_at` < %s AND `email` IS NOT NULL',
            self::MAILED,
            self::SKIPPED,
            $this->time(-2 * self::WINDOW_SECONDS)
        ));
        if ($closed !== []) {
            $db->execute(sprintf('UPDATE %s SET `email` = NULL WHERE `id_cart` IN (%s)', self::table(), implode(', ', $closed)));
        }

        return count($stale) + count($closed);
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
        $rows = \Db::getInstance()->executeS(
            sprintf('SELECT `id_cart` FROM %s WHERE %s', self::table(), $condition),
            true,
            false
        );

        return is_array($rows) ? array_map(static fn (array $row): int => (int) $row['id_cart'], $rows) : [];
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
