<?php
declare(strict_types=1);

namespace Tests\Unit\Engine;

use PHPUnit\Framework\TestCase;
use PrestaShop\Module\SmailyForPrestaShop\Engine\Connection;
use PrestaShop\Module\SmailyForPrestaShop\Engine\HealthCheck;

/**
 * What a ping's answer changes in a stored connection: the engine's history window and connection
 * prefix replace the stored ones, each only in the form the contract gives it: a prefix that is
 * '' or `c<N>-` exactly as it came, and no other.
 */
final class HealthAnswerTest extends TestCase
{
    private const STORED = [
        'tenant_name' => 'Mock Store',
        'engine_version' => '1.0.0',
        'config' => ['batch_size_max' => 100, 'history' => ['start_months' => 18], 'connection' => ['prefix' => 'c2-']],
    ];

    public function testThePingsHistoryPrefixAndVersionReplaceTheStoredOnes(): void
    {
        $settings = HealthCheck::withAnswer(self::STORED, [
            'ok' => true,
            'engine_version' => '1.4.0',
            'config' => ['history' => ['start_months' => 12, 'horizon_months' => 60], 'connection' => ['prefix' => 'c7-']],
        ]);

        self::assertSame([
            'tenant_name' => 'Mock Store',
            'engine_version' => '1.4.0',
            'config' => ['batch_size_max' => 100, 'history' => ['start_months' => 12, 'horizon_months' => 60], 'connection' => ['prefix' => 'c7-']],
        ], $settings);
    }

    public function testAConnectionThatHeldNoPrefixGetsItFromThePing(): void
    {
        $settings = HealthCheck::withAnswer(['config' => ['batch_size_max' => 100]], ['config' => ['connection' => ['prefix' => 'c3-']]]);

        self::assertSame('c3-', (new Connection(1, 0, '', 't', 'k', $settings))->prefix());
        self::assertSame(100, $settings['config']['batch_size_max']);
    }

    /**
     * @dataProvider prefixes
     */
    public function testThePrefixIsKeptExactlyAsThePingGaveIt(string $prefix): void
    {
        $settings = HealthCheck::withAnswer(self::STORED, ['config' => ['connection' => ['prefix' => $prefix]]]);

        self::assertSame($prefix, $settings['config']['connection']['prefix']);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function prefixes(): iterable
    {
        yield 'none' => [''];
        yield 'as the contract shows it' => ['c2-'];
        yield 'the highest number' => ['c9999-'];
    }

    /**
     * @dataProvider otherForms
     */
    public function testAPrefixOfAnotherFormIsIgnoredAsInTheSiblingConnectors(string $prefix): void
    {
        self::assertSame(self::STORED, HealthCheck::withAnswer(self::STORED, ['config' => ['connection' => ['prefix' => $prefix]]]));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function otherForms(): iterable
    {
        yield 'another form' => ['shop_7.'];
        yield 'characters a parser trips on' => ["<c9>-'\"\\ %s\u{00e4}`;--\n"];
        yield 'white space only' => ['  '];
        yield 'a number as text' => ['007'];
        yield 'no number' => ['c-'];
        yield 'a number from zero' => ['c0-'];
        yield 'five digits' => ['c10000-'];
        yield 'upper case' => ['C2-'];
        yield 'a line end after it' => ["c2-\n"];
    }

    /**
     * @dataProvider answersWithoutAUsableValue
     *
     * @param array<string, mixed> $answer
     */
    public function testAnAnswerWithoutAUsableValueKeepsTheStoredOne(array $answer): void
    {
        self::assertSame(self::STORED, HealthCheck::withAnswer(self::STORED, $answer));
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function answersWithoutAUsableValue(): iterable
    {
        yield 'an engine before the config block' => [['ok' => true, 'pong' => true]];
        yield 'a config that is no object' => [['config' => 'none']];
        yield 'a history that is no object' => [['config' => ['history' => 18]]];
        yield 'a prefix that is a number' => [['config' => ['connection' => ['prefix' => 7]]]];
        yield 'a prefix that is null' => [['config' => ['connection' => ['prefix' => null]]]];
        yield 'a connection block that is text' => [['config' => ['connection' => 'c7-']]];
        yield 'a version that is no text' => [['engine_version' => 2]];
    }

    public function testAConnectionReadsItsEndpointsPrefixAndHealth(): void
    {
        $connection = new Connection(4, 2, 'demo', 'tenant', 'k', [
            'engine_base_url' => 'https://intelligence.smaily.com',
            'endpoints' => ['ingest_ping' => 'https://intelligence.smaily.com/api/v1/ingest/ping', 'broken' => ''],
            'config' => ['connection' => ['prefix' => 'c2-']],
            'refused_at' => '2026-11-21T10:00:00Z',
            'health' => HealthCheck::health(HealthCheck::REFUSED, 'unauthorised', 401, '2026-11-21T10:15:00Z'),
        ]);

        self::assertSame([4, 2, 'demo', 'tenant', 'c2-'], [$connection->id(), $connection->idShop(), $connection->smailySubdomain(), $connection->tenantId(), $connection->prefix()]);
        self::assertSame('https://intelligence.smaily.com/api/v1/ingest/ping', $connection->endpoint('ingest_ping', '/other'));
        self::assertSame('https://intelligence.smaily.com/api/v1/store/uninstall', $connection->endpoint('store_uninstall', '/api/v1/store/uninstall'));
        self::assertSame('https://intelligence.smaily.com/fallback', $connection->endpoint('broken', '/fallback'));
        self::assertSame('2026-11-21T10:00:00Z', $connection->refusedAt());
        self::assertSame(['status' => 'refused', 'error' => 'unauthorised', 'http_status' => 401, 'checked_at' => '2026-11-21T10:15:00Z'], $connection->health());
    }

    public function testAConnectionWithoutSettingsReadsEmpty(): void
    {
        $connection = new Connection(1, 0, '', '', '', []);

        self::assertSame('', $connection->prefix());
        self::assertSame('', $connection->refusedAt());
        self::assertSame([], $connection->config());
        self::assertSame(['status' => '', 'error' => '', 'http_status' => 0, 'checked_at' => ''], $connection->health());
    }
}
