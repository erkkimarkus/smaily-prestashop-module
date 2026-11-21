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

namespace PrestaShop\Module\SmailyForPrestaShop\Contacts\Import;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * The rows of the job table (ARCHITECTURE 2.2) that the contact imports keep: a job's type, its
 * shop (0 = every shop), its status, its cursor and its counts. Every write names its row by
 * primary key; a write that names the statuses it starts from leaves a row in any other status as
 * it is, so a stop that the admin makes during a tick is never overwritten. Every time is UTC.
 */
final class JobStore
{
    public const TABLE = 'smaily_connect_job';

    public const STATUS_QUEUED = 'queued';

    public const STATUS_RUNNING = 'running';

    public const STATUS_DONE = 'done';

    public const STATUS_FAILED = 'failed';

    public const STATUS_CANCELLED = 'cancelled';

    /** The statuses of a job that the tick still runs. */
    public const ACTIVE = [self::STATUS_QUEUED, self::STATUS_RUNNING];

    /** The statuses of a job that the tick no longer runs. */
    public const FINISHED = [self::STATUS_DONE, self::STATUS_FAILED, self::STATUS_CANCELLED];

    /**
     * @return array<string, mixed>|null
     */
    public function find(int $id): ?array
    {
        $row = \Db::getInstance()->getRow('SELECT * FROM ' . self::table() . ' WHERE `id` = ' . $id, false);

        return is_array($row) ? $row : null;
    }

    /**
     * The newest job of a type and shop.
     *
     * @return array<string, mixed>|null
     */
    public function latest(string $type, int $idShop): ?array
    {
        $row = \Db::getInstance()->getRow(
            'SELECT * FROM ' . self::table() . ' WHERE `type` = \'' . pSQL($type) . '\' AND `id_shop` = ' . $idShop
            . ' ORDER BY `id` DESC',
            false
        );

        return is_array($row) ? $row : null;
    }

    /**
     * The oldest job of one of the types that is still to run.
     *
     * @param list<string> $types
     *
     * @return array<string, mixed>|null
     */
    public function next(array $types): ?array
    {
        $row = \Db::getInstance()->getRow(
            'SELECT * FROM ' . self::table()
            . ' WHERE `status` IN (' . self::quoteAll(self::ACTIVE) . ') AND `type` IN (' . self::quoteAll($types) . ')'
            . ' ORDER BY `id`',
            false
        );

        return is_array($row) ? $row : null;
    }

    /**
     * @param array<string, int> $counts
     */
    public function create(string $type, int $idShop, string $status, array $counts, ?string $lastError = null): int
    {
        $now = self::now();
        $finished = in_array($status, self::ACTIVE, true) ? null : $now;
        \Db::getInstance()->execute(
            'INSERT INTO ' . self::table() . ' (`type`, `id_shop`, `status`, `counts`, `started_at`, `updated_at`, `finished_at`, `last_error`)'
            . ' VALUES (' . self::quote($type) . ', ' . $idShop . ', ' . self::quote($status) . ', ' . self::quote((string) json_encode($counts))
            . ', ' . self::quote($finished) . ', ' . self::quote($now) . ', ' . self::quote($finished) . ', ' . self::quote($lastError) . ')'
        );

        return (int) \Db::getInstance()->Insert_ID();
    }

    /**
     * Writes the given columns and `updated_at`. `counts` is given as an array.
     *
     * @param array<string, mixed> $columns
     * @param list<string> $fromStatuses the statuses the row must be in; none = any
     */
    public function update(int $id, array $columns, array $fromStatuses = []): void
    {
        $columns['updated_at'] = self::now();
        if (isset($columns['counts'])) {
            $columns['counts'] = (string) json_encode($columns['counts']);
        }
        $assignments = [];
        foreach ($columns as $column => $value) {
            $assignments[] = '`' . $column . '` = ' . self::quote($value === null ? null : (string) $value);
        }
        \Db::getInstance()->execute(
            'UPDATE ' . self::table() . ' SET ' . implode(', ', $assignments) . ' WHERE `id` = ' . $id
            . ($fromStatuses === [] ? '' : ' AND `status` IN (' . self::quoteAll($fromStatuses) . ')')
        );
    }

    /**
     * Deletes up to $limit finished jobs, oldest first, that last changed more than $ageSeconds ago.
     *
     * @return int the number of rows deleted
     */
    public function deleteFinished(int $ageSeconds, int $limit): int
    {
        $condition = '`status` IN (' . self::quoteAll(self::FINISHED) . ')'
            . ' AND `updated_at` < ' . self::quote(gmdate('Y-m-d H:i:s', time() - $ageSeconds));
        $rows = \Db::getInstance()->executeS(
            'SELECT `id` FROM ' . self::table() . ' WHERE ' . $condition . ' ORDER BY `id` LIMIT ' . $limit,
            true,
            false
        );
        if (!is_array($rows) || $rows === []) {
            return 0;
        }

        \Db::getInstance()->execute(
            'DELETE FROM ' . self::table() . ' WHERE `id` IN (' . implode(', ', array_map('intval', array_column($rows, 'id'))) . ')'
            . ' AND ' . $condition
        );

        return (int) \Db::getInstance()->Affected_Rows();
    }

    public static function now(): string
    {
        return gmdate('Y-m-d H:i:s');
    }

    private static function table(): string
    {
        return '`' . _DB_PREFIX_ . self::TABLE . '`';
    }

    private static function quote(?string $value): string
    {
        return $value === null ? 'NULL' : '\'' . pSQL($value) . '\'';
    }

    /**
     * @param list<string> $values
     */
    private static function quoteAll(array $values): string
    {
        return implode(', ', array_map([self::class, 'quote'], $values));
    }
}
