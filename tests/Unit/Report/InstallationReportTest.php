<?php
declare(strict_types=1);

namespace Tests\Unit\Report;

use PHPUnit\Framework\TestCase;
use PrestaShop\Module\SmailyForPrestaShop\Config\ReportSettings;
use PrestaShop\Module\SmailyForPrestaShop\Http\NetworkException;
use PrestaShop\Module\SmailyForPrestaShop\Http\Response;
use PrestaShop\Module\SmailyForPrestaShop\Report\InstallationReport;
use PrestaShop\Module\SmailyForPrestaShop\Report\ReportSender;

require_once __DIR__ . '/ReportFixture.php';

/**
 * When a snapshot goes: five minutes after a save, once a day, only with a connected account, one
 * report a run, and the contract's retries kept across runs in the stored state.
 */
final class InstallationReportTest extends TestCase
{
    private const DAY = 86400;

    private ReportFixture $store;

    private ReportTransport $transport;

    /** @var list<int> what the next calls of random() answer; 0 after that */
    private array $random = [];

    /** @var list<string> */
    private array $log = [];

    protected function setUp(): void
    {
        $this->store = new ReportFixture();
        $this->store->accounts = [ReportFixture::account(0, '', 'example')];
        $this->transport = new ReportTransport();
    }

    public function testASaveSchedulesOneReportFiveMinutesOnAndMoreSavesAddNone(): void
    {
        $this->random = [7200];
        $report = $this->report();

        $report->setupSaved();
        $this->store->now += 120;
        $report->setupSaved();
        $report->run();
        $this->store->now += 179;
        $report->run();
        self::assertSame([], $this->transport->requests, 'nothing goes before the five minutes are over');
        self::assertSame(ReportFixture::NOW + 300, $this->state()['setup_saved_at'], 'the second save did not move the report');

        ++$this->store->now;
        $report->run();
        $report->run();

        self::assertCount(1, $this->transport->requests);
        self::assertSame('setup_saved', $this->transport->body()['trigger']);
        self::assertNull($this->state()['setup_saved_at']);
        self::assertSame([], $this->log);
    }

    public function testWithoutAConnectedAccountNothingIsScheduledOrSentAndTheScheduleIsForgotten(): void
    {
        $this->store->accounts = [];
        $report = $this->report();

        $report->setupSaved();
        $report->run();
        self::assertSame([], $this->store->settings->values, 'nothing is stored for a store without an account');

        $this->store->settings->values[ReportSettings::STATE] = (string) json_encode(['setup_saved_at' => ReportFixture::NOW - 1, 'daily_at' => ReportFixture::NOW - 1, 'retry' => null]);
        $report->run();

        self::assertSame([], $this->transport->requests);
        self::assertSame([], $this->state(), 'a later connection starts the schedule anew');
    }

    public function testTheFirstDailyReportIsAtARandomMomentWithinADayAndThenEveryDay(): void
    {
        $this->random = [5000];
        $report = $this->report();

        $report->run();
        self::assertSame(ReportFixture::NOW + 5000, $this->state()['daily_at']);
        $this->store->now += 4999;
        $report->run();
        self::assertSame([], $this->transport->requests);

        ++$this->store->now;
        $report->run();
        self::assertCount(1, $this->transport->requests);
        self::assertSame('daily', $this->transport->body()['trigger']);
        self::assertSame($this->store->now + self::DAY, $this->state()['daily_at']);

        $this->store->now += self::DAY - 1;
        $report->run();
        self::assertCount(1, $this->transport->requests, 'nothing changed, and the day is not over');
        ++$this->store->now;
        $report->run();
        self::assertCount(2, $this->transport->requests, 'the daily report goes also when nothing changed');
    }

    public function testAReportAfterASaveKeepsTheFirstDailyMomentAndALaterOneMovesIt(): void
    {
        $this->random = [5000];
        $report = $this->report();
        $report->setupSaved();
        $this->store->now += 300;

        $report->run();
        self::assertCount(1, $this->transport->requests);
        self::assertSame($this->store->now + 5000, $this->state()['daily_at'], 'the first daily report keeps its random moment');

        $report->setupSaved();
        $this->store->now += 300;
        $report->run();
        self::assertCount(2, $this->transport->requests);
        self::assertSame($this->store->now + self::DAY, $this->state()['daily_at'], 'the daily report is due 24 hours after the last attempt');
    }

    public function testOneReportPerRunASaveFirst(): void
    {
        $this->store->settings->values[ReportSettings::STATE] = (string) json_encode(['setup_saved_at' => ReportFixture::NOW - 1, 'daily_at' => ReportFixture::NOW - 1, 'retry' => null]);

        $this->report()->run();

        self::assertCount(1, $this->transport->requests);
        self::assertSame('setup_saved', $this->transport->body()['trigger']);
        self::assertSame(ReportFixture::NOW + self::DAY, $this->state()['daily_at'], 'the report that went counts as the day\'s attempt');
    }

