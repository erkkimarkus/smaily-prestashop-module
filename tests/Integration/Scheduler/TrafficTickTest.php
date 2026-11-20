<?php
declare(strict_types=1);

namespace Tests\Integration\Scheduler;

use PrestaShop\Module\SmailyForPrestaShop\Config\TickSettings;
use PrestaShop\Module\SmailyForPrestaShop\Scheduler\Job;
use PrestaShop\Module\SmailyForPrestaShop\Scheduler\Lock;
use PrestaShop\Module\SmailyForPrestaShop\Scheduler\Tick;
use PrestaShop\Module\SmailyForPrestaShop\Scheduler\TickState;
use PrestaShop\Module\SmailyForPrestaShop\Scheduler\TrafficTick;
use Tests\Integration\IntegrationTestCase;

/**
 * A page request whose last tick is a minute old or older runs the tick after its response has been
 * sent, where the server can finish the response early; elsewhere it does nothing.
 */
final class TrafficTickTest extends IntegrationTestCase
{
    private const START = 1893456000; // 2030-01-01 00:00:00 UTC

    /** @var string|false */
    private $savedState = false;

    /** @var list<string> */
    private array $events = [];

    /** @var list<callable> */
    private array $shutdown = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->savedState = \Configuration::hasKey(TickSettings::TICK_STATE) ? (string) \Configuration::getGlobalValue(TickSettings::TICK_STATE) : false;
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

    public function testRunsTheTickAfterTheResponseWhenTheLastTickIsStale(): void
    {
        (new TickSettings())->saveState(TickState::fromJson(null)->withTick('tick', self::START - 60));
        $traffic = $this->traffic(true);

        $traffic->onRequest();
        $traffic->onRequest();

        self::assertSame([], $this->events, 'nothing runs during the request');
        self::assertCount(1, $this->shutdown, 'one tick per request');
        ($this->shutdown[0])();
        self::assertSame(['response finished', 'job'], $this->events);
        $state = (new TickSettings())->storedState();
        self::assertSame(Tick::SOURCE_TRAFFIC, $state->lastSource());
        self::assertSame(self::START, $state->lastTickFrom(Tick::SOURCE_TRAFFIC));
    }

    public function testRunsTheTickWhenNoTickEverRan(): void
    {
        $this->traffic(true)->onRequest();

        self::assertCount(1, $this->shutdown);
    }

    public function testDoesNothingWhileTheLastTickIsYoungerThanAMinute(): void
    {
        (new TickSettings())->saveState(TickState::fromJson(null)->withTick('tick', self::START - 59));

        $this->traffic(true)->onRequest();

        self::assertSame([], $this->shutdown);
    }

    public function testDoesNothingWhereTheResponseCannotBeFinishedEarly(): void
    {
        $this->traffic(false)->onRequest();

        self::assertSame([], $this->shutdown);
    }

    public function testDoesNothingAfterTheResponseWhenAnotherTickRanMeanwhile(): void
    {
        $traffic = $this->traffic(true);
        $traffic->onRequest();
        (new TickSettings())->saveState(TickState::fromJson(null)->withTick('customer_cron', self::START - 1));

        ($this->shutdown[0])();

        self::assertSame(['response finished'], $this->events);
        self::assertSame('customer_cron', (new TickSettings())->storedState()->lastSource());
    }

    private function traffic(bool $canFinishEarly): TrafficTick
    {
        $tick = new Tick(
            new Lock(),
            new TickSettings(),
            [new Job('job', 60, 10, function (): void {
                $this->events[] = 'job';
            })],
            fn (): bool => true,
            fn (): float => (float) self::START,
            function (): void {
            }
        );

        return new TrafficTick(
            new TickSettings(),
            fn (): Tick => $tick,
            fn (): int => self::START,
            $canFinishEarly ? function (): void {
                $this->events[] = 'response finished';
            } : null,
            function (callable $callback): void {
                $this->shutdown[] = $callback;
            }
        );
    }
}
