<?php
declare(strict_types=1);

namespace Tests\Unit\Engine;

use PHPUnit\Framework\TestCase;
use PrestaShop\Module\SmailyForPrestaShop\Engine\Client;
use PrestaShop\Module\SmailyForPrestaShop\Engine\Connection;
use PrestaShop\Module\SmailyForPrestaShop\Engine\Exception\EngineRequestException;
use PrestaShop\Module\SmailyForPrestaShop\Engine\Exception\EngineTransportException;
use PrestaShop\Module\SmailyForPrestaShop\Http\Response;
use PrestaShop\Module\SmailyForPrestaShop\Http\Transport;

/**
 * The three privacy calls of the engine client (contract §8, §9 and §10): the address stands in
 * the path, trimmed, in lower case and URL-encoded; each call is one attempt of ten seconds with
 * the connection's key; and a failure carries the HTTP status and a code, never the engine's text.
 */
final class ClientPrivacyTest extends TestCase
{
    private const KEY = 'sk_not_a_real_key';

    private const REMOTE_TEXT = 'No customer with the email shopper1@example.test';

    public function testTheExportIsAGetOfTheAddressInThePath(): void
    {
        $transport = new PrivacyRecordingTransport(new Response(200, '{"customer": {"email": "shopper1@example.test"}}'));

        $answer = (new Client($transport, '3.0.0'))->customerExport(self::connection(), ' Shopper1+tag@Example.test ');

        self::assertSame(['customer' => ['email' => 'shopper1@example.test']], $answer);
        self::assertSame([[
            'GET',
            'https://intelligence.smaily.com/api/v1/customer/shopper1%2Btag%40example.test/export',
            [
                'headers' => [
                    'User-Agent' => 'SmailyConnect-PrestaShopModule/3.0.0',
                    'Accept' => 'application/json',
                    'Authorization' => 'Bearer ' . self::KEY,
                ],
                'timeout' => 10,
                'max_duration' => 10,
                'max_redirects' => 0,
            ],
        ]], $transport->requests);
    }

    public function testTheDeletionIsADeleteWithTheConfirmation(): void
    {
        $transport = new PrivacyRecordingTransport(new Response(200, '{"ok": true, "deleted": true}'));

        (new Client($transport, '3.0.0'))->customerDelete(self::connection(), 'shopper1@example.test');

        self::assertSame('DELETE', $transport->requests[0][0]);
        self::assertSame('https://intelligence.smaily.com/api/v1/customer/shopper1%40example.test', $transport->requests[0][1]);
        self::assertSame(['confirm' => true, 'reason' => 'user_request'], $transport->requests[0][2]['json']);
        self::assertSame(10, $transport->requests[0][2]['timeout']);
    }

    public function testAnOptOutCarriesItsTimeAndAnOptInDoesNot(): void
    {
        $transport = new PrivacyRecordingTransport(new Response(200, '{"ok": true}'));
        $client = new Client($transport, '3.0.0');

        $client->customerOptOut(self::connection(), 'shopper1@example.test', true, '2026-11-21T10:15:23Z');
        $client->customerOptOut(self::connection(), 'shopper1@example.test', false, '2026-11-21T10:15:23Z');

        self::assertSame('POST', $transport->requests[0][0]);
        self::assertSame('https://intelligence.smaily.com/api/v1/customer/shopper1%40example.test/opt-out', $transport->requests[0][1]);
        self::assertSame(['opt_out' => true, 'reason' => 'user_preference', 'opted_out_at' => '2026-11-21T10:15:23Z'], $transport->requests[0][2]['json']);
        self::assertSame(['opt_out' => false, 'reason' => 'user_preference'], $transport->requests[1][2]['json']);
    }

