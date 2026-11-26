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

            self::assertSame([200, 'OK: ran test_job.'], $this->answer($this->door(), $door, $token));
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
            self::assertSame([403, 'Access denied!'], $this->answer($this->door(), $door, $token), $door);
        }
        self::assertNull((new TickSettings())->storedState()->lastTickAt(), 'no tick ran');
    }

    public function testADoorWithoutAStoredTokenIsRefusedAndWritesNothing(): void
    {
        $count = static fn (): int => (int) self::db()->getValue('SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'configuration`', false);
        $rows = $count();

        foreach ([Door::TICK, Door::CUSTOMER_CRON, Door::CART_CRON] as $door) {
            foreach (['', 'anything', str_repeat('0', 32)] as $token) {
                self::assertSame([403, 'Access denied!'], $this->answer($this->door(), $door, $token), $door);
            }
        }

        self::assertSame($rows, $count(), 'no configuration row was added');
        foreach (self::KEYS as $key) {
            self::assertSame(0, (int) self::db()->getValue('SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'configuration` WHERE `name` = \'' . pSQL($key) . '\'', false), $key);
        }
    }

    public function testSaysWhenNoJobWasDue(): void
    {
        $token = (new TickSettings())->token(TickSettings::TICK_TOKEN, 1);
        $this->answer($this->door(), Door::TICK, $token);

        self::assertSame([200, 'OK: no job was due.'], $this->answer($this->door(), Door::TICK, $token));
    }

    public function testSaysWhenAnotherTickIsRunning(): void
    {
        $token = (new TickSettings())->token(TickSettings::TICK_TOKEN, 1);
        $holder = proc_open([PHP_BINARY, __DIR__ . '/lock-holder.php'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($holder);
        try {
            self::assertSame("locked\n", fgets($pipes[1]));

            self::assertSame([200, 'Busy: another tick is running.'], $this->answer($this->door(), Door::TICK, $token));
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
            $this->answer($this->door(), Door::CUSTOMER_CRON, $token)
        );
    }

    public function testSaysWhichJobsRanWhenTheTickFinishedTheUpgrade(): void
    {
        $this->schemaIsCurrent = false;
        $token = (new TickSettings())->token(TickSettings::CART_CRON_TOKEN, 1);
        $migration = new Job('migration', 60, 10, function (): void {
            $this->schemaIsCurrent = true;
        }, true);

        self::assertSame([200, 'OK: ran migration.'], $this->answer($this->door($migration), Door::CART_CRON, $token));
    }

    /**
     * A web server cuts a request at its own limit (nginx: 30 s) while a job still waits on Smaily:
     * where the response can go early, the cron's request ends before the first job.
     */
    public function testAnswersBeforeTheJobsWhereTheServerCanSendTheResponseEarly(): void
    {
        $token = (new TickSettings())->token(TickSettings::TICK_TOKEN, 1);

        self::assertSame(['200 OK: the tick is running.', 'finish', 'job'], $this->events(null, Door::TICK, $token));
        self::assertSame(Door::TICK, (new TickSettings())->storedState()->lastSource());
        self::assertSame(['200 OK: the tick is running.', 'finish'], $this->events(null, Door::TICK, $token), 'no job was due');
    }

    public function testAnswersEarlyThatTheUpgradeHasNotFinishedAndStillRunsTheMigration(): void
    {
        $this->schemaIsCurrent = false;
        $token = (new TickSettings())->token(TickSettings::CUSTOMER_CRON_TOKEN, 1);
        $migration = new Job('migration', 60, 10, function (): void {
            $this->schemaIsCurrent = true;
        }, true);

        self::assertSame(
            ['200 Waiting: the upgrade of the module has not finished.', 'finish'],
            $this->events($migration, Door::CUSTOMER_CRON, $token)
        );
        self::assertTrue($this->schemaIsCurrent, 'the migration ran after the answer');
    }

    public function testARefusedOrBusyDoorAnswersAtOnceAndSendsNothingEarly(): void
    {
        $token = (new TickSettings())->token(TickSettings::CART_CRON_TOKEN, 1);
        self::assertSame(['403 Access denied!'], $this->events(null, Door::CART_CRON, $token . 'x'));

        $holder = proc_open([PHP_BINARY, __DIR__ . '/lock-holder.php'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($holder);
        try {
            self::assertSame("locked\n", fgets($pipes[1]));

            self::assertSame(['200 Busy: another tick is running.'], $this->events(null, Door::CART_CRON, $token));
        } finally {
            fclose($pipes[0]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($holder);
        }
    }

    public function testGivesTheTickFiftySecondsOrFiveLessThanTheRequestMayTake(): void
    {
        self::assertSame(50, Door::budget(0));
        self::assertSame(50, Door::budget(300));
        self::assertSame(25, Door::budget(30));
        self::assertSame(1, Door::budget(3));
    }

    /**
     * Opens $name with $token on $door and gives back its one answer.
     *
     * @return array{0: int, 1: string}
     */
    private function answer(Door $door, string $name, string $token): array
    {
        $answers = [];
        $door->open($name, $token, 1, 30, static function (int $status, string $answer) use (&$answers): void {
            $answers[] = [$status, $answer];
        });
        self::assertCount(1, $answers, 'a door answers once');

        return $answers[0];
    }

    /**
     * A door whose server sends the response early: each answer, the finish and each job, in the
     * order they happened.
     *
     * @return list<string>
     */
    private function events(?Job $firstJob, string $name, string $token): array
    {
        $events = [];
        $jobs = [new Job('test_job', 60, 10, function () use (&$events): void {
            $events[] = 'job';
        })];
        if ($firstJob !== null) {
            array_unshift($jobs, $firstJob);
        }
        $tick = new Tick(
            new Lock(),
            new TickSettings(),
            $jobs,
            fn (): bool => $this->schemaIsCurrent,
            fn (): float => microtime(true),
            function (): void {
            }
        );
        $door = new Door(new TickSettings(), fn (): Tick => $tick, static function () use (&$events): void {
            $events[] = 'finish';
        });
        $door->open($name, $token, 1, 30, static function (int $status, string $answer) use (&$events): void {
            $events[] = $status . ' ' . $answer;
        });

        return $events;
    }

    private function door(?Job $firstJob = null): Door
    {
        $jobs = [new Job('test_job', 60, 10, fn () => null)];
        if ($firstJob !== null) {
            array_unshift($jobs, $firstJob);
        }
        $tick = new Tick(
            new Lock(),
            new TickSettings(),
            $jobs,
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
