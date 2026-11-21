<?php
declare(strict_types=1);

namespace Tests\Unit\Engine;

use PHPUnit\Framework\TestCase;
use PrestaShop\Module\SmailyForPrestaShop\Engine\Client;
use PrestaShop\Module\SmailyForPrestaShop\Engine\Connection;
use PrestaShop\Module\SmailyForPrestaShop\Engine\Exception\EngineException;
use PrestaShop\Module\SmailyForPrestaShop\Engine\Exception\EngineRequestException;
use PrestaShop\Module\SmailyForPrestaShop\Engine\Exception\EngineTransportException;
use PrestaShop\Module\SmailyForPrestaShop\Http\NetworkException;
use PrestaShop\Module\SmailyForPrestaShop\Http\Response;
use PrestaShop\Module\SmailyForPrestaShop\Http\Transport;

/**
 * The engine client calls the engine's own host only, sends the connection's key as a Bearer
 * token, and types every failure with the HTTP status and a code, never with the engine's text.
 */
final class ClientTest extends TestCase
{
    private const KEY = 'sk_not_a_real_key';

    private const REMOTE_TEXT = 'No customer with email shopper@example.test';

    public function testTheExchangePostsTheBodyToTheEngineWithoutAKey(): void
    {
        $transport = new EngineRecordingTransport(new Response(200, '{"tenant_id": "t"}'));
        $body = ['setup_token' => 'tok', 'capabilities' => ['connection_prefix']];

        $answer = (new Client($transport, '3.0.0'))->setupExchange(Client::BASE_URL, $body);

        self::assertSame(['tenant_id' => 't'], $answer);
        self::assertSame([[
            'POST',
            'https://intelligence.smaily.com/api/setup/exchange',
            [
                'headers' => ['User-Agent' => 'SmailyConnect-PrestaShopModule/3.0.0', 'Accept' => 'application/json'],
                'timeout' => 10,
                'max_duration' => 10,
                'max_redirects' => 0,
                'json' => $body,
            ],
        ]], $transport->requests);
    }

    public function testThePingIsAGetWithTheConnectionsKeyAndNoBody(): void
    {
        $transport = new EngineRecordingTransport(new Response(200, '{"ok": true, "pong": true}'));

        (new Client($transport, '3.0.0'))->ping(self::connection());

        self::assertSame([[
            'GET',
            'https://intelligence.smaily.com/api/v1/ingest/ping',
            [
                'headers' => [
                    'User-Agent' => 'SmailyConnect-PrestaShopModule/3.0.0',
                    'Accept' => 'application/json',
                    'Authorization' => 'Bearer ' . self::KEY,
                ],
                'timeout' => 5,
                'max_duration' => 5,
                'max_redirects' => 0,
            ],
        ]], $transport->requests);
    }

    public function testTheDisconnectIsAPostWithTheConnectionsKeyNoBodyAndThreeSeconds(): void
    {
        $transport = new EngineRecordingTransport(new Response(200, '{"ok": true, "status": "store_paused"}'));

        (new Client($transport, '3.0.0'))->storeUninstall(self::connection());

        self::assertSame('POST', $transport->requests[0][0]);
        self::assertSame('https://intelligence.smaily.com/api/v1/store/uninstall', $transport->requests[0][1]);
        self::assertSame('Bearer ' . self::KEY, $transport->requests[0][2]['headers']['Authorization']);
        self::assertSame(3, $transport->requests[0][2]['timeout']);
        self::assertSame(3, $transport->requests[0][2]['max_duration']);
        self::assertArrayNotHasKey('json', $transport->requests[0][2]);
        self::assertArrayNotHasKey('body', $transport->requests[0][2]);
    }

    public function testAnEndpointThatTheStoredMapLacksFallsBackToItsPathOnTheStoredBase(): void
    {
        $transport = new EngineRecordingTransport(new Response(200, '{}'));
        $connection = new Connection(1, 0, '', 'tenant', self::KEY, ['engine_base_url' => Client::BASE_URL . '/', 'endpoints' => []]);

        (new Client($transport, '3.0.0'))->storeUninstall($connection);

        self::assertSame('https://intelligence.smaily.com/api/v1/store/uninstall', $transport->requests[0][1]);
    }

