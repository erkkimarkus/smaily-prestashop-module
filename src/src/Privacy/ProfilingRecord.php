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

use PrestaShop\Module\SmailyForPrestaShop\Database\Sql;
use PrestaShop\Module\SmailyForPrestaShop\Queue\ContactEntity;

/**
 * The store's own record of each shopper's choice about personalization, in the table
 * smaily_connect_privacy: one row per address, under the address's keyed hash
 * (Queue\ContactEntity::key()), never under the address itself. The row holds the newest choice,
 * its UTC time and where it came from. A shopper without a row made no choice and is profiled
 * (the model is opt-out, as in the sibling connectors).
 *
 * The row is the installation's: one address has one choice in every shop. A write replaces the
 * row, so of two choices the later write stays.
 *
 * An erasure keeps the row: it holds no address, and without it a shopper who opted out would be
 * profiled again as soon as the address comes back with a new order.
 */
class ProfilingRecord
{
    public const TABLE = 'smaily_connect_privacy';

    /** The shopper's own choice on the account page. */
    public const SOURCE_ACCOUNT = 'account';

    /** The shopper left marketing, which also stops profiling. */
    public const SOURCE_UNSUBSCRIBE = 'unsubscribe';

    /** The shopper joined marketing again, which lifts an opt-out that came from leaving it. */
    public const SOURCE_SUBSCRIBE = 'subscribe';

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
     * The record of an address, or null when the shopper made no choice.
     *
     * @return array{opted_out: bool, changed_at: string, source: string}|null `changed_at` in UTC
     */
    public function find(string $email): ?array
    {
        $row = Sql::row(sprintf(
            'SELECT `opted_out`, `changed_at`, `source` FROM `%s%s` WHERE `address_key` = \'%s\'',
            _DB_PREFIX_,
            self::TABLE,
            pSQL(ContactEntity::key($email))
        ));

        return $row === null ? null : [
            'opted_out' => (int) $row['opted_out'] === 1,
            'changed_at' => (string) $row['changed_at'],
            'source' => (string) $row['source'],
        ];
    }

    /**
     * Whether the store holds an opt-out for the address.
     */
    public function optedOut(string $email): bool
    {
        $record = $this->find($email);

        return $record !== null && $record['opted_out'];
    }

    /**
     * Records the choice of an address as its newest one.
     *
     * @param string $source one of the SOURCE_ constants
     *
     * @return string the UTC time of the choice, `Y-m-d\TH:i:s\Z`
     */
    public function write(string $email, bool $optedOut, string $source): string
    {
        $now = ($this->clock)();
        Sql::write(sprintf(
            'INSERT INTO `%s%s` (`address_key`, `opted_out`, `changed_at`, `source`) VALUES (\'%s\', %d, \'%s\', \'%s\')'
            . ' ON DUPLICATE KEY UPDATE `opted_out` = VALUES(`opted_out`), `changed_at` = VALUES(`changed_at`), `source` = VALUES(`source`)',
            _DB_PREFIX_,
            self::TABLE,
            pSQL(ContactEntity::key($email)),
            $optedOut ? 1 : 0,
            gmdate('Y-m-d H:i:s', $now),
            pSQL($source)
        ));

        return gmdate('Y-m-d\TH:i:s\Z', $now);
    }
}
