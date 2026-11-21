<?php
declare(strict_types=1);

namespace Tests\Unit\Database;

use PHPUnit\Framework\TestCase;
use PrestaShop\Module\SmailyForPrestaShop\Database\Sql;
use PrestaShop\Module\SmailyForPrestaShop\Database\SqlException;

/**
 * The module's database gateway tells a failed query from an empty answer. A live PrestaShop 9.2.0
 * store answers `false` for a query that failed and throws nothing (facts file 6.11); PrestaShop
 * 8.2.8 throws. Either way the gateway throws SqlException, and an answer without rows stays an
 * answer.
 */
final class SqlTest extends TestCase
{
    protected function tearDown(): void
    {
        Sql::useConnection(null);
    }

    public function testAnswersTheRowsOfAQueryThatRan(): void
    {
        $db = self::connection([[['id' => '1'], ['id' => '2']]]);

        self::assertSame([['id' => '1'], ['id' => '2']], Sql::rows('SELECT `id` FROM `t`'));
        self::assertSame([['SELECT `id` FROM `t`', true, false]], $db->calls, 'read past the query cache');
    }

    public function testAnAnswerWithoutRowsIsNotAFailure(): void
    {
        self::connection([[], [], []]);

        self::assertSame([], Sql::rows('SELECT 1 FROM `t`'));
        self::assertNull(Sql::row('SELECT * FROM `t`'));
        self::assertNull(Sql::value('SELECT `name` FROM `t`'));
    }

    public function testReadsOneRowWithTheLimitThatPrestaShopAdds(): void
    {
        $db = self::connection([[['id' => '7', 'name' => 'first']], [['name' => 'first']], [['name' => null]], [['n' => 0]]]);

        self::assertSame(['id' => '7', 'name' => 'first'], Sql::row("SELECT * FROM `t` ORDER BY `id`;\n"));
        self::assertSame('first', Sql::value('SELECT `name` FROM `t`'));
        self::assertNull(Sql::value('SELECT `name` FROM `t`'), 'a column that holds NULL');
        self::assertSame('0', Sql::value('SELECT 0 AS `n`'), 'a value is text, as the database link of PHP 8.0 answers it');
        self::assertSame('SELECT * FROM `t` ORDER BY `id` LIMIT 1', $db->calls[0][0]);
        self::assertSame('SELECT `name` FROM `t` LIMIT 1', $db->calls[1][0]);
    }

    public function testCountsWhatACountAnswers(): void
    {
        self::connection([[['COUNT(*)' => '0']], [['COUNT(*)' => '12']]]);

        self::assertSame(0, Sql::count('SELECT COUNT(*) FROM `t`'));
        self::assertSame(12, Sql::count('SELECT COUNT(*) FROM `t`'));
    }

    /**
     * @return array<string, array{callable(): mixed}>
     */
    public function reads(): array
    {
        return [
            'rows' => [static fn () => Sql::rows('SELECT 1 FROM `t`')],
            'one row' => [static fn () => Sql::row('SELECT * FROM `t`')],
            'one value' => [static fn () => Sql::value('SELECT `name` FROM `t`')],
            'a count' => [static fn () => Sql::count('SELECT COUNT(*) FROM `t`')],
        ];
    }

    /**
     * @dataProvider reads
     */
    public function testAReadThatTheDriverAnswersWithFalseThrows(callable $read): void
    {
        self::connection([false]);

        $this->expectException(SqlException::class);
        $read();
    }

    /**
     * @dataProvider reads
     */
    public function testAReadThatTheDriverThrowsOnThrowsTheModulesError(callable $read): void
    {
        $failure = new \RuntimeException('SQLSTATE[HY000]: Lock wait timeout exceeded: SELECT … \'shopper@example.test\'');
        self::connection([$failure]);

        try {
            $read();
            self::fail('The read did not throw.');
        } catch (SqlException $error) {
            self::assertSame($failure, $error->getPrevious());
            self::assertSame('A database query failed.', $error->getMessage(), 'the message carries no statement');
        }
    }

    public function testACountWithoutARowIsAFailure(): void
    {
        self::connection([[]]);

        $this->expectException(SqlException::class);
        Sql::count('SELECT COUNT(*) FROM `t`');
    }

    public function testAWriteThatRanReturns(): void
    {
        $db = self::connection([true, true, true]);
        $db->affected = 3;
        $db->insertId = 41;

        Sql::write('UPDATE `t` SET `a` = 1');
        Sql::update('t', ['a' => '1'], '`id` = 1');
        Sql::delete('t', '`id` = 1');

        self::assertSame([['UPDATE `t` SET `a` = 1'], ['t', ['a' => '1'], '`id` = 1'], ['t', '`id` = 1']], $db->calls);
        self::assertSame(3, Sql::affectedRows());
        self::assertSame(41, Sql::insertId());
    }

    /**
     * @return array<string, array{callable(): void}>
     */
    public function writes(): array
    {
        return [
            'a statement' => [static function (): void { Sql::write('UPDATE `t` SET `a` = 1'); }],
            'an update' => [static function (): void { Sql::update('t', ['a' => '1'], '`id` = 1'); }],
            'a delete' => [static function (): void { Sql::delete('t', '`id` = 1'); }],
        ];
    }

    /**
     * @dataProvider writes
     */
    public function testAWriteThatTheDriverAnswersWithFalseThrows(callable $write): void
    {
        self::connection([false]);

        $this->expectException(SqlException::class);
        $write();
    }

    /**
     * @dataProvider writes
     */
    public function testAWriteThatTheDriverThrowsOnThrowsTheModulesError(callable $write): void
    {
        self::connection([new \RuntimeException('Deadlock found')]);

        $this->expectException(SqlException::class);
        $write();
    }

    /**
     * A stand-in for PrestaShop's Db that gives the next of $answers to each call; an answer that
     * is a \Throwable is thrown.
     *
     * @param list<mixed> $answers
     */
    private static function connection(array $answers): object
    {
        $db = new class($answers) {
            /** @var list<mixed> */
            private array $answers;

            /** @var list<array<int, mixed>> */
            public array $calls = [];

            public int $affected = 0;

            public int $insertId = 0;

            /**
             * @param list<mixed> $answers
             */
            public function __construct(array $answers)
            {
                $this->answers = $answers;
            }

            /**
             * @return mixed
             */
            public function executeS(string $sql, bool $array = true, bool $useCache = true)
            {
                return $this->answer([$sql, $array, $useCache]);
            }

            /**
             * @return mixed
             */
            public function execute(string $sql)
            {
                return $this->answer([$sql]);
            }

            /**
             * @param array<string, mixed> $data
             *
             * @return mixed
             */
            public function update(string $table, array $data, string $where)
            {
                return $this->answer([$table, $data, $where]);
            }

            /**
             * @return mixed
             */
            public function delete(string $table, string $where)
            {
                return $this->answer([$table, $where]);
            }

            public function Affected_Rows(): int
            {
                return $this->affected;
            }

            public function Insert_ID(): int
            {
                return $this->insertId;
            }

            /**
             * @param array<int, mixed> $call
             *
             * @return mixed
             */
            private function answer(array $call)
            {
                $this->calls[] = $call;
                $answer = array_shift($this->answers);
                if ($answer instanceof \Throwable) {
                    throw $answer;
                }

                return $answer;
            }
        };
        Sql::useConnection($db);

        return $db;
    }
}
