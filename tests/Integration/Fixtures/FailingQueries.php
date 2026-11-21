<?php
declare(strict_types=1);

namespace Tests\Integration\Fixtures;

use PrestaShop\Module\SmailyForPrestaShop\Database\Sql;

/**
 * Makes chosen queries of the module fail the way a live PrestaShop 9.2.0 store reports a failed
 * query (facts file 6.11): the driver answers `false` and throws nothing. It stands between the
 * module's gateway (Database\Sql) and PrestaShop's Db; a statement that the pattern does not select
 * runs on the shop's database as usual, so a test behaves the same on every PrestaShop version
 * and with debug mode on or off.
 *
 * A pattern is a regular expression on the SQL text. Db::update() and Db::delete() carry no text:
 * they match as "UPDATE `<prefix><table>` WHERE <where>" and "DELETE FROM `<prefix><table>` WHERE
 * <where>".
 */
final class FailingQueries
{
    private string $pattern;

    private ?int $times;

    private int $failed = 0;

    private function __construct(string $pattern, ?int $times)
    {
        $this->pattern = $pattern;
        $this->times = $times;
    }

    /**
     * Fails every statement that $pattern selects from now on, until stop().
     *
     * @param int|null $times how many statements fail at most; null fails every one
     */
    public static function start(string $pattern, ?int $times = null): self
    {
        $failing = new self($pattern, $times);
        Sql::useConnection($failing);

        return $failing;
    }

    /**
     * Returns the gateway to the shop's database.
     *
     * @return int the number of statements that were failed
     */
    public function stop(): int
    {
        Sql::useConnection(null);

        return $this->failed;
    }

    /**
     * Runs $work while the statements that $pattern selects fail.
     *
     * @param int|null $times how many statements fail at most; null fails every one
     *
     * @return int the number of statements that were failed
     */
    public static function during(string $pattern, callable $work, ?int $times = null): int
    {
        $failing = self::start($pattern, $times);
        try {
            $work();
        } finally {
            $failing->stop();
        }

        return $failing->failed;
    }

    /**
     * @return array<int, array<string, mixed>>|false
     */
    public function executeS(string $sql, bool $array = true, bool $useCache = true)
    {
        return $this->fails($sql) ? false : \Db::getInstance()->executeS($sql, $array, $useCache);
    }

    public function execute(string $sql, bool $useCache = true): bool
    {
        return $this->fails($sql) ? false : \Db::getInstance()->execute($sql, $useCache);
    }

    /**
     * @param array<string, mixed> $data
     */
    public function update(string $table, array $data, string $where = ''): bool
    {
        return $this->fails('UPDATE `' . _DB_PREFIX_ . $table . '` WHERE ' . $where) ? false : \Db::getInstance()->update($table, $data, $where);
    }

    public function delete(string $table, string $where = ''): bool
    {
        return $this->fails('DELETE FROM `' . _DB_PREFIX_ . $table . '` WHERE ' . $where) ? false : \Db::getInstance()->delete($table, $where);
    }

    /**
     * @return int|string
     */
    public function Affected_Rows()
    {
        return \Db::getInstance()->Affected_Rows();
    }

    /**
     * @return int|string
     */
    public function Insert_ID()
    {
        return \Db::getInstance()->Insert_ID();
    }

    private function fails(string $sql): bool
    {
        if (($this->times !== null && $this->failed >= $this->times) || preg_match($this->pattern, $sql) !== 1) {
            return false;
        }
        ++$this->failed;

        return true;
    }
}
