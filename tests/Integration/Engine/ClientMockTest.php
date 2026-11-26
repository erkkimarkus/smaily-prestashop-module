<?php
declare(strict_types=1);

namespace Tests\Integration\Engine;

use PrestaShop\Module\SmailyForPrestaShop\Engine\Client;
use PrestaShop\Module\SmailyForPrestaShop\Engine\Connection;
use PrestaShop\Module\SmailyForPrestaShop\Engine\Exception\EngineRequestException;
use PrestaShop\Module\SmailyForPrestaShop\Engine\Exception\EngineTransportException;
use PrestaShop\Module\SmailyForPrestaShop\Engine\Setup;
use Tests\Integration\Fixtures\EngineMockServer;

require_once __DIR__ . '/EngineTestCase.php';

/**
 * The three calls of a connection go over HTTP to the engine mock, and the mock's answers hold
 * the fields that docs/RECENGINE_API_CONTRACT.md v1.37.1 names: §1 the setup exchange, §2 the
 * ping, §16 the disconnect, and the errors of "Authentication" and §1.
 */
final class ClientMockTest extends EngineTestCase
{
    /** §1 "Response 200 OK": the top-level fields. */
    private const EXCHANGE_FIELDS = ['tenant_id', 'tenant_name', 'smaily_subdomain', 'api_key', 'engine_base_url', 'engine_version', 'endpoints', 'config', 'issued_at'];

    /** §1 "Response 200 OK": the endpoints map, less the keys the engine returns only once they are live. */
    private const ENDPOINT_KEYS = [
        'ingest_ping', 'ingest_catalog', 'ingest_catalog_remove', 'ingest_catalog_manifest', 'ingest_customers',
        'ingest_orders', 'ingest_browse', 'ingest_history_progress', 'ingest_stock_alerts', 'identity_merge',
        'customer_export', 'customer_delete', 'customer_opt_out', 'recommendations_preview',
        'recommendations_issue', 'recommendations_customer', 'automations_catalog', 'automations_config',
        'notifications_ingest', 'store_uninstall', 'automations_setup_link', 'store_branding',
        'emails_design_link', 'emails_designs',
    ];