    public function testAnEndpointThatTheStoredMapLacksFallsBackToItsPath(): void
    {
        $transport = new PrivacyRecordingTransport(new Response(200, '{}'));
        $connection = new Connection(1, 0, '', 'tenant', self::KEY, ['engine_base_url' => Client::BASE_URL, 'endpoints' => []]);
        $client = new Client($transport, '3.0.0');

        $client->customerExport($connection, 'shopper1@example.test');
        $client->customerDelete($connection, 'shopper1@example.test');
        $client->customerOptOut($connection, 'shopper1@example.test', false);

        self::assertSame([
            'https://intelligence.smaily.com/api/v1/customer/shopper1%40example.test/export',
            'https://intelligence.smaily.com/api/v1/customer/shopper1%40example.test',
            'https://intelligence.smaily.com/api/v1/customer/shopper1%40example.test/opt-out',
        ], array_column($transport->requests, 1));
    }

    public function testAnUnknownShopperIsARefusalWithTheStatusAndNeverTheEnginesText(): void
    {
        $transport = new PrivacyRecordingTransport(new Response(404, (string) json_encode(['error' => 'customer_not_found', 'message' => self::REMOTE_TEXT])));

        try {
            (new Client($transport, '3.0.0'))->customerDelete(self::connection(), 'shopper1@example.test');
            self::fail('A 404 is a refusal.');
        } catch (EngineRequestException $refusal) {
            self::assertSame([404, 'customer_not_found'], [$refusal->httpStatus(), $refusal->errorCode()]);
            self::assertSame('Campaign Intelligence answered HTTP 404 (customer_not_found).', $refusal->getMessage());
            self::assertStringNotContainsString('shopper1', $refusal->getMessage());
        }
    }

    public function testAnEngineThatIsDownIsATransportFailureWithoutItsText(): void
    {
        $transport = new PrivacyRecordingTransport(new Response(503, (string) json_encode(['error' => 'A sentence, not a code', 'message' => self::REMOTE_TEXT])));

        try {
            (new Client($transport, '3.0.0'))->customerExport(self::connection(), 'shopper1@example.test');
            self::fail('A 503 is a failure.');
        } catch (EngineTransportException $failure) {
            self::assertSame([503, 'http_503'], [$failure->httpStatus(), $failure->errorCode()]);
            self::assertStringNotContainsString('shopper1', $failure->getMessage());
            self::assertStringNotContainsString('sentence', $failure->getMessage());
        }
    }

    public function testAnAddressNeverTakesTheRequestToAnotherPathOrHost(): void
    {
        $transport = new PrivacyRecordingTransport(new Response(200, '{}'));
        $client = new Client($transport, '3.0.0');

        $client->customerExport(self::connection(), 'a/../../admin@example.test');
        $client->customerExport(self::connection(), 'shopper1@example.test?x=1#y');

        self::assertSame([
            'https://intelligence.smaily.com/api/v1/customer/a%2F.%2E%2F.%2E%2Fadmin%40example.test/export',
            'https://intelligence.smaily.com/api/v1/customer/shopper1%40example.test%3Fx%3D1%23y/export',
        ], array_column($transport->requests, 1));
    }

    private static function connection(): Connection
    {
        return new Connection(1, 0, 'demo', 'tenant', self::KEY, [
            'engine_base_url' => Client::BASE_URL,
            'endpoints' => [
                'customer_export' => Client::BASE_URL . '/api/v1/customer/{email}/export',
                'customer_delete' => Client::BASE_URL . '/api/v1/customer/{email}',
                'customer_opt_out' => Client::BASE_URL . '/api/v1/customer/{email}/opt-out',
            ],
        ]);
    }
}

/**
 * Records each request and answers every one with the same response.
 */
final class PrivacyRecordingTransport implements Transport
{
    /** @var list<array{string, string, array<string, mixed>}> */
    public array $requests = [];

    private Response $answer;

    public function __construct(Response $answer)
    {
        $this->answer = $answer;
    }

    public function request(string $method, string $url, array $options = []): Response
    {
        $this->requests[] = [$method, $url, $options];

        return $this->answer;
    }
}