    /**
     * @dataProvider otherAddresses
     */
    public function testNoRequestGoesToAnAddressThatIsNotTheEngines(string $url): void
    {
        $transport = new EngineRecordingTransport(new Response(200, '{}'));
        $client = new Client($transport, '3.0.0');
        $connection = new Connection(1, 0, '', 'tenant', self::KEY, ['endpoints' => ['ingest_ping' => $url]]);

        try {
            $client->ping($connection);
            self::fail('The key went to ' . $url);
        } catch (EngineRequestException $refusal) {
            self::assertSame(Client::HOST_NOT_ALLOWED, $refusal->errorCode());
        }
        self::assertFalse(Client::isEngineUrl($url));
        self::assertSame([], $transport->requests);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function otherAddresses(): iterable
    {
        yield 'plain http' => ['http://intelligence.smaily.com/api/v1/ingest/ping'];
        yield 'another host' => ['https://engine.example.test/api/v1/ingest/ping'];
        yield 'a host that ends alike' => ['https://evil-intelligence.smaily.com/api/v1/ingest/ping'];
        yield 'a host that starts alike' => ['https://intelligence.smaily.com.example.test/ping'];
        yield 'credentials in front' => ['https://intelligence.smaily.com@example.test/ping'];
        yield 'user info' => ['https://user@intelligence.smaily.com/ping'];
        yield 'another port' => ['https://intelligence.smaily.com:8443/ping'];
        yield 'a backslash' => ['https://intelligence.smaily.com\\@example.test/ping'];
        yield 'a query' => ['https://intelligence.smaily.com/ping?next=https://example.test'];
        yield 'a fragment' => ['https://intelligence.smaily.com/ping#x'];
        yield 'a climb' => ['https://intelligence.smaily.com/api/../ping'];
        yield 'upper case scheme' => ['HTTPS://intelligence.smaily.com/ping'];
        yield 'a line break' => ["https://intelligence.smaily.com/ping\n"];
        yield 'no base at all' => ['/api/v1/ingest/ping'];
        yield 'empty' => [''];
    }

    public function testTakesTheEnginesOwnAddressesWithTheMapsPlaceholders(): void
    {
        self::assertTrue(Client::isEngineUrl('https://intelligence.smaily.com'));
        self::assertTrue(Client::isEngineUrl('https://intelligence.smaily.com:443/api/v1/ingest/ping'));
        self::assertTrue(Client::isEngineUrl('https://intelligence.smaily.com/api/v1/customer/{email}/export'));
        self::assertTrue(Client::isEngineUrl('https://intelligence.smaily.com/api/v1/emails/{email_key}/design-link'));
    }

    public function testAConnectionWhoseKeyDoesNotDecryptAsksNothing(): void
    {
        $transport = new EngineRecordingTransport(new Response(200, '{}'));
        $connection = new Connection(1, 0, '', 'tenant', '', ['engine_base_url' => Client::BASE_URL]);

        try {
            (new Client($transport, '3.0.0'))->ping($connection);
            self::fail('A request went out without a key.');
        } catch (EngineRequestException $refusal) {
            self::assertSame(Client::KEY_UNREADABLE, $refusal->errorCode());
            self::assertSame(0, $refusal->httpStatus());
        }
        self::assertSame([], $transport->requests);
    }

    /**
     * @dataProvider failures
     *
     * @param class-string<EngineException> $class
     */
    public function testTypesAFailureByItsStatusAndKeepsTheCodeOnly(int $status, string $body, string $class, string $code): void
    {
        $client = new Client(new EngineRecordingTransport(new Response($status, $body)), '3.0.0');

        try {
            $client->ping(self::connection());
            self::fail('HTTP ' . $status . ' is a failure.');
        } catch (EngineException $failure) {
            self::assertSame($class, get_class($failure));
            self::assertSame($code, $failure->errorCode());
            self::assertSame($status, $failure->httpStatus());
            self::assertStringNotContainsString('shopper', $failure->getMessage());
            self::assertStringNotContainsString(self::KEY, $failure->getMessage());
            self::assertNull($failure->getPrevious());
        }
    }

    /**
     * @return iterable<string, array{int, string, class-string<EngineException>, string}>
     */
    public static function failures(): iterable
    {
        $body = static fn (string $error): string => (string) json_encode(['error' => $error, 'message' => self::REMOTE_TEXT]);

        yield 'a revoked key' => [401, $body('unauthorised'), EngineRequestException::class, 'unauthorised'];
        yield 'a deactivated account' => [403, $body('tenant_inactive'), EngineRequestException::class, 'tenant_inactive'];
        yield 'an update is required' => [409, $body('plugin_update_required'), EngineRequestException::class, 'plugin_update_required'];
        yield 'a used token' => [410, $body('setup_token_expired_or_used'), EngineRequestException::class, 'setup_token_expired_or_used'];
        yield 'a redirect is not followed' => [302, '', EngineRequestException::class, 'http_302'];
        yield 'a rate limit' => [429, $body('rate_limited'), EngineTransportException::class, 'rate_limited'];
        yield 'an engine error' => [500, $body('internal_error'), EngineTransportException::class, 'internal_error'];
        yield 'a gateway error without JSON' => [502, '<html>Bad Gateway</html>', EngineTransportException::class, 'http_502'];
        yield 'an error whose code is a sentence' => [400, $body(self::REMOTE_TEXT), EngineRequestException::class, 'http_400'];
        yield 'an error whose code is no string' => [404, '{"error": {"email": "shopper@example.test"}}', EngineRequestException::class, 'http_404'];
    }

    public function testAnAnswerThatIsNotJsonMayPassLater(): void
    {
        $client = new Client(new EngineRecordingTransport(new Response(200, '<html>shopper@example.test</html>')), '3.0.0');

        try {
            $client->ping(self::connection());
            self::fail('The answer is not JSON.');
        } catch (EngineTransportException $failure) {
            self::assertSame(Client::MALFORMED_ANSWER, $failure->errorCode());
            self::assertSame(200, $failure->httpStatus());
            self::assertStringNotContainsString('shopper', $failure->getMessage());
        }
    }

    public function testNoAnswerIsANetworkErrorWithoutTheClientsText(): void
    {
        $network = NetworkException::of(new \RuntimeException('Timeout for https://intelligence.smaily.com/api/v1/ingest/ping'));
        $client = new Client(new EngineRecordingTransport($network), '3.0.0');

        try {
            $client->ping(self::connection());
            self::fail('No answer arrived.');
        } catch (EngineTransportException $failure) {
            self::assertSame(Client::NETWORK_ERROR, $failure->errorCode());
            self::assertSame(0, $failure->httpStatus());
            self::assertStringNotContainsString('Timeout', $failure->getMessage());
            self::assertNull($failure->getPrevious());
        }
    }

    private static function connection(): Connection
    {
        return new Connection(1, 0, 'demo', 'tenant', self::KEY, [
            'engine_base_url' => Client::BASE_URL,
            'endpoints' => ['ingest_ping' => Client::BASE_URL . '/api/v1/ingest/ping'],
        ]);
    }
}

/**
 * Records each request and answers every one with the same response or failure.
 */
final class EngineRecordingTransport implements Transport
{
    /** @var list<array{string, string, array<string, mixed>}> */
    public array $requests = [];

    /** @var Response|\Throwable */
    private $answer;

    /**
     * @param Response|\Throwable $answer
     */
    public function __construct($answer)
    {
        $this->answer = $answer;
    }

    public function request(string $method, string $url, array $options = []): Response
    {
        $this->requests[] = [$method, $url, $options];
        if ($this->answer instanceof \Throwable) {
            throw $this->answer;
        }

        return $this->answer;
    }
}