    public function testATryThatMayPassIsRepeatedThreeTimesAtTheContractsWaitsAndThenLeavesOneLine(): void
    {
        $this->transport = new ReportTransport(new Response(503, '{"error": "unavailable", "message": "Try later."}'));
        $this->store->settings->values[ReportSettings::STATE] = (string) json_encode(['setup_saved_at' => null, 'daily_at' => ReportFixture::NOW, 'retry' => null]);
        $this->random = [10, 0, 30];
        $report = $this->report();

        $report->run();
        self::assertSame(['trigger' => 'daily', 'attempt' => 1, 'at' => ReportFixture::NOW + 70], $this->state()['retry']);

        $this->store->now = ReportFixture::NOW + 69;
        $report->run();
        self::assertCount(1, $this->transport->requests, 'the retry waits across runs');

        $this->store->now = ReportFixture::NOW + 70;
        $report->run();
        self::assertSame(['trigger' => 'daily', 'attempt' => 2, 'at' => $this->store->now + 120], $this->state()['retry']);

        $this->store->now += 120;
        $report->run();
        self::assertSame(['trigger' => 'daily', 'attempt' => 3, 'at' => $this->store->now + 270], $this->state()['retry']);
        self::assertSame([], $this->log, 'a try that is repeated leaves no line');

        $this->store->now += 270;
        $report->run();
        self::assertCount(4, $this->transport->requests, 'the first try and three more');
        self::assertNull($this->state()['retry']);
        self::assertSame(['the installation report (daily) was not taken in: http=503 error=unavailable'], $this->log);
        self::assertSame($this->store->now + self::DAY, $this->state()['daily_at']);

        $this->store->now += 3600;
        $report->run();
        self::assertCount(4, $this->transport->requests, 'given up until the next daily report');
    }

    public function testNoAnswerIsTriedAgain(): void
    {
        $this->transport = new ReportTransport(new NetworkException('Idle timeout reached.'), new Response(200, '{"installation_id": "3f8a1c52-7b9e-4d1a-9c3e-2a6f5b8d0e17"}'));
        $this->store->settings->values[ReportSettings::STATE] = (string) json_encode(['setup_saved_at' => null, 'daily_at' => ReportFixture::NOW, 'retry' => null]);
        $report = $this->report();

        $report->run();
        self::assertSame(['trigger' => 'daily', 'attempt' => 1, 'at' => ReportFixture::NOW + 60], $this->state()['retry']);

        $this->store->now += 60;
        $report->run();

        self::assertCount(2, $this->transport->requests);
        self::assertNull($this->state()['retry']);
        self::assertSame([], $this->log);
    }

    public function testALongerRetryAfterIsWaitedAndOneOverAnHourEndsTheTry(): void
    {
        $this->transport = new ReportTransport(
            new Response(429, '{"error": "rate_limited"}', ['retry-after' => ['900']]),
            new Response(503, '{"error": "smaily_unreachable"}', ['retry-after' => ['3601']])
        );
        $this->store->settings->values[ReportSettings::STATE] = (string) json_encode(['setup_saved_at' => null, 'daily_at' => ReportFixture::NOW, 'retry' => null]);
        $report = $this->report();

        $report->run();
        self::assertSame(ReportFixture::NOW + 900, $this->state()['retry']['at']);

        $this->store->now += 900;
        $report->run();
        self::assertNull($this->state()['retry']);
        self::assertSame(['the installation report (daily) was not taken in: http=503 error=smaily_unreachable'], $this->log);
    }

    /**
     * @return array<string, array{Response, string}>
     */
    public function answersThatCannotChange(): array
    {
        return [
            'a refused body' => [new Response(400, '{"error": "validation_failed", "message": "One or more fields failed validation."}'), 'http=400 error=validation_failed'],
            'refused credentials' => [new Response(401, '{"error": "invalid_smaily_credentials"}'), 'http=401 error=invalid_smaily_credentials'],
            'a redirect' => [new Response(308, ''), 'http=308 error=redirect'],
        ];
    }

    /**
     * @dataProvider answersThatCannotChange
     */
    public function testAnAnswerThatCannotChangeLeavesOneLineAndIsNotTriedAgain(Response $answer, string $summary): void
    {
        $this->transport = new ReportTransport($answer);
        $this->store->settings->values[ReportSettings::STATE] = (string) json_encode(['setup_saved_at' => ReportFixture::NOW, 'daily_at' => ReportFixture::NOW + 5000, 'retry' => null]);
        $report = $this->report();

        $report->run();
        $this->store->now += 600;
        $report->run();

        self::assertCount(1, $this->transport->requests);
        self::assertNull($this->state()['retry']);
        self::assertSame(['the installation report (setup_saved) was not taken in: ' . $summary], $this->log);
    }

    public function testANewerSnapshotReplacesAPendingRetry(): void
    {
        $this->store->settings->values[ReportSettings::STATE] = (string) json_encode([
            'setup_saved_at' => ReportFixture::NOW,
            'daily_at' => ReportFixture::NOW + 5000,
            'retry' => ['trigger' => 'daily', 'attempt' => 2, 'at' => ReportFixture::NOW],
        ]);

        $this->report()->run();

        self::assertCount(1, $this->transport->requests);
        self::assertSame('setup_saved', $this->transport->body()['trigger']);
        self::assertNull($this->state()['retry']);
    }

    public function testASaveNeverFailsOnItsReport(): void
    {
        $this->store->settings->failing = true;

        $this->report()->setupSaved();

        self::assertSame(['the installation report was not scheduled (RuntimeException)'], $this->log);
    }

    private function report(): InstallationReport
    {
        return new InstallationReport(
            $this->store->builder(),
            new ReportSender($this->transport, '3.0.0'),
            $this->store->settings,
            fn (): int => $this->store->now,
            fn (int $min, int $max): int => min($max, max($min, array_shift($this->random) ?? 0)),
            function (string $line): void {
                $this->log[] = $line;
            }
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function state(): array
    {
        return json_decode($this->store->settings->values[ReportSettings::STATE] ?? '[]', true);
    }
}
