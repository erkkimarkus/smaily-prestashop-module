<?php
declare(strict_types=1);

namespace Tests\Unit\Privacy;

use PHPUnit\Framework\TestCase;
use PrestaShop\Module\SmailyForPrestaShop\Config\GdprPaceSettings;
use PrestaShop\Module\SmailyForPrestaShop\Engine\Connection;
use PrestaShop\Module\SmailyForPrestaShop\Engine\Exception\EngineRequestException;
use PrestaShop\Module\SmailyForPrestaShop\Engine\Exception\EngineTransportException;
use PrestaShop\Module\SmailyForPrestaShop\Privacy\GdprPace;
use PrestaShop\Module\SmailyForPrestaShop\Queue\Deferred;

/**
 * The privacy requests to an engine store keep to the engine's limit of 10 per 60 seconds, and a
 * 429 holds the store's requests for the wait it asked for. The clock is the test's own: nothing
 * waits.
 */
final class GdprPaceTest extends TestCase
{
    private const START = 1795000000;

    private int $now = self::START;

    private FakeGdprPaceSettings $settings;

    private GdprPace $pace;

    /** @var list<string> the requests made, by tenant */
    private array $calls = [];

    protected function setUp(): void
    {
        $this->settings = new FakeGdprPaceSettings();
        $this->pace = new GdprPace($this->settings, fn (): int => $this->now);
    }

    public function testTenRequestsGoInAMinuteAndTheEleventhWaitsForTheOldestToLeave(): void
    {
        $store = self::connection(1, 'tenant-a');
        for ($second = 0; $second < 10; ++$second) {
            $this->now = self::START + $second;
            self::assertNull($this->send($store), 'request ' . ($second + 1) . ' goes');
        }

        $this->now = self::START + 30;
        $held = $this->send($store);
        self::assertInstanceOf(Deferred::class, $held);
        self::assertSame(self::START + 60, $held->until(), 'the oldest request leaves the window then');
        self::assertNull($held->reason(), 'a row held back keeps its reason');
        self::assertCount(10, $this->calls, 'a held request is not made');

        $this->now = self::START + 60;
        self::assertNull($this->send($store));
        self::assertInstanceOf(Deferred::class, $this->send($store), 'one place freed, one request went');
        self::assertCount(11, $this->calls);
    }

    public function testEachEngineStoreHasItsOwnLimitAndTwoConnectionsOfOneStoreShareIt(): void
    {
        $first = self::connection(1, 'tenant-a');
        $sameStore = self::connection(2, 'tenant-a');
        $other = self::connection(3, 'tenant-b');
        for ($i = 0; $i < 5; ++$i) {
            self::assertNull($this->send($first));
            self::assertNull($this->send($sameStore));
        }

        self::assertInstanceOf(Deferred::class, $this->send($first));
        self::assertInstanceOf(Deferred::class, $this->send($sameStore));
        self::assertNull($this->send($other));
        self::assertSame(['tenant-a' => 10, 'tenant-b' => 1], array_count_values($this->calls));
    }

    public function testEveryRequestCountsWhateverComesBack(): void
    {
        $store = self::connection(1, 'tenant-a');
        $this->pace->spend($store);
        self::assertNull($this->pace->heldUntil($store));
        for ($i = 0; $i < 9; ++$i) {
            $failure = $i % 2 === 0 ? new EngineTransportException('internal_error', 503) : new EngineRequestException('customer_not_found', 404);
            try {
                $this->pace->send($store, static function () use ($failure): void {
                    throw $failure;
                });
                self::fail('Another failure than a 429 is the caller\'s.');
            } catch (\Throwable $thrown) {
                self::assertSame($failure, $thrown);
            }
        }

        self::assertSame(self::START + 60, $this->pace->heldUntil($store), 'an export and nine failed requests are ten');
    }

    public function testA429HoldsTheStoresRequestsForTheWaitItAskedFor(): void
    {
        $store = self::connection(1, 'tenant-a');
        $deferred = $this->pace->send($store, static function (): void {
            throw new EngineTransportException('rate_limit_exceeded', 429, 42);
        });

        self::assertInstanceOf(Deferred::class, $deferred);
        self::assertSame(self::START + 42, $deferred->until());
        self::assertSame('Campaign Intelligence answered HTTP 429 (rate_limit_exceeded).', $deferred->reason());

        $this->now = self::START + 41;
        $held = $this->send($store);
        self::assertInstanceOf(Deferred::class, $held, 'the rest of the store\'s requests wait too');
        self::assertSame(self::START + 42, $held->until());
        self::assertSame([], $this->calls);
        self::assertNull($this->send(self::connection(2, 'tenant-b')), 'another store is not held');

        $this->now = self::START + 42;
        self::assertNull($this->send($store));
    }

    /**
     * @return iterable<string, array{int|null, int}>
     */
    public static function waits(): iterable
    {
        yield 'no wait named: 60 s' => [null, 60];
        yield 'a wait over the ladder\'s ceiling: 6 h' => [86400, 21600];
    }

    /**
     * @dataProvider waits
     */
    public function testA429WithoutAWaitHoldsAMinuteAndALongWaitIsCapped(?int $asked, int $held): void
    {
        $deferred = $this->pace->send(self::connection(1, 'tenant-a'), static function () use ($asked): void {
            throw new EngineTransportException('rate_limit_exceeded', 429, $asked);
        });

        self::assertInstanceOf(Deferred::class, $deferred);
        self::assertSame(self::START + $held, $deferred->until());
    }

    public function testTheStoredRequestsKeepOnlyTheLastWindow(): void
    {
        $store = self::connection(1, 'tenant-a');
        $this->send($store);
        $this->now = self::START + 59;
        $this->send($store);
        $this->now = self::START + 61;
        $this->send($store);

        self::assertSame(['tenant-a' => ['calls' => [self::START + 59, self::START + 61], 'until' => 0]], json_decode((string) $this->settings->value, true));
    }

    private function send(Connection $connection): ?Deferred
    {
        return $this->pace->send($connection, function () use ($connection): void {
            $this->calls[] = $connection->tenantId();
        });
    }

    private static function connection(int $id, string $tenant): Connection
    {
        return new Connection($id, 0, '', $tenant, 'sk_test_key', ['engine_base_url' => 'https://intelligence.smaily.com']);
    }
}

/**
 * The stored requests held in memory.
 */
final class FakeGdprPaceSettings extends GdprPaceSettings
{
    public ?string $value = null;

    protected function storedValue(string $key, ?int $idShop = null): ?string
    {
        return $this->value;
    }

    protected function set(string $key, string $value, int $idShop = 0): bool
    {
        $this->value = $value;

        return true;
    }
}
