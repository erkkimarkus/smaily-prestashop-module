<?php
declare(strict_types=1);

namespace Tests\Unit\Admin\Dashboard;

use PHPUnit\Framework\TestCase;
use PrestaShop\Module\SmailyForPrestaShop\Admin\Dashboard\BackgroundTasks;
use PrestaShop\Module\SmailyForPrestaShop\Scheduler\TickState;

/**
 * The Background tasks card is green while a cron URL ran a tick in the last 5 minutes, red when no
 * tick ran in the last 15 minutes or none ever ran, and amber in between: ticks run, but only from
 * shop visits or from a cron URL that is not called every minute (ARCHITECTURE 4.3).
 */
final class BackgroundTasksTest extends TestCase
{
    private const NOW = 1763640000;

    /**
     * @return array<string, array{TickState, string}>
     */
    public function states(): array
    {
        $none = TickState::fromJson(null);

        return [
            'no tick ever' => [$none, BackgroundTasks::STOPPED],
            'the tick URL a minute ago' => [$none->withTick('tick', self::NOW - 60), BackgroundTasks::RUNNING],
            'the tick URL exactly 5 minutes ago' => [$none->withTick('tick', self::NOW - 300), BackgroundTasks::RUNNING],
            'the 2.x contact cron URL a minute ago' => [$none->withTick('customer_cron', self::NOW - 60), BackgroundTasks::RUNNING],
            'the 2.x cart cron URL a minute ago' => [$none->withTick('cart_cron', self::NOW - 60), BackgroundTasks::RUNNING],
            'a cron URL 6 minutes ago, nothing since' => [$none->withTick('tick', self::NOW - 360), BackgroundTasks::VISITORS],
            'shop visits only, a minute ago' => [$none->withTick('traffic', self::NOW - 60), BackgroundTasks::VISITORS],
            'a daily cron URL, then shop visits' => [$none->withTick('customer_cron', self::NOW - 40000)->withTick('traffic', self::NOW - 120), BackgroundTasks::VISITORS],
            'shop visits exactly 15 minutes ago' => [$none->withTick('traffic', self::NOW - 900), BackgroundTasks::VISITORS],
            'shop visits 16 minutes ago' => [$none->withTick('traffic', self::NOW - 960), BackgroundTasks::STOPPED],
            'a cron URL an hour ago' => [$none->withTick('tick', self::NOW - 3600), BackgroundTasks::STOPPED],
        ];
    }

    /**
     * @dataProvider states
     */
    public function testTheStateFollowsTheLastTicks(TickState $state, string $expected): void
    {
        self::assertSame($expected, BackgroundTasks::state($state, self::NOW));
    }

    public function testEachDoorNamesWhereTheTickCameFrom(): void
    {
        self::assertSame(BackgroundTasks::SOURCE_CRON, BackgroundTasks::source('tick'));
        self::assertSame(BackgroundTasks::SOURCE_CRON, BackgroundTasks::source('customer_cron'));
        self::assertSame(BackgroundTasks::SOURCE_CRON, BackgroundTasks::source('cart_cron'));
        self::assertSame(BackgroundTasks::SOURCE_VISITS, BackgroundTasks::source('traffic'));
    }

    public function testTheCronLineCallsTheUrlEveryMinute(): void
    {
        self::assertSame(
            '* * * * * curl -fsS "https://shop.example.test/module/smailyforprestashop/tick?token=abc" > /dev/null',
            BackgroundTasks::cronLine('https://shop.example.test/module/smailyforprestashop/tick?token=abc')
        );
    }
}