    /** §1 "Response 200 OK": the config block. */
    private const CONFIG_KEYS = [
        'tracking_cookie_name', 'session_cookie_name', 'rec_id_cookie_name', 'context_cookie_name',
        'cookie_ttl_days', 'session_ttl_days', 'rec_id_ttl_days', 'context_ttl_days', 'rate_limit_browse',
        'rate_limit_other', 'batch_size_max', 'supported_languages', 'url_param_visitor_token',
        'url_param_rec_id', 'url_param_context', 'history', 'connection',
    ];

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = $this->services->get(Client::class);
    }

    public function testTheExchangeAnswersTheContractsFields(): void
    {
        $this->engine->set('next_prefix', 'c2-');

        $answer = $this->client->setupExchange(Client::BASE_URL, ['setup_token' => 'tok1', 'capabilities' => ['connection_prefix']]);

        self::assertSame(self::EXCHANGE_FIELDS, array_keys($answer));
        self::assertSame(self::ENDPOINT_KEYS, array_keys($answer['endpoints']));
        self::assertSame(self::CONFIG_KEYS, array_keys($answer['config']));
        self::assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-8[0-9a-f]{3}-[0-9a-f]{12}$/', $answer['tenant_id']);
        self::assertMatchesRegularExpression('/^sk_[0-9a-f]{32}$/', $answer['api_key']);
        self::assertNull($answer['smaily_subdomain']);
        self::assertSame('https://intelligence.smaily.com', $answer['engine_base_url']);
        self::assertSame('https://intelligence.smaily.com/api/v1/customer/{email}/export', $answer['endpoints']['customer_export']);
        self::assertSame(['start_months' => 18, 'horizon_months' => 60, 'order' => 'newest_first'], $answer['config']['history']);
        self::assertSame(['prefix' => 'c2-'], $answer['config']['connection']);
        self::assertMatchesRegularExpression('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$/', $answer['issued_at']);
        // The module stores every address of this answer: each is the engine's own.
        self::assertNotEmpty(Setup::read($answer, '2026-11-21T10:00:00Z'));

        $request = $this->engine->requestsTo('/api/setup/exchange')[0];
        self::assertSame('POST', $request['method']);
        self::assertSame('application/json', $request['content_type']);
        self::assertSame('application/json', $request['accept']);
        self::assertMatchesRegularExpression('~^SmailyConnect-PrestaShopModule/\d+\.\d+\.\d+$~', (string) $request['user_agent']);
        self::assertNull($request['key'], 'the exchange carries no key');
        self::assertSame(['setup_token' => 'tok1', 'capabilities' => ['connection_prefix']], $request['body']);
    }

    public function testASetupTokenWorksOnce(): void
    {
        $this->client->setupExchange(Client::BASE_URL, ['setup_token' => 'tok1']);

        self::assertSame(
            [410, 'setup_token_expired_or_used'],
            $this->refusal(fn () => $this->client->setupExchange(Client::BASE_URL, ['setup_token' => 'tok1']))
        );
        self::assertSame(
            [404, 'setup_token_not_found'],
            $this->refusal(fn () => $this->client->setupExchange(Client::BASE_URL, ['setup_token' => 'notfound1']))
        );
        self::assertSame(
            [400, 'validation_failed'],
            $this->refusal(fn () => $this->client->setupExchange(Client::BASE_URL, ['plugin_info' => []]))
        );
    }

    public function testAnEngineThatRequiresTheCapabilityRefusesAConnectorWithoutItAndKeepsTheToken(): void
    {
        $this->engine->set('require_capability', true);

        self::assertSame(
            [409, 'plugin_update_required'],
            $this->refusal(fn () => $this->client->setupExchange(Client::BASE_URL, ['setup_token' => 'tok1']))
        );
        $answer = $this->client->setupExchange(Client::BASE_URL, ['setup_token' => 'tok1', 'capabilities' => ['connection_prefix']]);

        self::assertArrayHasKey('api_key', $answer, 'the same link works with the capability');
    }

    public function testOnlyAConnectorWithTheCapabilityGetsAPrefixAndAReportedOneIsReused(): void
    {
        $this->engine->set('next_prefix', 'c2-');
        $without = $this->client->setupExchange(Client::BASE_URL, ['setup_token' => 'tok1']);
        $first = $this->client->setupExchange(Client::BASE_URL, ['setup_token' => 'tok2', 'capabilities' => ['connection_prefix']]);
        $this->engine->set('next_prefix', 'c3-');
        $again = $this->client->setupExchange(Client::BASE_URL, [
            'setup_token' => 'tok3',
            'capabilities' => ['connection_prefix'],
            'connection' => ['previous_prefix' => 'c2-'],
        ]);
        $unknown = $this->client->setupExchange(Client::BASE_URL, [
            'setup_token' => 'tok4',
            'capabilities' => ['connection_prefix'],
            'connection' => ['previous_prefix' => 'c8-'],
        ]);

        self::assertSame('', $without['config']['connection']['prefix']);
        self::assertSame('c2-', $first['config']['connection']['prefix']);
        self::assertSame('c2-', $again['config']['connection']['prefix'], 'a prefix the store assigned is given back');
        self::assertSame('c3-', $unknown['config']['connection']['prefix'], 'a prefix the store never assigned is not');
    }

    public function testThePingAnswersTheContractsFieldsForAKeyTheEngineIssued(): void
    {
        $this->engine->set('next_prefix', 'c2-');
        $connection = $this->exchanged();

        $answer = $this->client->ping($connection);

        self::assertSame(['ok', 'pong', 'tenant_id', 'industry', 'engine_version', 'ts', 'config'], array_keys($answer));
        self::assertTrue($answer['ok']);
        self::assertTrue($answer['pong']);
        self::assertSame($connection->tenantId(), $answer['tenant_id']);
        self::assertSame(
            ['history' => ['start_months' => 18, 'horizon_months' => 60, 'order' => 'newest_first'], 'connection' => ['prefix' => 'c2-']],
            $answer['config']
        );

        $request = $this->engine->requestsTo('/api/v1/ingest/ping')[0];
        self::assertSame('GET', $request['method']);
        self::assertSame(EngineMockServer::keyId($connection->apiKey()), $request['key']);
        self::assertSame('', $request['raw']);
        self::assertSame([], $request['query']);
    }

    public function testAKeyTheEngineDoesNotKnowOrRevokedIsUnauthorised(): void
    {
        $connection = $this->exchanged();
        $unknown = new Connection(2, 0, '', 'tenant', 'sk_' . str_repeat('0', 32), $connection->settings());

        self::assertSame([401, 'unauthorised'], $this->refusal(fn () => $this->client->ping($unknown)));
        self::assertSame([401, 'unauthorised'], $this->refusal(fn () => $this->client->storeUninstall($unknown)));

        $this->engine->revokeKeys();
        self::assertSame([401, 'unauthorised'], $this->refusal(fn () => $this->client->ping($connection)));
        self::assertSame([401, 'unauthorised'], $this->refusal(fn () => $this->client->storeUninstall($connection)), 'revoked other than by the disconnect');
    }

    public function testTheMocksStateHoldsNoKeyThatItIssuedOrWasSent(): void
    {
        $connection = $this->exchanged();
        $unknown = new Connection(2, 0, '', 'tenant', 'sk_' . str_repeat('1', 32), $connection->settings());

        $this->client->ping($connection);
        $this->refusal(fn () => $this->client->ping($unknown));

        self::assertSame([EngineMockServer::keyId($connection->apiKey()), EngineMockServer::keyId($unknown->apiKey())], array_column($this->engine->requestsTo('/api/v1/ingest/ping'), 'key'));
        self::assertStringNotContainsString($connection->apiKey(), $this->engine->stateText());
        self::assertStringNotContainsString($unknown->apiKey(), $this->engine->stateText());
    }

    /**
     * A dev shop's state from before the mock hashed its keys held them as they are: its
     * connections keep working, and the keys leave the state.
     */
    public function testAStateThatHeldTheKeysThemselvesKeepsItsConnectionsByTheirHash(): void
    {
        $settings = $this->exchanged()->settings();
        $key = 'sk_' . str_repeat('2', 32);
        $this->engine->set('connections', [$key => ['tenant_id' => 'tenant-1', 'prefix' => '', 'revoked' => false, 'site_url' => null]]);
        $this->engine->set('requests', [['method' => 'GET', 'path' => '/api/v1/ingest/ping', 'authorization' => 'Bearer ' . $key]]);

        self::assertTrue($this->client->ping(new Connection(3, 0, '', 'tenant-1', $key, $settings))['pong']);

        self::assertSame([EngineMockServer::keyId($key)], array_keys($this->engine->connections()));
        self::assertSame([EngineMockServer::keyId($key), EngineMockServer::keyId($key)], array_column($this->engine->requests(), 'key'));
        self::assertStringNotContainsString($key, $this->engine->stateText());
    }

    public function testADeactivatedAccountRefusesThePingAndMayStillDisconnect(): void
    {
        $connection = $this->exchanged();
        $this->engine->set('tenant_inactive', true);

        self::assertSame([403, 'tenant_inactive'], $this->refusal(fn () => $this->client->ping($connection)));
        self::assertSame('store_paused', $this->client->storeUninstall($connection)['status']);
    }

    public function testTheDisconnectRevokesTheCallingKeyAndAnswersTheStoresState(): void
    {
        $this->engine->set('tenant_id', '550e8400-e29b-41d4-a716-446655440000');
        $first = $this->exchanged();
        $second = $this->exchanged();

        $answer = $this->client->storeUninstall($first);

        self::assertSame(['ok' => true, 'status' => 'connection_removed', 'other_connections_remain' => true], $answer);
        $request = $this->engine->requestsTo('/api/v1/store/uninstall')[0];
        self::assertSame('POST', $request['method']);
        self::assertSame(EngineMockServer::keyId($first->apiKey()), $request['key']);
        self::assertSame('', $request['raw'], 'the disconnect has no body');
        self::assertSame([401, 'unauthorised'], $this->refusal(fn () => $this->client->ping($first)), 'the key is revoked');
        self::assertTrue($this->client->ping($second)['pong'], 'the store\'s other connection keeps working');
        self::assertSame(
            ['ok' => true, 'status' => 'connection_removed', 'other_connections_remain' => true],
            $this->client->storeUninstall($first),
            'a repeat with the same key answers the current state'
        );

        self::assertSame(
            ['ok' => true, 'status' => 'store_paused', 'other_connections_remain' => false],
            $this->client->storeUninstall($second)
        );
    }

    public function testAnEngineErrorAndAnEngineThatDoesNotAnswerMayPassLater(): void
    {
        $connection = $this->exchanged();
        $this->engine->fail('ping', 503, 'service_unavailable', 'No customer with email shopper@example.test');
        try {
            $this->client->ping($connection);
            self::fail('HTTP 503 is a failure.');
        } catch (EngineTransportException $failure) {
            self::assertSame([503, 'service_unavailable'], [$failure->httpStatus(), $failure->errorCode()]);
            self::assertStringNotContainsString('shopper', $failure->getMessage());
        }

        $this->engine->set('fail', []);
        $this->engine->delay('uninstall', Client::DISCONNECT_TIMEOUT_SECONDS + 1.5);
        $start = microtime(true);
        try {
            $this->client->storeUninstall($connection);
            self::fail('The engine answered in time.');
        } catch (EngineTransportException $failure) {
            self::assertSame([0, Client::NETWORK_ERROR], [$failure->httpStatus(), $failure->errorCode()]);
        }
        self::assertLessThan(Client::DISCONNECT_TIMEOUT_SECONDS + 1, microtime(true) - $start, 'the disconnect waits 3 seconds at most');
    }

    /**
     * A connection as the module stores it after an exchange with the mock, not saved.
     */
    private function exchanged(): Connection
    {
        static $token = 0;
        $answer = $this->client->setupExchange(Client::BASE_URL, ['setup_token' => 'exchanged' . ++$token, 'capabilities' => ['connection_prefix']]);
        $stored = Setup::read($answer, '2026-11-21T10:00:00Z');

        return new Connection(1, 0, $stored['smaily_subdomain'], $stored['tenant_id'], $stored['api_key'], $stored['settings']);
    }

    /**
     * @return array{int, string} the HTTP status and the code of the refusal that $call meets
     */
    private function refusal(callable $call): array
    {
        try {
            $call();
        } catch (EngineRequestException $refusal) {
            return [$refusal->httpStatus(), $refusal->errorCode()];
        }
        self::fail('The engine took the request.');
    }
}
