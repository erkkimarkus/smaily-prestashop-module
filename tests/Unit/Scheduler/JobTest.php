<?php
declare(strict_types=1);

namespace Tests\Unit\Scheduler;

use PHPUnit\Framework\TestCase;
use PrestaShop\Module\SmailyForPrestaShop\Scheduler\Deadline;
use PrestaShop\Module\SmailyForPrestaShop\Scheduler\Job;
use PrestaShop\Module\SmailyForPrestaShop\Scheduler\TickState;

/**
 * A job is due when it never ran, or when its interval has passed since it last ran. A cron line
 * that fires a little early still finds a job of its own interval due.
 */
final class JobTest extends TestCase
{
    public function testAJobThatNeverRanIsDue(): void
    {
        self::assertTrue(self::job(60)->isDue(TickState::fromJson(null), 1000));
    }

    public function testAJobIsDueOnceItsIntervalHasPassed(): void
    {
        $state = TickState::fromJson(null)->withJobRun('test', 1000);

        self::assertFalse(self::job(60)->isDue($state, 1030));
        self::assertTrue(self::job(60)->isDue($state, 1060));
        self::assertFalse(self::job(86400)->isDue($state, 1060));
        self::assertTrue(self::job(86400)->isDue($state, 1000 + 86400));
    }

    public function testAJobIsDueAFewSecondsEarlyForCronJitter(): void
    {
        $state = TickState::fromJson(null)->withJobRun('test', 1000);

        self::assertTrue(self::job(60)->isDue($state, 1000 + 60 - Job::GRACE_SECONDS));
        self::assertFalse(self::job(60)->isDue($state, 1000 + 60 - Job::GRACE_SECONDS - 1));
    }

    public function testRunsItsWorkWithTheDeadline(): void
    {
        $seen = null;
        $job = new Job('test', 60, 20, function (Deadline $deadline) use (&$seen): void {
            $seen = $deadline;
        });
        $deadline = new Deadline(10.0, fn (): float => 5.0);

        $job->run($deadline);

        self::assertSame($deadline, $seen);
        self::assertSame('test', $job->name());
        self::assertSame(20, $job->budget());
        self::assertFalse($job->runsBeforeUpgrade());
        self::assertTrue((new Job('test', 60, 20, fn () => null, true))->runsBeforeUpgrade());
    }

    public function testADeadlinePassesAtItsTime(): void
    {
        $now = 9.0;
        $deadline = new Deadline(10.0, function () use (&$now): float {
            return $now;
        });

        self::assertFalse($deadline->passed());
        $now = 10.0;
        self::assertTrue($deadline->passed());
    }

    private static function job(int $interval): Job
    {
        return new Job('test', $interval, 10, fn () => null);
    }
}
