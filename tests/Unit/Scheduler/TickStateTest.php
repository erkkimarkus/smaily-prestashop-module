<?php
declare(strict_types=1);

namespace Tests\Unit\Scheduler;

use PHPUnit\Framework\TestCase;
use PrestaShop\Module\SmailyForPrestaShop\Scheduler\TickState;

/**
 * The tick state keeps when the last tick ran and from which door, when each door last ran one, and
 * when each job last ran.
 */
final class TickStateTest extends TestCase
{
    public function testAnEmptyOrUnreadableValueHoldsNoTick(): void
    {
        foreach ([null, '', 'not json', '[1, 2]'] as $stored) {
            $state = TickState::fromJson($stored);

            self::assertNull($state->lastTickAt());
            self::assertNull($state->lastSource());
            self::assertNull($state->lastTickFrom('tick'));
            self::assertNull($state->jobRanAt('queue_flush'));
        }
    }

    public function testRecordsTheLastTickAndTheLastTickOfEachSource(): void
    {
        $state = TickState::fromJson(null)
            ->withTick('customer_cron', 1000)
            ->withTick('traffic', 1100);

        self::assertSame(1100, $state->lastTickAt());
        self::assertSame('traffic', $state->lastSource());
        self::assertSame(1000, $state->lastTickFrom('customer_cron'));
        self::assertSame(1100, $state->lastTickFrom('traffic'));
        self::assertNull($state->lastTickFrom('tick'));
    }

    public function testRecordsWhenEachJobRan(): void
    {
        $state = TickState::fromJson(null)->withJobRun('queue_flush', 1000)->withJobRun('queue_janitor', 1001);

        self::assertSame(1000, $state->jobRanAt('queue_flush'));
        self::assertSame(1001, $state->jobRanAt('queue_janitor'));
    }

    public function testSurvivesItsJson(): void
    {
        $state = TickState::fromJson(null)
            ->withTick('tick', 1000)
            ->withTick('traffic', 1200)
            ->withJobRun('queue_flush', 1200);

        $read = TickState::fromJson($state->toJson());

        self::assertSame(1200, $read->lastTickAt());
        self::assertSame('traffic', $read->lastSource());
        self::assertSame(1000, $read->lastTickFrom('tick'));
        self::assertSame(1200, $read->jobRanAt('queue_flush'));
    }

    public function testLeavesTheStateItCameFromUnchanged(): void
    {
        $empty = TickState::fromJson(null);

        $empty->withTick('tick', 1000)->withJobRun('queue_flush', 1000);

        self::assertNull($empty->lastTickAt());
        self::assertNull($empty->jobRanAt('queue_flush'));
    }
}
