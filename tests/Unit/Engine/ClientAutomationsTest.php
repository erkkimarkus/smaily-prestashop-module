<?php
declare(strict_types=1);

namespace Tests\Unit\Engine;

use PHPUnit\Framework\TestCase;
use PrestaShop\Module\SmailyForPrestaShop\Engine\Client;
use PrestaShop\Module\SmailyForPrestaShop\Engine\Connection;
use PrestaShop\Module\SmailyForPrestaShop\Engine\Exception\EngineRequestException;
use PrestaShop\Module\SmailyForPrestaShop\Http\Response;
use PrestaShop\Module\SmailyForPrestaShop\Http\Transport;

/**
 * The three automation calls of the engine client (contract §11, §12 and §13): each is one attempt
 * of ten seconds with the connection's key, and a refused save names the fields at fault by
 * position, key and field, never by the engine's text.
 */
final class ClientAutomationsTest extends TestCase
{
    private const KEY = 'sk_not_a_real_key';

    private const REMOTE_TEXT = 'automation_map.fallback on nõutav';

    public function testTheCatalogueIsAGetWithTheConnectionsKey(): void
    {
        $transport = new AutomationsRecordingTransport(new Response(200, '{"triggers": []}'));

        $answer = (new Client($transport, '3.0.0'))->automationsCatalog(self::connection());

        self::assertSame(['triggers' => []], $answer);
        self::assertSame([[
            'GET',
            'https://intelligence.smaily.com/api/v1/automations/catalog',
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

    public function testTheConfigurationIsReadFromTheAddressOfTheEndpointsMap(): void
    {
        $transport = new AutomationsRecordingTransport(new Response(200, '{"configs": []}'));
        $connection = new Connection(1, 0, 'demo', 'tenant', self::KEY, [
            'engine_base_url' => Client::BASE_URL,
            'endpoints' => ['automations_config' => Client::BASE_URL . '/api/v2/automations/config'],
        ]);

        (new Client($transport, '3.0.0'))->automationsConfig($connection);

        self::assertSame('GET', $transport->requests[0][0]);
        self::assertSame('https://intelligence.smaily.com/api/v2/automations/config', $transport->requests[0][1]);
        self::assertArrayNotHasKey('json', $transport->requests[0][2]);
    }

    public function testASaveIsAPutOfTheRowsUnderConfigs(): void
    {
        $transport = new AutomationsRecordingTransport(new Response(200, '{"ok": true, "upserted": 1}'));
        $rows = [['trigger_key' => 'replenish_due', 'enabled' => false]];

        $answer = (new Client($transport, '3.0.0'))->saveAutomationsConfig(self::connection(), $rows);

        self::assertSame(['ok' => true, 'upserted' => 1], $answer);
        self::assertSame('PUT', $transport->requests[0][0]);
        self::assertSame('https://intelligence.smaily.com/api/v1/automations/config', $transport->requests[0][1]);
        self::assertSame(['configs' => $rows], $transport->requests[0][2]['json']);
        self::assertSame(10, $transport->requests[0][2]['timeout']);
    }

    public function testARefusedSaveNamesItsFieldsAndNeverTheEnginesText(): void
    {
        $transport = new AutomationsRecordingTransport(new Response(422, (string) json_encode([
            'error' => 'validation_failed',
            'errors' => [
                ['index' => 0, 'trigger_key' => 'replenish_due', 'field' => 'automation_map', 'message' => self::REMOTE_TEXT],
                ['index' => 2, 'field' => 'test_emails.1', 'message' => 'Invalid email'],
                ['field' => 'configs', 'message' => 'Expected 1 to 50 rows'],
                // Not plain values: left out, or read as no key.
                ['index' => 1, 'field' => 'a field with <b>text</b>', 'message' => 'x'],
                ['index' => '3', 'trigger_key' => 'a key with text', 'field' => 'cooldown_days', 'message' => 'x'],
                'not an entry',
            ],
        ])));

        try {
            (new Client($transport, '3.0.0'))->saveAutomationsConfig(self::connection(), [['trigger_key' => 'replenish_due']]);
            self::fail('The refusal was not thrown.');
        } catch (EngineRequestException $refusal) {
            self::assertSame('validation_failed', $refusal->errorCode());
            self::assertSame(422, $refusal->httpStatus());
            self::assertSame([
                ['index' => 0, 'key' => 'replenish_due', 'field' => 'automation_map'],
                ['index' => 2, 'key' => '', 'field' => 'test_emails.1'],
                ['index' => null, 'key' => '', 'field' => 'configs'],
                ['index' => null, 'key' => '', 'field' => 'cooldown_days'],
            ], $refusal->fields());
            self::assertStringNotContainsString(self::REMOTE_TEXT, $refusal->getMessage());
            self::assertStringNotContainsString('Invalid email', (string) json_encode($refusal->fields()));
        }
    }

    public function testARefusalWithoutErrorsNamesNoField(): void
    {
        $transport = new AutomationsRecordingTransport(new Response(403, '{"error": "tenant_inactive", "message": "text"}'));

        try {
            (new Client($transport, '3.0.0'))->automationsCatalog(self::connection());
            self::fail('The refusal was not thrown.');
        } catch (EngineRequestException $refusal) {
            self::assertSame('tenant_inactive', $refusal->errorCode());
            self::assertSame([], $refusal->fields());
        }
    }

    private static function connection(): Connection
    {
        return new Connection(1, 0, 'demo', 'tenant', self::KEY, ['engine_base_url' => Client::BASE_URL, 'endpoints' => []]);
    }
}

final class AutomationsRecordingTransport implements Transport
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
