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

namespace PrestaShop\Module\SmailyForPrestaShop\Database;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * The module's one door to the shop's database: every read and write of the module goes through
 * it, and each of its operations throws SqlException when the query failed.
 *
 * PrestaShop does not tell a failed query from an empty answer everywhere. On 9.2.0 with debug mode
 * off (a live store) its database link reports no error: a query that failed (a lock wait that ran
 * out, a deadlock, a lost connection) answers `false`, which Db::getRow() and Db::getValue() also
 * answer for "no row". On 8.2.8, and on 9.2.0 with debug mode on, the same failure throws. So this
 * class reads through Db::executeS(), which answers an array for every query that ran, and takes
 * anything else, and a `false` from a write, as the failure it is, on every version alike.
 *
 * No read uses PrestaShop's query cache. The SQL is the caller's, unchanged; row() and value() add
 * the `LIMIT 1` that Db::getRow() adds.
 */
final class Sql
{
    /** @var object|null what stands in for PrestaShop's Db in a test */
    private static ?object $connection = null;

    /**
     * Every row that $sql selects.
     *
     * @return list<array<string, string|null>>
     *
     * @throws SqlException when the query failed
     */
    public static function rows(string $sql): array
    {
        try {
            $rows = self::db()->executeS($sql, true, false);
        } catch (\Exception $error) {
            throw new SqlException($error);
        }
        if (!is_array($rows)) {
            throw new SqlException();
        }

        return $rows;
    }

    /**
     * The first row that $sql selects, or null when it selects none. $sql carries no LIMIT.
     *
     * @return array<string, string|null>|null
     *
     * @throws SqlException when the query failed
     */
    public static function row(string $sql): ?array
    {
        return self::rows(rtrim($sql, " \t\n\r\0\x0B;") . ' LIMIT 1')[0] ?? null;
    }

    /**
     * The first column of the first row that $sql selects; null when it selects no row, or that
     * column holds NULL. $sql carries no LIMIT.
     *
     * @throws SqlException when the query failed
     */
    public static function value(string $sql): ?string
    {
        $row = self::row($sql);
        $value = $row === null ? null : reset($row);

        return $value === null || $value === false ? null : (string) $value;
    }

    /**
     * The number that a `SELECT COUNT(*)` answers. A count always answers one row.
     *
     * @throws SqlException when the query failed
     */
    public static function count(string $sql): int
    {
        $count = self::value($sql);
        if ($count === null) {
            throw new SqlException();
        }

        return (int) $count;
    }

    /**
     * Runs a statement that selects nothing: an INSERT, UPDATE, DELETE or a table statement.
     *
     * @throws SqlException when the statement failed
     */
    public static function write(string $sql): void
    {
        try {
            $done = self::db()->execute($sql);
        } catch (\Exception $error) {
            throw new SqlException($error);
        }
        if (!$done) {
            throw new SqlException();
        }
    }

    /**
     * Updates the rows of $table (named without the prefix) that $where selects, as Db::update()
     * builds the statement.
     *
     * @param array<string, mixed> $data the columns' new values, escaped by the caller
     *
     * @throws SqlException when the statement failed
     */
    public static function update(string $table, array $data, string $where): void
    {
        try {
            $done = self::db()->update($table, $data, $where);
        } catch (\Exception $error) {
            throw new SqlException($error);
        }
        if (!$done) {
            throw new SqlException();
        }
    }

    /**
     * Deletes the rows of $table (named without the prefix) that $where selects, as Db::delete()
     * builds the statement.
     *
     * @throws SqlException when the statement failed
     */
    public static function delete(string $table, string $where): void
    {
        try {
            $done = self::db()->delete($table, $where);
        } catch (\Exception $error) {
            throw new SqlException($error);
        }
        if (!$done) {
            throw new SqlException();
        }
    }

    /**
     * The number of rows that the last write changed.
     */
    public static function affectedRows(): int
    {
        return (int) self::db()->Affected_Rows();
    }

    /**
     * The id that the last INSERT gave its row.
     */
    public static function insertId(): int
    {
        return (int) self::db()->Insert_ID();
    }

    /**
     * Makes every operation run through $connection, an object with the methods of PrestaShop's
     * Db that this class calls; null returns to PrestaShop's own. For tests, which make a chosen
     * query fail the way a live PrestaShop 9 store reports it.
     */
    public static function useConnection(?object $connection): void
    {
        self::$connection = $connection;
    }

    private static function db(): object
    {
        return self::$connection ?? \Db::getInstance();
    }
}
