<?php
declare(strict_types=1);

namespace Tests\Integration\Engine;

use PrestaShop\Module\SmailyForPrestaShop\Config\TickSettings;
use PrestaShop\Module\SmailyForPrestaShop\Engine\Client;
use PrestaShop\Module\SmailyForPrestaShop\Engine\HealthCheck;
use PrestaShop\Module\SmailyForPrestaShop\Runtime\Provider\EngineProvider;
use PrestaShop\Module\SmailyForPrestaShop\Runtime\Provider\SchedulerProvider;
use PrestaShop\Module\SmailyForPrestaShop\Scheduler\Deadline;
use PrestaShop\Module\SmailyForPrestaShop\Scheduler\Job;
use PrestaShop\Module\SmailyForPrestaShop\Scheduler\Lock;
use PrestaShop\Module\SmailyForPrestaShop\Scheduler\Tick;
use PrestaShop\Module\SmailyForPrestaShop\Scheduler\TickReport;
use PrestaShop\Module\SmailyForPrestaShop\Scope\StoreLayout;

require_once __DIR__ . '/EngineTestCase.php';

/**
 * The health check pings each stored connection and records with it whether the engine takes it:
 * connected, refused or unreachable, with a code and the HTTP status and never the engine's text.
 * A failing engine never fails the tick.
 */
final class HealthCheckTest extends EngineTestCase
{
    /** A time after every real exchange of a test, so that a check at it is the later one. */
    private const NOW = 1893492000;

    private const NOW_UTC = '2030-01-01T10:00:00Z';

    private const LATER_UTC = '2030-01-01T10:15:00Z';

    private const REMOTE_TEXT = 'No customer with email shopper@example.test';

