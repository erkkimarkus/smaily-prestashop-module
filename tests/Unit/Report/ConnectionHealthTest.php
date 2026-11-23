<?php
declare(strict_types=1);

namespace Tests\Unit\Report;

use PHPUnit\Framework\TestCase;
use PrestaShop\Module\SmailyForPrestaShop\Config\ReportSettings;
use PrestaShop\Module\SmailyForPrestaShop\Report\ConnectionHealth;

require_once __DIR__ . '/FakeReportSettings.php';

/**
 * The health of a Smaily account and of an engine store, as the installation report carries it:
 * whether the most recent request failed, with its error code, and when the last write succeeded.
 * Codes only; a success that changes only the time is written at most every five minutes.
 */
final class ConnectionHealthTest extends TestCase
{
    private FakeReportSettings $settings;

    private int $now = 1763622000;

    protected function setUp(): void
    {
        $this->settings = new FakeReportSettings();
    }

    public function testAnAccountNobodyAskedIsOkWithNoSync(): void
    {
        self::assertSame(['status' => 'ok', 'last_sync_at' => null, 'last_error_code' => null], $this->health()->smaily('demo'));
    }

    public function testASuccessfulWriteIsTheLastSyncAndAReadIsNot(): void
    {
        $health = $this->health();

        $health->recordSmaily('Demo ', '', false);
        self::assertNull($health->smaily('demo')['last_sync_at'], 'a read is no sync');

        $health->recordSmaily('demo', '', true);
        self::assertSame(['status' => 'ok', 'last_sync_at' => $this->now, 'last_error_code' => null], $health->smaily('DEMO'));
    }

    public function testAFailureMakesTheAccountFailingAndKeepsTheLastSync(): void
    {
        $health = $this->health();
        $health->recordSmaily('demo', '', true);
        $synced = $this->now;
        $this->now += 60;

        $health->recordSmaily('demo', ConnectionHealth::CREDENTIALS_REJECTED, false);

        self::assertSame(['status' => 'failing', 'last_sync_at' => $synced, 'last_error_code' => 'credentials_rejected'], $health->smaily('demo'));

        $health->recordSmaily('demo', '', false);

        self::assertSame(['status' => 'ok', 'last_sync_at' => $synced, 'last_error_code' => null], $health->smaily('demo'), 'the next answer ends the failure');
    }

    public function testASuccessThatChangesOnlyTheTimeIsWrittenAtMostEveryFiveMinutes(): void
    {
        $health = $this->health();
        $health->recordEngine('tenant-1', '', true);
        $first = $this->now;
        self::assertSame(1, $this->settings->writes);

        $this->now += ConnectionHealth::WRITE_EVERY_SECONDS - 1;
        $health->recordEngine('tenant-1', '', true);
        $health->recordEngine('tenant-1', '', false);
        self::assertSame(1, $this->settings->writes);
        self::assertSame($first, $health->engine('tenant-1')['last_sync_at']);

        ++$this->now;
        $health->recordEngine('tenant-1', '', true);
        self::assertSame(2, $this->settings->writes);
        self::assertSame($this->now, $health->engine('tenant-1')['last_sync_at']);

        $health->recordEngine('tenant-1', 'rate_limit_exceeded', false);
        self::assertSame(3, $this->settings->writes, 'a change of the code is written at once');
        self::assertSame('rate_limit_exceeded', $health->engine('tenant-1')['last_error_code']);
    }

    public function testACodeThatIsNoPlainCodeIsNeverStored(): void
    {
        $health = $this->health();

        $health->recordEngine('tenant-1', 'Bad Code: shopper@example.test', false);

        self::assertSame('unknown_error', $health->engine('tenant-1')['last_error_code']);
        self::assertStringNotContainsString('shopper', (string) $this->settings->values[ReportSettings::HEALTH]);
    }

    public function testASmailyAccountAndAnEngineStoreOfOneNameAreApart(): void
    {
        $health = $this->health();

        $health->recordSmaily('demo', 'unreachable', false);
        $health->recordEngine('demo', '', true);
        $health->recordSmaily('', 'unreachable', false);

        self::assertSame('failing', $health->smaily('demo')['status']);
        self::assertSame('ok', $health->engine('demo')['status']);
        self::assertSame(2, $this->settings->writes, 'an answer without a name is not recorded');
    }

    public function testOnlyTheNewestFiftyEntriesOfAGroupAreKept(): void
    {
        $health = $this->health();
        for ($i = 0; $i <= ConnectionHealth::MAX_ENTRIES; ++$i) {
            $health->recordSmaily('account' . $i, 'unreachable', false);
        }

        self::assertSame('ok', $health->smaily('account0')['status'], 'the oldest entry went');
        self::assertSame('failing', $health->smaily('account1')['status']);
        self::assertSame('failing', $health->smaily('account' . ConnectionHealth::MAX_ENTRIES)['status']);
    }

    public function testRecordingAndReadingNeverThrow(): void
    {
        $health = $this->health();
        $this->settings->failing = true;

        $health->recordSmaily('demo', '', true);

        self::assertSame(['status' => 'ok', 'last_sync_at' => null, 'last_error_code' => null], $health->smaily('demo'));
    }

    private function health(): ConnectionHealth
    {
        return new ConnectionHealth($this->settings, fn (): int => $this->now);
    }
}
