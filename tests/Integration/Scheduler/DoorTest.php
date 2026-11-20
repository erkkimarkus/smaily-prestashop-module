<?php
declare(strict_types=1);

namespace Tests\Integration\Scheduler;

use PrestaShop\Module\SmailyForPrestaShop\Config\TickSettings;
use PrestaShop\Module\SmailyForPrestaShop\Scheduler\Door;
use PrestaShop\Module\SmailyForPrestaShop\Scheduler\Job;
use PrestaShop\Module\SmailyForPrestaShop\Scheduler\Lock;
use PrestaShop\Module\SmailyForPrestaShop\Scheduler\Tick;
use Tests\Integration\IntegrationTestCase;

/**
 * Each door runs the tick only for its own token, and answers with a short status in plain text.
 */
final class DoorTest extends IntegrationTestCase
{
    private const KEYS = [
        TickSettings::TICK_TOKEN,
        TickSettings::CUSTOMER_CRON_TOKEN,
        TickSettings::CART_CRON_TOKEN,
        TickSettings::TICK_STATE,
    ];

    /** @var array<string, string|false> */
    private array $saved = [];

    private bool $schemaIsCurrent = true;

    /** @var list<string> */
    private array $sources = [];

    protected function setUp(): void
    {
        parent::setUp();
        foreach (self::KEYS as $key) {
            $this->saved[$key] = self::storedValue($key);
            \Configuration::deleteByName($key);
        }
    }

    protected function tearDown(): void
    {
        if (defined('_PS_ROOT_DIR_')) {
            foreach ($this->saved as $key => $value) {
                \Configuration::deleteByName($key);
                if ($value !== false) {
                    \Configuration::updateGlobalValue($key, $value);
                }
            }
        }
        parent::tearDown();
    }

    public function testEachDoorRunsTheTickWithItsOwnToken(): void
    {
        $doors = [
            Door::TICK => TickSettings::TICK_TOKEN,
            Door::CUSTOMER_CRON => TickSettings::CUSTOMER_CRON_TOKEN,
            Door::CART_CRON => TickSettings::CART_CRON_TOKEN,
        ];
        foreach ($doors as $door => $key) {
            \Configuration::deleteByName(TickSettings::TICK_STATE);
            $token = (new TickSettings())->token($key, 1);

            self::assertSame([200, 'OK: ran test_job.'], $this->door()->open($door, $token, 1, 30));
            self::assertSame($door, (new TickSettings())->storedState()->lastSource());
        }
    }

    public function testRefusesAWrongOrEmptyToken(): void
    {
        $tickToken = (new TickSettings())->token(TickSettings::TICK_TOKEN, 1);
        \Configuration::updateGlobalValue(TickSettings::CART_CRON_TOKEN, '');

        $refused = [
            [Door::TICK, ''],
            [Door::TICK, $tickToken . 'x'],
            [Door::CUSTOMER_CRON, $tickToken],
            [Door::CART_CRON, ''],
            [Door::CART_CRON, 'anything'],
        ];
        foreach ($refused as [$door, $token]) {
            self::assertSame([403, 'Access denied!'], $this->door()->open($door, $token, 1, 30), $door);
        }
        self::assertNull((new TickSettings())->storedState()->lastTickAt(), 'no tick ran');
    }

    public function testSaysWhenNoJobWasDue(): void
    {
        $token = (new TickSettings())->token(TickSettings::TICK_TOKEN, 1);
        $this->door()->open(Door::TICK, $token, 1, 30);

        self::assertSame([200, 'OK: no job was due.'], $this->door()->open(Door::TICK, $token, 1, 30));
    }

    public function testSaysWhenAnotherTickIsRunning(): void
    {
        $token = (new TickSettings())->token(TickSettings::TICK_TOKEN, 1);
        $holder = proc_open([PHP_BINARY, __DIR__ . '/lock-holder.php'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($holder);
        try {
            self::assertSame("locked\n", fgets($pipes[1]));

            self::assertSame([200, 'Busy: another tick is running.'], $this->door()->open(Door::TICK, $token, 1, 30));
        } finally {
            fclose($pipes[0]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($holder);
        }
    }

    public function testSaysWhenTheUpgradeHasNotFinished(): void
    {
        $this->schemaIsCurrent = false;
        $token = (new TickSettings())->token(TickSettings::CUSTOMER_CRON_TOKEN, 1);

        self::assertSame(
            [200, 'Waiting: the upgrade of the module has not finished.'],
            $this->door()->open(Door::CUSTOMER_CRON, $token, 1, 30)
        );
    }

    public function testGivesTheTickFiftySecondsOrFiveLessThanTheRequestMayTake(): void
    {
        self::assertSame(50, Door::budget(0));
        self::assertSame(50, Door::budget(300));
        self::assertSame(25, Door::budget(30));
        self::assertSame(1, Door::budget(3));
    }

    private function door(): Door
    {
        $tick = new Tick(
            new Lock(),
            new TickSettings(),
            [new Job('test_job', 60, 10, fn () => null)],
            fn (): bool => $this->schemaIsCurrent,
            fn (): float => microtime(true),
            function (): void {
            }
        );

        return new Door(new TickSettings(), fn (): Tick => $tick);
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