    /** @var string|false */
    private $savedTickState = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->savedTickState = \Configuration::getGlobalValue(TickSettings::TICK_STATE);
        self::assertSame(self::NOW_UTC, gmdate('Y-m-d\TH:i:s\Z', self::NOW));
    }

    protected function tearDown(): void
    {
        if (defined('_PS_ROOT_DIR_')) {
            \Configuration::deleteByName(TickSettings::TICK_STATE);
            if ($this->savedTickState !== false) {
                \Configuration::updateGlobalValue(TickSettings::TICK_STATE, (string) $this->savedTickState);
            }
        }
        parent::tearDown();
    }

    public function testWithoutAConnectionTheCheckAsksNothing(): void
    {
        $this->check(self::NOW)->run(self::deadline());

        self::assertSame([], $this->engine->requests());
    }

    public function testAnAnsweredPingRecordsConnectedAndSendsTheKeyOnly(): void
    {
        $connection = $this->connect();

        $status = $this->check(self::NOW)->check($connection);

        self::assertSame(HealthCheck::CONNECTED, $status);
        $stored = $this->connections()->find(0);
        self::assertSame(['status' => 'connected', 'error' => '', 'http_status' => 200, 'checked_at' => self::NOW_UTC], $stored->health());
        self::assertSame('', $stored->refusedAt());
        $pings = $this->engine->requestsTo('/api/v1/ingest/ping');
        self::assertCount(1, $pings);
        self::assertSame(['GET', 'Bearer ' . $connection->apiKey(), '', []], [$pings[0]['method'], $pings[0]['authorization'], $pings[0]['raw'], $pings[0]['query']]);
    }

    /**
     * @dataProvider refusals
     */
    public function testARefusalIsRecordedWithItsCodeAndStatusAndNotTheEnginesText(int $status, ?string $error, string $code): void
    {
        $connection = $this->connect();
        $this->engine->fail('ping', $status, $error, self::REMOTE_TEXT);

        self::assertSame(HealthCheck::REFUSED, $this->check(self::NOW)->check($connection));

        $stored = $this->connections()->find(0);
        self::assertSame(['status' => 'refused', 'error' => $code, 'http_status' => $status, 'checked_at' => self::NOW_UTC], $stored->health());
        self::assertSame(self::NOW_UTC, $stored->refusedAt());
        self::assertStringNotContainsString('shopper', implode('|', self::row(0)));
        self::assertSame($connection->apiKey(), $stored->apiKey(), 'the connection stays stored: only a disconnect removes it');
    }

    /**
     * @return iterable<string, array{int, ?string, string}>
     */
    public static function refusals(): iterable
    {
        yield 'a revoked key' => [401, null, 'unauthorised'];
        yield 'a deactivated account' => [403, 'tenant_inactive', 'tenant_inactive'];
        yield 'the engine requires a newer module' => [409, null, 'plugin_update_required'];
        yield 'a code that is a sentence' => [400, self::REMOTE_TEXT, 'http_400'];
    }

    public function testAKeyThatAnOperatorRevokedIsRefused(): void
    {
        $connection = $this->connect();
        $this->engine->revokeKeys();

        self::assertSame(HealthCheck::REFUSED, $this->check(self::NOW)->check($connection));
        self::assertSame(['unauthorised', 401], [$this->connections()->find(0)->health()['error'], $this->connections()->find(0)->health()['http_status']]);
    }

    public function testAnEngineErrorIsUnreachableAndKeepsNoRefusal(): void
    {
        $connection = $this->connect();
        $this->engine->fail('ping', 500, 'internal_error', self::REMOTE_TEXT);

        self::assertSame(HealthCheck::UNREACHABLE, $this->check(self::NOW)->check($connection));

        $stored = $this->connections()->find(0);
        self::assertSame(['status' => 'unreachable', 'error' => 'internal_error', 'http_status' => 500, 'checked_at' => self::NOW_UTC], $stored->health());
        self::assertSame('', $stored->refusedAt());
        self::assertStringNotContainsString('shopper', implode('|', self::row(0)));
    }

    public function testAnEngineThatDoesNotAnswerInTimeIsUnreachable(): void
    {
        $connection = $this->connect();
        $this->engine->delay('ping', Client::PING_TIMEOUT_SECONDS + 1.5);

        $start = microtime(true);
        $status = $this->check(self::NOW)->check($connection);

        self::assertLessThan(Client::PING_TIMEOUT_SECONDS + 1, microtime(true) - $start, 'a ping waits 5 seconds at most');
        self::assertSame(HealthCheck::UNREACHABLE, $status);
        self::assertSame(
            ['status' => 'unreachable', 'error' => Client::NETWORK_ERROR, 'http_status' => 0, 'checked_at' => self::NOW_UTC],
            $this->connections()->find(0)->health()
        );
    }

    public function testTheFirstRefusalsTimeStaysUntilTheEngineAnswersAgain(): void
    {
        $this->connect();
        $this->engine->set('tenant_inactive', true);
        $this->check(self::NOW)->run(self::deadline());
        $this->check(self::NOW + 900)->run(self::deadline());
        self::assertSame(self::NOW_UTC, $this->connections()->find(0)->refusedAt(), 'the first refusal');
        self::assertSame(self::LATER_UTC, $this->connections()->find(0)->health()['checked_at']);

        $this->engine->set('tenant_inactive', false);
        $this->engine->fail('ping', 503);
        $this->check(self::NOW + 1800)->run(self::deadline());
        self::assertSame(self::NOW_UTC, $this->connections()->find(0)->refusedAt(), 'no answer ends no refusal');
        self::assertSame('unreachable', $this->connections()->find(0)->health()['status']);

        $this->engine->set('fail', []);
        $this->check(self::NOW + 2700)->run(self::deadline());
        self::assertSame('', $this->connections()->find(0)->refusedAt());
        self::assertSame('connected', $this->connections()->find(0)->health()['status']);
    }

    public function testThePingsPrefixAndHistoryReplaceTheStoredOnes(): void
    {
        $this->engine->set('omit_connection', ['exchange' => true]);
        $connection = $this->connect();
        self::assertSame('', $connection->prefix());
        $this->engine->set('omit_connection', []);
        $this->engine->set('prefix_answer', ['ping' => 'c6-']);

        $this->check(self::NOW)->check($connection);

        $stored = $this->connections()->find(0);
        self::assertSame('c6-', $stored->prefix(), 'a connection without a prefix gets the ping\'s');
        self::assertSame(100, $stored->config()['batch_size_max'], 'the rest of the stored config stays');

        $this->engine->set('prefix_answer', ['ping' => 7]);
        $this->check(self::NOW + 900)->check($stored);
        self::assertSame('c6-', $this->connections()->find(0)->prefix(), 'a prefix that is no text changes nothing');

        // The sibling connectors' rule: a prefix that is neither '' nor `c<N>-` is ignored.
        $this->engine->set('prefix_answer', ['ping' => "<c9>-'\"\\ %s\u{00e4}`;--\n"]);
        $this->check(self::NOW + 1800)->check($this->connections()->find(0));
        self::assertSame('c6-', $this->connections()->find(0)->prefix(), 'a prefix of another form changes nothing');
        self::assertSame('connected', $this->connections()->find(0)->health()['status']);
    }

    public function testAKeyThatDoesNotDecryptIsRefusedWithoutARequest(): void
    {
        $this->connect();
        self::db()->update(self::CONNECTION_TABLE, ['api_key_enc' => 'not-a-ciphertext'], '`id_shop` = 0');
        $requests = count($this->engine->requests());

        $this->check(self::NOW)->run(self::deadline());

        self::assertSame(
            ['status' => 'refused', 'error' => Client::KEY_UNREADABLE, 'http_status' => 0, 'checked_at' => self::NOW_UTC],
            $this->connections()->find(0)->health()
        );
        self::assertCount($requests, $this->engine->requests());
    }

    public function testEveryConnectionIsCheckedTheOneCheckedLongestAgoFirstUntilTheDeadline(): void
    {
        \Configuration::updateGlobalValue(StoreLayout::SEPARATE_STORES, '1');
        $this->services = $this->runtime();
        $first = $this->connect(991);
        $second = $this->connect(992);
        $this->check(self::NOW)->check($second);

        // The deadline passes after the first ping.
        $calls = 0;
        $this->check(self::NOW + 900)->run(new Deadline(1.0, static function () use (&$calls): float {
            return ++$calls > 1 ? 2.0 : 0.0;
        }));

        self::assertSame(self::LATER_UTC, $this->connections()->find(991)->health()['checked_at'], 'the connection never checked comes first');
        self::assertSame(self::NOW_UTC, $this->connections()->find(992)->health()['checked_at'], 'the other waits for the next run');

        $this->check(self::NOW + 1800)->run(self::deadline());
        $pings = array_column($this->engine->requestsTo('/api/v1/ingest/ping'), 'authorization');
        self::assertSame(
            ['Bearer ' . $second->apiKey(), 'Bearer ' . $first->apiKey(), 'Bearer ' . $second->apiKey(), 'Bearer ' . $first->apiKey()],
            $pings
        );
    }

    public function testACheckThatStartedBeforeAReconnectDoesNotWriteOverTheNewConnection(): void
    {
        $old = $this->connect();
        $this->engine->set('next_prefix', 'c4-');
        $new = $this->connect();
        $this->engine->set('tenant_inactive', true);

        $this->check(self::NOW)->check($old);

        $stored = $this->connections()->find(0);
        self::assertSame([$new->apiKey(), 'c4-', '', 'connected'], [$stored->apiKey(), $stored->prefix(), $stored->refusedAt(), $stored->health()['status']]);
    }

    public function testTheTickRunsTheCheckAndAFailingEngineDoesNotStopTheJobsAfterIt(): void
    {
        $this->connect();
        $this->engine->fail('ping', 500, 'internal_error', self::REMOTE_TEXT);
        \Configuration::deleteByName(TickSettings::TICK_STATE);
        $jobs = array_values(array_filter(
            array_map(static fn (callable $job): Job => $job([]), $this->services->listeners(SchedulerProvider::JOBS)),
            static fn (Job $job): bool => $job->name() === EngineProvider::HEALTH_JOB
        ));
        self::assertCount(1, $jobs);
        $after = false;
        $jobs[] = new Job('after_engine_health', 60, 5, static function () use (&$after): void {
            $after = true;
        });
        $failures = [];
        $tick = new Tick(new Lock(), new TickSettings(), $jobs, static fn (): bool => true, static fn (): float => microtime(true), static function (string $job) use (&$failures): void {
            $failures[] = $job;
        });

        $report = $tick->run('tick', 50);

        self::assertSame(TickReport::RAN, $report->status());
        self::assertSame([EngineProvider::HEALTH_JOB, 'after_engine_health'], $report->jobs());
        self::assertTrue($after);
        self::assertSame([], $failures, 'an engine failure is recorded with the connection, not thrown at the tick');
        self::assertSame('unreachable', $this->connections()->find(0)->health()['status']);
    }

    public function testTheCheckIsDueEvery15Minutes(): void
    {
        $jobs = array_values(array_filter(
            array_map(static fn (callable $job): Job => $job([]), $this->services->listeners(SchedulerProvider::JOBS)),
            static fn (Job $job): bool => $job->name() === EngineProvider::HEALTH_JOB
        ));

        self::assertCount(1, $jobs);
        self::assertSame(5, $jobs[0]->budget());
        self::assertFalse($jobs[0]->runsBeforeUpgrade());
        $state = (new TickSettings())->storedState()->withJobRun(EngineProvider::HEALTH_JOB, self::NOW);
        self::assertFalse($jobs[0]->isDue($state, self::NOW + 890));
        self::assertTrue($jobs[0]->isDue($state, self::NOW + 900));
    }

    private function check(int $now): HealthCheck
    {
        return new HealthCheck($this->services->get(Client::class), $this->connections(), static fn (): int => $now);
    }

    private static function deadline(): Deadline
    {
        return new Deadline(microtime(true) + 60, static fn (): float => microtime(true));
    }
}
