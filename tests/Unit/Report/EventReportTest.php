<?php
declare(strict_types=1);

namespace Tests\Unit\Report;

use PHPUnit\Framework\TestCase;
use PrestaShop\Module\SmailyForPrestaShop\Http\NetworkException;
use PrestaShop\Module\SmailyForPrestaShop\Http\Response;
use PrestaShop\Module\SmailyForPrestaShop\Report\Credentials;
use PrestaShop\Module\SmailyForPrestaShop\Report\EventReport;
use PrestaShop\Module\SmailyForPrestaShop\Report\InstallationReport;
use PrestaShop\Module\SmailyForPrestaShop\Report\ReportBuilder;
use PrestaShop\Module\SmailyForPrestaShop\Report\ReportSender;
use PrestaShop\Module\SmailyForPrestaShop\Report\SetupChange;

require_once __DIR__ . '/ReportFixture.php';

/**
 * The two events (`disconnected`, `deactivated`) go at once, once, and never stop their caller;
 * a save that removed a Smaily account sends `disconnected`, and a save that went through
 * schedules a snapshot.
 */
final class EventReportTest extends TestCase
{
    private ReportFixture $store;

    private ReportTransport $transport;

    /** @var list<string> */
    private array $log = [];

    protected function setUp(): void
    {
        $this->store = new ReportFixture();
        $this->store->accounts = [ReportFixture::account(0, '', 'example')];
        $this->transport = new ReportTransport();
    }

    public function testTheEventIsSentOnceAndWithoutATrigger(): void
    {
        $this->events()->send(ReportBuilder::EVENT_DEACTIVATED);

        self::assertCount(1, $this->transport->requests);
        self::assertSame('deactivated', $this->transport->body()['event']);
        self::assertArrayNotHasKey('trigger', $this->transport->body());
        self::assertSame([], $this->log);
    }

    public function testNothingIsSentWithoutAConnectedAccount(): void
    {
        $this->store->accounts = [];

        $this->events()->send(ReportBuilder::EVENT_DEACTIVATED);
        $this->events()->send(ReportBuilder::EVENT_DISCONNECTED);

        self::assertSame([], $this->transport->requests);
        self::assertSame([], $this->log);
    }

    /**
     * @return array<string, array{Response|\Throwable, string}>
     */
    public function failures(): array
    {
        return [
            'the service fails' => [new Response(503, '{"error": "unavailable"}', ['retry-after' => ['60']]), 'http=503 error=unavailable'],
            'the service refuses' => [new Response(401, '{"error": "invalid_smaily_credentials"}'), 'http=401 error=invalid_smaily_credentials'],
            'no answer' => [new NetworkException('Idle timeout reached.'), 'http=0 error=network_error'],
        ];
    }

    /**
     * @dataProvider failures
     *
     * @param Response|\Throwable $answer
     */
    public function testAnEventThatIsNotTakenInIsNotRepeatedAndLeavesOneLine($answer, string $summary): void
    {
        $this->transport = new ReportTransport($answer);

        $this->events()->send(ReportBuilder::EVENT_DISCONNECTED, [7]);

        self::assertCount(1, $this->transport->requests);
        self::assertSame(['the installation report (disconnected) was not taken in: ' . $summary], $this->log);
        self::assertSame([], $this->store->settings->values, 'an event schedules no retry');
    }

    public function testAFailureNeverReachesTheCaller(): void
    {
        $this->transport = new ReportTransport(new \LogicException('The transport is broken.'));

        $this->events()->send(ReportBuilder::EVENT_DEACTIVATED);

        self::assertCount(1, $this->transport->requests);
    }

    public function testASaveThatKeepsEveryAccountOnlySchedulesASnapshot(): void
    {
        $change = $this->change();

        $before = $change->before();
        $change->after($before);

        self::assertSame([], $this->transport->requests);
        self::assertSame(ReportFixture::NOW + 300, $this->state()['setup_saved_at']);
    }

    public function testASaveThatRemovesAnAccountReportsItAtOnceWithTheConnectionsThatRemain(): void
    {
        $this->store->shops = [1 => 'https://example-store.test', 2 => 'https://second-example-store.test'];
        $this->store->accounts[] = ReportFixture::account(2, '', 'example-second');
        $change = $this->change();

        $before = $change->before();
        array_pop($this->store->accounts);
        $change->after($before);

        self::assertCount(1, $this->transport->requests);
        self::assertSame('disconnected', $this->transport->body()['event']);
        self::assertSame(['example', 'example'], array_column($this->transport->body()['connections'], 'smaily_subdomain'));
        self::assertSame(ReportFixture::NOW + 300, $this->state()['setup_saved_at'], 'the save also schedules a snapshot');
    }

    public function testASaveThatRemovesTheLastAccountReportsItWithTheAccountItHeld(): void
    {
        $change = $this->change();

        $before = $change->before();
        $this->store->accounts = [];
        $change->after($before);

        self::assertCount(1, $this->transport->requests);
        self::assertSame([], $this->transport->body()['connections']);
        self::assertSame('example', $this->transport->body()['smaily_subdomain']);
        self::assertSame((new Credentials('example', 'api-user-example', ReportFixture::PASSWORD))->authorization(), $this->transport->requests[0][2]['headers']['Authorization']);
        self::assertSame([], $this->store->settings->values, 'no snapshot is scheduled without an account');
    }

    public function testASaveThatReplacesAnAccountReportsTheOneThatWent(): void
    {
        $change = $this->change();

        $before = $change->before();
        $this->store->accounts = [ReportFixture::account(0, '', 'example-new')];
        $change->after($before);

        self::assertCount(1, $this->transport->requests);
        self::assertSame('disconnected', $this->transport->body()['event']);
        self::assertSame('example-new', $this->transport->body()['smaily_subdomain'], 'the main account after the save authenticates');
    }

    public function testASaveThatDidNotGoThroughSchedulesNothing(): void
    {
        $change = $this->change();

        $change->after($change->before(), false);

        self::assertSame([], $this->transport->requests);
        self::assertSame([], $this->store->settings->values);
    }

    private function events(): EventReport
    {
        return new EventReport($this->store->builder(), new ReportSender($this->transport, '3.0.0'), function (string $line): void {
            $this->log[] = $line;
        });
    }

    private function change(): SetupChange
    {
        $log = function (string $line): void {
            $this->log[] = $line;
        };
        $sender = new ReportSender($this->transport, '3.0.0');

        return new SetupChange(
            $this->store->builder(),
            $this->events(),
            new InstallationReport($this->store->builder(), $sender, $this->store->settings, fn (): int => $this->store->now, static fn (): int => 0, $log)
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function state(): array
    {
        return json_decode($this->store->settings->values['SMAILY_CONNECT_REPORT_STATE'] ?? '[]', true);
    }
}
