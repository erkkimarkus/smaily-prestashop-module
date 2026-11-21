<?php
declare(strict_types=1);

namespace Tests\Unit\Engine;

use PHPUnit\Framework\TestCase;
use PrestaShop\Module\SmailyForPrestaShop\Engine\Client;
use PrestaShop\Module\SmailyForPrestaShop\Engine\Connection;
use PrestaShop\Module\SmailyForPrestaShop\Engine\Exception\EngineRequestException;
use PrestaShop\Module\SmailyForPrestaShop\Engine\HealthCheck;
use PrestaShop\Module\SmailyForPrestaShop\Engine\Setup;

/**
 * What the setup exchange takes from the merchant and from the engine: a setup link of the
 * engine's own host, and an answer that is stored whole or not at all, with the connection prefix
 * exactly as the engine gave it.
 */
final class SetupTest extends TestCase
{
    private const NOW = '2026-11-21T10:00:00Z';

    /**
     * @dataProvider links
     */
    public function testReadsTheTokenOfASetupLinkOrABareToken(string $pasted, string $token): void
    {
        self::assertSame(['https://intelligence.smaily.com', $token], Setup::parseLink($pasted));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function links(): iterable
    {
        yield 'the link' => ['https://intelligence.smaily.com/setup/abc123xyz', 'abc123xyz'];
        yield 'with spaces around' => ["  https://intelligence.smaily.com/setup/abc_123-XYZ \n", 'abc_123-XYZ'];
        yield 'with a slash at the end' => ['https://intelligence.smaily.com/setup/abc123xyz/', 'abc123xyz'];
        yield 'the token alone' => ['abc123xyz', 'abc123xyz'];
    }

    /**
     * @dataProvider refusedLinks
     */
    public function testRefusesWhatIsNoSetupLinkOfTheEngine(string $pasted, string $code): void
    {
        try {
            Setup::parseLink($pasted);
            self::fail('A token would have gone out for: ' . $pasted);
        } catch (EngineRequestException $refusal) {
            self::assertSame($code, $refusal->errorCode());
        }
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function refusedLinks(): iterable
    {
        yield 'nothing' => ['', Setup::INVALID_LINK];
        yield 'another host' => ['https://engine.example.test/setup/abc123xyz', Client::HOST_NOT_ALLOWED];
        yield 'plain http' => ['http://intelligence.smaily.com/setup/abc123xyz', Client::HOST_NOT_ALLOWED];
        yield 'a host that starts alike' => ['https://intelligence.smaily.com.example.test/setup/abc', Client::HOST_NOT_ALLOWED];
        yield 'credentials in front' => ['https://intelligence.smaily.com@example.test/setup/abc', Client::HOST_NOT_ALLOWED];
        yield 'another page of the engine' => ['https://intelligence.smaily.com/admin/tenants', Setup::INVALID_LINK];
        yield 'no token' => ['https://intelligence.smaily.com/setup/', Setup::INVALID_LINK];
        yield 'a token with other characters' => ['abc 123', Setup::INVALID_LINK];
        yield 'a path in the token' => ['https://intelligence.smaily.com/setup/abc/def', Setup::INVALID_LINK];
    }

    public function testStoresWhatTheAnswerHolds(): void
    {
        $answer = self::answer();

        $stored = Setup::read($answer, self::NOW);

        self::assertSame('demo', $stored['smaily_subdomain']);
        self::assertSame($answer['tenant_id'], $stored['tenant_id']);
        self::assertSame($answer['api_key'], $stored['api_key']);
        self::assertSame([
            'tenant_name' => 'Mock Store',
            'engine_base_url' => 'https://intelligence.smaily.com',
            'engine_version' => '1.0.0',
            'endpoints' => $answer['endpoints'],
            'config' => $answer['config'],
            'issued_at' => '2026-11-21T09:59:59Z',
            'refused_at' => '',
            'health' => ['status' => HealthCheck::CONNECTED, 'error' => '', 'http_status' => 200, 'checked_at' => self::NOW],
        ], $stored['settings']);
        self::assertArrayNotHasKey('api_key', $stored['settings'], 'the key is stored encrypted, apart from the settings');
    }

    /**
     * @dataProvider prefixes
     */
    public function testKeepsTheConnectionPrefixExactlyAsTheEngineGaveIt(string $prefix): void
    {
        $answer = self::answer(['config' => ['history' => ['start_months' => 18], 'connection' => ['prefix' => $prefix]]]);

        $settings = Setup::read($answer, self::NOW)['settings'];

        self::assertSame($prefix, $settings['config']['connection']['prefix']);
        self::assertSame($prefix, (new Connection(1, 0, '', 't', 'k', $settings))->prefix());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function prefixes(): iterable
    {
        yield 'none' => [''];
        yield 'as the contract shows it' => ['c2-'];
        yield 'the highest number' => ['c9999-'];
        yield 'another form' => ['shop_7.'];
        yield 'characters a parser trips on' => ["<c9>-'\"\\ %s\u{00e4}`;--\n"];
        yield 'white space only' => ['  '];
        yield 'a number as text' => ['007'];
    }

    public function testAnAnswerWithoutTheConnectionBlockStoresNoPrefix(): void
    {
        $settings = Setup::read(self::answer(['config' => ['batch_size_max' => 100]]), self::NOW)['settings'];

        self::assertSame(['batch_size_max' => 100], $settings['config']);
        self::assertSame('', (new Connection(1, 0, '', 't', 'k', $settings))->prefix());
    }

    public function testAnAnswerThatNamesNoSmailyAccountStoresNone(): void
    {
        self::assertSame('', Setup::read(self::answer(['smaily_subdomain' => null]), self::NOW)['smaily_subdomain']);
        self::assertSame('demo', Setup::read(self::answer(['smaily_subdomain' => ' Demo ']), self::NOW)['smaily_subdomain']);
    }

    public function testAnAnswerWithoutABaseUrlKeepsTheEnginesOwn(): void
    {
        $answer = self::answer();
        unset($answer['engine_base_url']);

        self::assertSame(Client::BASE_URL, Setup::read($answer, self::NOW)['settings']['engine_base_url']);
    }

    /**
     * @dataProvider unusableAnswers
     *
     * @param array<string, mixed> $changes
     */
    public function testRefusesAnAnswerThatCannotBeStoredWhole(array $changes, string $code): void
    {
        try {
            Setup::read(self::answer($changes), self::NOW);
            self::fail('The answer was stored.');
        } catch (EngineRequestException $refusal) {
            self::assertSame($code, $refusal->errorCode());
        }
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function unusableAnswers(): iterable
    {
        yield 'no key' => [['api_key' => ''], Setup::INVALID_ANSWER];
        yield 'a key that is no text' => [['api_key' => ['sk']], Setup::INVALID_ANSWER];
        yield 'no engine store' => [['tenant_id' => null], Setup::INVALID_ANSWER];
        yield 'an engine store id longer than its column' => [['tenant_id' => str_repeat('a', 37)], Setup::INVALID_ANSWER];
        yield 'a base on another host' => [['engine_base_url' => 'https://engine.example.test'], Client::HOST_NOT_ALLOWED];
        yield 'a base over http' => [['engine_base_url' => 'http://intelligence.smaily.com'], Client::HOST_NOT_ALLOWED];
        yield 'an endpoint on another host' => [['endpoints' => ['ingest_ping' => 'https://engine.example.test/api/v1/ingest/ping']], Client::HOST_NOT_ALLOWED];
        yield 'an endpoint that is no text' => [['endpoints' => ['ingest_ping' => null]], Client::HOST_NOT_ALLOWED];
        yield 'endpoints that are no map' => [['endpoints' => 'none'], Setup::INVALID_ANSWER];
        yield 'a prefix that is a number' => [['config' => ['connection' => ['prefix' => 2]]], Setup::INVALID_ANSWER];
        yield 'a prefix that is null' => [['config' => ['connection' => ['prefix' => null]]], Setup::INVALID_ANSWER];
        yield 'a prefix that is a list' => [['config' => ['connection' => ['prefix' => ['c2-']]]], Setup::INVALID_ANSWER];
        yield 'a connection block without a prefix' => [['config' => ['connection' => []]], Setup::INVALID_ANSWER];
        yield 'a connection block that is text' => [['config' => ['connection' => 'c2-']], Setup::INVALID_ANSWER];
        yield 'a Smaily account longer than a subdomain' => [['smaily_subdomain' => str_repeat('a', 64)], Setup::INVALID_ANSWER];
    }

    public function testALinkOfAnotherSmailyAccountIsNotTheShopsOwn(): void
    {
        self::assertTrue(Setup::isOwnAccount('demo', ['demo', 'demo-et']));
        self::assertTrue(Setup::isOwnAccount('', ['demo']), 'the engine names no account');
        self::assertTrue(Setup::isOwnAccount('demo', []), 'the shop holds no account yet');
        self::assertFalse(Setup::isOwnAccount('other', ['demo', 'demo-et']));
    }

    /**
     * @param array<string, mixed> $changes
     *
     * @return array<string, mixed>
     */
    private static function answer(array $changes = []): array
    {
        return $changes + [
            'tenant_id' => '550e8400-e29b-41d4-a716-446655440000',
            'tenant_name' => 'Mock Store',
            'smaily_subdomain' => 'demo',
            'api_key' => 'sk_not_a_real_key',
            'engine_base_url' => 'https://intelligence.smaily.com',
            'engine_version' => '1.0.0',
            'endpoints' => [
                'ingest_ping' => 'https://intelligence.smaily.com/api/v1/ingest/ping',
                'customer_export' => 'https://intelligence.smaily.com/api/v1/customer/{email}/export',
                'store_uninstall' => 'https://intelligence.smaily.com/api/v1/store/uninstall',
            ],
            'config' => ['batch_size_max' => 100, 'history' => ['start_months' => 18], 'connection' => ['prefix' => 'c2-']],
            'issued_at' => '2026-11-21T09:59:59Z',
        ];
    }
}
