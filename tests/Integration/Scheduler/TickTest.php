<?php
declare(strict_types=1);

namespace Tests\Integration\Scheduler;

use PrestaShop\Module\SmailyForPrestaShop\Config\TickSettings;
use PrestaShop\Module\SmailyForPrestaShop\Database\SqlException;
use PrestaShop\Module\SmailyForPrestaShop\Scheduler\Deadline;
use PrestaShop\Module\SmailyForPrestaShop\Scheduler\Job;
use PrestaShop\Module\SmailyForPrestaShop\Scheduler\Lock;
use PrestaShop\Module\SmailyForPrestaShop\Scheduler\Tick;
use PrestaShop\Module\SmailyForPrestaShop\Scheduler\TickReport;
use PrestaShop\Module\SmailyForPrestaShop\Scheduler\TickState;
use Tests\Integration\Fixtures\FailingQueries;
use Tests\Integration\IntegrationTestCase;

require_once __DIR__ . '/../Fixtures/FailingQueries.php';

/**
 * The tick runs the due jobs in order within its budget and each job within its own, under a lock
 * that a second tick cannot take, and records in the tick state when it ran, from which source,
 * and when each job ran.
 */
final class TickTest extends IntegrationTestCase
{
    private const START = 1893456000; // 2030-01-01 00:00:00 UTC

    private float $now = self::START;

    private bool $schemaIsCurrent = true;

    /** @var list<string> */
    private array $ran = [];

    /** @var list<string> */
    private array $failures = [];

    /** @var string|false */
    private $savedState = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->savedState = self::storedValue(TickSettings::TICK_STATE);
        \Configuration::deleteByName(TickSettings::TICK_STATE);
    }

    protected function tearDown(): void
    {
        if (defined('_PS_ROOT_DIR_')) {
            \Configuration::deleteByName(TickSettings::TICK_STATE);
            if ($this->savedState !== false) {
                \Configuration::updateGlobalValue(TickSettings::TICK_STATE, $this->savedState);
            }
        }
        parent::tearDown();
    }

    public function testRunsTheDueJobsInOrderAndRecordsTheTick(): void
    {
        (new TickSettings())->saveState(TickState::fromJson(null)->withJobRun('daily', self::START - 3600));

        $report = $this->tick([$this->job('minute', 60), $this->job('daily', 86400), $this->job('second', 60)])
            ->run('customer_cron', 50);

        self::assertSame(TickReport::RAN, $report->status());
        self::assertSame(['minute', 'second'], $report->jobs());
        self::assertSame(['minute', 'second'], $this->ran);
        $state = (new TickSettings())->storedState();
        self::assertSame(self::START, $state->lastTickAt());
        self::assertSame('customer_cron', $state->lastSource());
        self::assertSame(self::START, $state->lastTickFrom('customer_cron'));
        self::assertSame(self::START, $state->jobRanAt('minute'));
        self::assertSame(self::START - 3600, $state->jobRanAt('daily'), 'a job that was not due keeps its last run');
    }

    public function testRunsAJobAgainOnlyAfterItsInterval(): void
    {
        $tick = $this->tick([$this->job('minute', 60)]);

        $tick->run('tick', 50);
        $this->now += 30;
        self::assertSame([], $tick->run('tick', 50)->jobs());
        $this->now += 30;
        self::assertSame(['minute'], $tick->run('tick', 50)->jobs());
        self::assertSame(['minute', 'minute'], $this->ran);
    }

    public function testGivesEachJobItsBudgetWithinTheTicksBudget(): void
    {
        $deadlines = [];
        $spend = function (int $seconds) use (&$deadlines): callable {
            return function (Deadline $deadline) use ($seconds, &$deadlines): void {
                $deadlines[] = [$deadline->passed(), $this->advance($seconds - 1, $deadline), $this->advance(1, $deadline)];
            };
        };
        $tick = $this->tick([
            new Job('first', 60, 20, $spend(20)),
            new Job('second', 60, 30, $spend(25)),
            new Job('third', 60, 5, $spend(1)),
        ]);

        $report = $tick->run('tick', 45);

        // first: its own 20 s; second: the 25 s left of the tick's 45 s, not its own 30 s; third:
        // no time left.
        self::assertSame([[false, false, true], [false, false, true]], $deadlines);
        self::assertSame(['first', 'second'], $report->jobs());
        self::assertNull((new TickSettings())->storedState()->jobRanAt('third'), 'a job left without time stays due');
    }

    public function testAJobThatThrowsFailsAlone(): void
    {
        $tick = $this->tick([
            new Job('broken', 60, 10, function (): void {
                throw new \RuntimeException('broken job');
            }),
            $this->job('healthy', 60),
        ]);

        $report = $tick->run('tick', 50);

        self::assertSame(['broken', 'healthy'], $report->jobs());
        self::assertSame(['healthy'], $this->ran);
        self::assertSame(['broken: broken job'], $this->failures);
        self::assertSame(self::START, (new TickSettings())->storedState()->jobRanAt('broken'), 'it waits for its interval');
        self::assertTrue($this->lockIsFree());
    }

    public function testRunsOnlyTheJobsMadeForItWhileTheUpgradeHasNotFinished(): void
    {
        $this->schemaIsCurrent = false;
        $tick = $this->tick([$this->job('queue', 60), new Job('migration', 60, 10, function (): void {
            $this->ran[] = 'migration';
        }, true)]);

        $report = $tick->run('cart_cron', 50);

        self::assertSame(TickReport::INERT, $report->status());
        self::assertSame(['migration'], $report->jobs());
        self::assertSame(['migration'], $this->ran);
    }

    public function testATickThatFinishesTheUpgradeReportsThatItRanAndLeavesTheOtherJobsForTheNext(): void
    {
        $this->schemaIsCurrent = false;
        $tick = $this->tick([new Job('migration', 60, 10, function (): void {
            $this->ran[] = 'migration';
            $this->schemaIsCurrent = true;
        }, true), $this->job('queue', 60)]);

        $report = $tick->run('cart_cron', 50);

        self::assertSame(TickReport::RAN, $report->status());
        self::assertSame(['migration'], $report->jobs());
        self::assertSame(['migration'], $this->ran);
    }

    public function testAStaleOnlyTickDoesNothingWhenAnotherTickRanRecently(): void
    {
        (new TickSettings())->saveState(TickState::fromJson(null)->withTick('tick', self::START - 59));

        $report = $this->tick([$this->job('minute', 60)])->run('traffic', 10, 60);

        self::assertSame(TickReport::FRESH, $report->status());
        self::assertSame([], $this->ran);
        self::assertSame('tick', (new TickSettings())->storedState()->lastSource());

        $this->now += 1;
        self::assertSame(TickReport::RAN, $this->tick([$this->job('minute', 60)])->run('traffic', 10, 60)->status());
        self::assertSame('traffic', (new TickSettings())->storedState()->lastSource());
    }

    public function testASecondTickReturnsWithoutWorkWhileTheFirstHoldsTheLock(): void
    {
        $holder = proc_open(
            [PHP_BINARY, __DIR__ . '/lock-holder.php'],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );
        self::assertIsResource($holder);
        try {
            $line = fgets($pipes[1]);
            self::assertSame("locked\n", $line, $line === false ? (string) stream_get_contents($pipes[2]) : '');

            $report = $this->tick([$this->job('minute', 60)])->run('tick', 50);

            self::assertSame(TickReport::BUSY, $report->status());
            self::assertSame([], $this->ran);
            self::assertNull((new TickSettings())->storedState()->lastTickAt(), 'a busy tick records nothing');
        } finally {
            fclose($pipes[0]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($holder);
        }

        self::assertSame(TickReport::RAN, $this->tick([$this->job('minute', 60)])->run('tick', 50)->status());
    }

    public function testReleasesTheLockAfterTheTick(): void
    {
        $this->tick([$this->job('minute', 60)])->run('tick', 50);

        self::assertTrue($this->lockIsFree());
    }

    /**
     * The tick reads its state from the table and writes it back with its own time. A read that
     * fails (facts file 6.11) is not an empty state: taken as one, the tick stored a state without
     * the other sources and jobs, and every job ran again at once.
     */
    public function testAFailedReadOfTheTickStateOverwritesNothingAndRunsNoJob(): void
    {
        (new TickSettings())->saveState(TickState::fromJson(null)->withJobRun('daily', self::START - 3600));
        $stored = self::storedValue(TickSettings::TICK_STATE);
        $tick = $this->tick([$this->job('minute', 60), $this->job('daily', 86400)]);

        try {
            FailingQueries::during(
                '/SELECT `value` FROM `[^`]+configuration` WHERE `name` = \'' . TickSettings::TICK_STATE . '\'/',
                static function () use ($tick): void {
                    $tick->run('tick', 50);
                }
            );
            self::fail('The tick went on without its state.');
        } catch (SqlException $error) {
            self::assertSame([], $this->ran);
        }

        \Configuration::resetStaticCache();
        self::assertSame($stored, self::storedValue(TickSettings::TICK_STATE));
        self::assertTrue($this->lockIsFree(), 'the failed tick released its lock');

        self::assertSame(['minute'], $tick->run('tick', 50)->jobs(), 'the next tick runs what is due');
        self::assertSame(self::START - 3600, (new TickSettings())->storedState()->jobRanAt('daily'));
    }

    /**
     * A tick that cannot ask for its lock does not run: a lock that the database did not answer
     * for is not held.
     */
    public function testATickWhoseLockCouldNotBeAskedForDoesNotRun(): void
    {
        $tick = $this->tick([$this->job('minute', 60)]);

        $failed = FailingQueries::during('/SELECT GET_LOCK/', function () use ($tick): void {
            self::assertSame(TickReport::BUSY, $tick->run('tick', 50)->status());
        });

        self::assertSame(1, $failed);
        self::assertSame([], $this->ran);
    }

    /**
     * @param list<Job> $jobs
     */
    private function tick(array $jobs): Tick
    {
        return new Tick(
            new Lock(),
            new TickSettings(),
            $jobs,
            fn (): bool => $this->schemaIsCurrent,
            fn (): float => $this->now,
            function (string $job, \Throwable $error): void {
                $this->failures[] = $job . ': ' . $error->getMessage();
            }
        );
    }

    private function job(string $name, int $interval): Job
    {
        return new Job($name, $interval, 10, function () use ($name): void {
            $this->ran[] = $name;
        });
    }

    /**
     * Lets $seconds pass, and tells whether $deadline passed by then.
     */
    private function advance(int $seconds, Deadline $deadline): bool
    {
        $this->now += $seconds;

        return $deadline->passed();
    }

    private function lockIsFree(): bool
    {
        return (int) self::db()->getValue('SELECT IS_FREE_LOCK(\'' . Lock::name() . '\')', false) === 1;
    }

    /**
     * The stored global value of $key, or false when none is stored. Configuration::get() loads the
     * configuration again after a deleteByName(); hasKey() does not.
     *
     * @return string|false
     */
    private static function storedValue(string $key)
    {
        $value = \Configuration::getGlobalValue($key);

        return $value === false ? false : (string) $value;
    }
}
