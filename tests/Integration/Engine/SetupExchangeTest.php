<?php
declare(strict_types=1);

namespace Tests\Integration\Engine;

use PrestaShop\Module\SmailyForPrestaShop\Engine\Client;
use PrestaShop\Module\SmailyForPrestaShop\Engine\Disconnector;
use PrestaShop\Module\SmailyForPrestaShop\Engine\Exception\EngineException;
use PrestaShop\Module\SmailyForPrestaShop\Engine\Exception\EngineRequestException;
use PrestaShop\Module\SmailyForPrestaShop\Engine\Exception\EngineTransportException;
use PrestaShop\Module\SmailyForPrestaShop\Engine\HealthCheck;
use PrestaShop\Module\SmailyForPrestaShop\Engine\Setup;
use PrestaShop\Module\SmailyForPrestaShop\Scope\StoreLayout;
use Tests\Integration\Fixtures\EngineMockServer;
use Tests\Integration\MultistoreShop;

require_once __DIR__ . '/EngineTestCase.php';
require_once __DIR__ . '/../MultistoreShop.php';

/**
 * A setup link becomes a stored connection: the request carries facts of the store only, the
 * answer is stored whole or not at all, a link of another Smaily account is refused, and each
 * separately set-up shop gets a connection of its own.
 */
final class SetupExchangeTest extends EngineTestCase
{
    use MultistoreShop;

    /** No prefix of the engine's form: tags, quotes, a backslash, a format mark, a back-tick. */
    private const ODD_PREFIX = "<c9>-'\"\\ %s\u{00e4}`;--\n";

    private const REMOTE_TEXT = 'No customer with email shopper@example.test';

    protected function tearDown(): void
    {
        if (defined('_PS_ROOT_DIR_') && $this->savedContext > 0) {
            $this->closeMultistore();
        }
        parent::tearDown();
    }

    public function testNothingIsConnectedUntilASetupLinkIsExchanged(): void
    {
        self::assertSame(0, self::connectionRows());
        self::assertSame([], $this->engine->requests());
    }

    public function testASetupLinkStoresTheConnectionWithAnEncryptedKeyAndTheEndpointsMap(): void
    {
        $this->engine->set('next_prefix', 'c2-');

        $connection = $this->connect();

        $issued = $this->engine->connections();
        self::assertCount(1, $issued);
        $key = (string) array_key_first($issued);
        self::assertSame($key, EngineMockServer::keyId($connection->apiKey()));
        self::assertSame($issued[$key]['tenant_id'], $connection->tenantId());
        self::assertSame(0, $connection->idShop());
        self::assertSame('c2-', $connection->prefix());
        self::assertSame('', $connection->refusedAt());
        self::assertSame(HealthCheck::CONNECTED, $connection->health()['status']);

        $row = self::row(0);
        self::assertStringNotContainsString($key, implode('|', $row), 'the key is stored encrypted only');
        $settings = json_decode($row['settings'], true);
        self::assertSame(
            ['tenant_name', 'engine_base_url', 'engine_version', 'endpoints', 'config', 'issued_at', 'refused_at', 'health'],
            array_keys($settings)
        );
        self::assertCount(26, $settings['endpoints']);
        self::assertSame('https://intelligence.smaily.com/api/v1/store/uninstall', $settings['endpoints']['store_uninstall']);
        self::assertSame(['start_months' => 18, 'horizon_months' => 60, 'order' => 'newest_first'], $settings['config']['history']);
        self::assertSame(['prefix' => 'c2-'], $settings['config']['connection']);
    }

    public function testTheRequestCarriesFactsOfTheStoreAndNothingOfAShopper(): void
    {
        $this->connect();

        $requests = $this->engine->requests();
        self::assertCount(1, $requests, 'one request: the exchange');
        $body = $requests[0]['body'];
        self::assertSame(['setup_token', 'plugin_info', 'capabilities'], array_keys($body));
        self::assertSame('tok1', $body['setup_token']);
        self::assertSame(['connection_prefix'], $body['capabilities']);
        self::assertSame(
            ['name', 'version', 'platform', 'platform_version', 'ecommerce_platform', 'ecommerce_platform_version', 'site_url'],
            array_keys($body['plugin_info'])
        );
        self::assertSame(
            ['smaily-connect-prestashop', self::module()->version, 'prestashop', _PS_VERSION_, 'prestashop', _PS_VERSION_],
            array_slice(array_values($body['plugin_info']), 0, 6)
        );
        $shop = new \Shop((int) \Configuration::get('PS_SHOP_DEFAULT'));
        self::assertMatchesRegularExpression('~^https?://' . preg_quote($shop->domain, '~') . '/~', $body['plugin_info']['site_url']);
        self::assertNull($requests[0]['key']);
    }

    /**
     * @dataProvider prefixes
     */
    public function testThePrefixIsStoredByteForByteAsTheEngineGaveIt(string $prefix): void
    {
        $this->engine->set('prefix_answer', ['exchange' => $prefix]);

        $connection = $this->connect();

        self::assertSame(bin2hex($prefix), bin2hex($connection->prefix()));
        self::assertSame(bin2hex($prefix), bin2hex($this->connections()->find(0)->prefix()));
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
    public function testAPrefixOfAnotherFormIsRefusedAndNothingIsStored(string $prefix): void
    {
        $this->engine->set('prefix_answer', ['exchange' => $prefix]);

        self::assertSame([0, Setup::INVALID_ANSWER], $this->failure(fn () => $this->connect()));
        self::assertSame(0, self::connectionRows());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function otherForms(): iterable
    {
        yield 'characters a parser trips on' => [self::ODD_PREFIX];
        yield 'another form' => ['shop_7.'];
        yield 'a number from zero' => ['c0-'];
    }

    public function testAnEngineThatNamesNoPrefixStoresNone(): void
    {
        $this->engine->set('omit_connection', ['exchange' => true]);

        $connection = $this->connect();

        self::assertSame('', $connection->prefix());
        self::assertArrayNotHasKey('connection', $connection->config());
    }

    public function testAPrefixThatIsNoTextIsRefusedAndNothingIsStored(): void
    {
        $this->engine->set('prefix_answer', ['exchange' => ['c2-']]);

        self::assertSame([0, Setup::INVALID_ANSWER], $this->failure(fn () => $this->connect()));
        self::assertSame(0, self::connectionRows());
    }

    public function testALinkOfAnotherSmailyAccountIsRefusedBeforeAnythingIsStored(): void
    {
        $this->storeAccount(0, 'engine-demo');
        $this->storeAccount(0, 'engine-demo-et', 'et');
        $this->engine->set('smaily_subdomain', 'engine-other');

        self::assertSame([0, Setup::ACCOUNT_MISMATCH], $this->failure(fn () => $this->connect()));

        self::assertSame(0, self::connectionRows());
        self::assertCount(1, $this->engine->requests(), 'the exchange was the only request');
    }

    public function testALinkOfOneOfTheShopsOwnAccountsConnectsAndNamesIt(): void
    {
        $this->storeAccount(0, 'engine-demo');
        $this->storeAccount(0, 'Engine-Demo-ET', 'et');
        $this->engine->set('smaily_subdomain', 'engine-demo-et');

        self::assertSame('engine-demo-et', $this->connect()->smailySubdomain());
    }

    public function testAnEngineThatNamesNoAccountConnectsUnderTheShopsDefaultAccount(): void
    {
        $this->storeAccount(0, 'Engine-Demo');

        self::assertSame('engine-demo', $this->connect()->smailySubdomain());
    }

    public function testAShopWithoutASmailyAccountConnects(): void
    {
        $this->engine->set('smaily_subdomain', 'engine-other');

        self::assertSame('engine-other', $this->connect()->smailySubdomain());
    }

    public function testAReconnectReportsThePrefixTheConnectionHolds(): void
    {
        $this->engine->set('next_prefix', 'c2-');
        $first = $this->connect();
        $this->engine->set('next_prefix', 'c3-');

        $second = $this->connect();

        $exchanges = $this->engine->requestsTo('/api/setup/exchange');
        self::assertArrayNotHasKey('connection', $exchanges[0]['body'], 'a first connection reports none');
        self::assertSame(['previous_prefix' => 'c2-'], $exchanges[1]['body']['connection']);
        self::assertSame('c2-', $second->prefix(), 'the engine gave the prefix back');
        self::assertSame($first->id(), $second->id());
        self::assertNotSame($first->apiKey(), $second->apiKey());
        self::assertSame(1, self::connectionRows());
    }

    public function testAReconnectOfAnUnprefixedConnectionReportsTheEmptyPrefix(): void
    {
        $this->connect();

        $this->connect();

        self::assertSame(['previous_prefix' => ''], $this->engine->requestsTo('/api/setup/exchange')[1]['body']['connection']);
    }

    public function testAConnectAfterADisconnectReportsThePrefixThatWasHeld(): void
    {
        $this->engine->set('next_prefix', 'c7-');
        $this->services->get(Disconnector::class)->disconnect($this->connect());
        self::assertSame(0, self::connectionRows());

        // Another request: the remembered prefix is read from the stored configuration.
        \Configuration::resetStaticCache();
        $this->services = $this->runtime();
        $this->connect();

        $again = $this->engine->requestsTo('/api/setup/exchange')[1]['body'];
        self::assertSame('c7-', $again['connection']['previous_prefix']);
    }

    /**
     * @dataProvider refusals
     */
    public function testAnExchangeTheEngineRefusesStoresNothing(int $status, ?string $error, string $class, string $code): void
    {
        $this->engine->fail('exchange', $status, $error, self::REMOTE_TEXT);

        try {
            $this->connect();
            self::fail('HTTP ' . $status . ' connected the shop.');
        } catch (EngineException $failure) {
            self::assertSame([$class, $status, $code], [get_class($failure), $failure->httpStatus(), $failure->errorCode()]);
            self::assertStringNotContainsString('shopper', $failure->getMessage());
        }
        self::assertSame(0, self::connectionRows());
    }

    /**
     * @return iterable<string, array{int, ?string, class-string<EngineException>, string}>
     */
    public static function refusals(): iterable
    {
        yield 'the engine requires a newer module' => [409, null, EngineRequestException::class, 'plugin_update_required'];
        yield 'a used link' => [410, 'setup_token_expired_or_used', EngineRequestException::class, 'setup_token_expired_or_used'];
        yield 'an unknown link' => [404, 'setup_token_not_found', EngineRequestException::class, 'setup_token_not_found'];
        yield 'an engine error' => [500, 'internal_error', EngineTransportException::class, 'internal_error'];
        yield 'a rate limit' => [429, 'rate_limited', EngineTransportException::class, 'rate_limited'];
    }

    public function testAFailedReconnectKeepsTheConnectionThatWorks(): void
    {
        $first = $this->connect();
        $this->engine->fail('exchange', 500);

        self::assertSame([500, 'internal_error'], $this->failure(fn () => $this->connect()));

        self::assertSame($first->apiKey(), $this->connections()->find(0)->apiKey());
    }

    public function testALinkToAnotherHostSendsNothing(): void
    {
        self::assertSame(
            [0, Client::HOST_NOT_ALLOWED],
            $this->failure(fn () => $this->services->get(Setup::class)->connect(0, 'https://engine.example.test/setup/tok1'))
        );
        self::assertSame(
            [0, Setup::INVALID_LINK],
            $this->failure(fn () => $this->services->get(Setup::class)->connect(0, 'not a link'))
        );
        self::assertSame([], $this->engine->requests());
        self::assertSame(0, self::connectionRows());
    }

    public function testTheShopsOfOneStoreShareOneConnection(): void
    {
        $this->openMultistore();
        $second = $this->addShop($this->addShopGroup(true));
        \Configuration::updateGlobalValue(StoreLayout::SEPARATE_STORES, '0');
        $this->services = $this->runtime();

        $connection = $this->services->get(Setup::class)->connect($second, $this->engine->setupLink('tok-one'));

        self::assertSame(0, $connection->idShop());
        self::assertSame(1, self::connectionRows());
        self::assertSame($connection->id(), $this->connections()->forShop($second)->id());
        self::assertSame($connection->id(), $this->connections()->forShop($this->defaultShopId())->id());
        $body = $this->engine->requests()[0]['body'];
        self::assertSame(['setup_token', 'plugin_info', 'store_config', 'capabilities'], array_keys($body));
        self::assertSame(['customer_account_scope' => 'website'], $body['store_config'], 'two shop groups keep customers apart');
        $default = new \Shop($this->defaultShopId());
        self::assertStringContainsString('://' . $default->domain . '/', $body['plugin_info']['site_url'], 'the installation connects with its default shop');
    }

    public function testSeparateStoresEachConnectOnTheirOwn(): void
    {
        $this->openMultistore();
        $first = $this->defaultShopId();
        $second = $this->addShop((int) \Shop::getGroupFromShop($first));
        $this->setShareCustomer((int) \Shop::getGroupFromShop($first), true);
        \Configuration::updateGlobalValue(StoreLayout::SEPARATE_STORES, '1');
        $this->services = $this->runtime();
        $this->storeAccount($first, 'engine-demo');
        $this->storeAccount($second, 'engine-second');
        $setup = $this->services->get(Setup::class);
        $this->engine->set('tenant_id', '550e8400-e29b-41d4-a716-446655440000');
        $this->engine->set('smaily_subdomain', 'engine-demo');
        $this->engine->set('next_prefix', 'c2-');

        $one = $setup->connect($first, $this->engine->setupLink('tok-first'));
        self::assertSame(
            [0, Setup::ACCOUNT_MISMATCH],
            $this->failure(fn () => $setup->connect($second, $this->engine->setupLink('tok-wrong'))),
            'the first shop\'s account is not the second shop\'s'
        );
        $this->engine->set('smaily_subdomain', 'engine-second');
        $this->engine->set('next_prefix', 'c3-');
        $two = $setup->connect($second, $this->engine->setupLink('tok-second'));

        self::assertSame(2, self::connectionRows());
        self::assertSame([$first, 'engine-demo', 'c2-'], [$one->idShop(), $one->smailySubdomain(), $one->prefix()]);
        self::assertSame([$second, 'engine-second', 'c3-'], [$two->idShop(), $two->smailySubdomain(), $two->prefix()]);
        self::assertNotSame($one->apiKey(), $two->apiKey());
        self::assertSame($one->tenantId(), $two->tenantId(), 'two connections may join one engine store');
        self::assertSame($one->id(), $this->connections()->forShop($first)->id());
        self::assertSame($two->id(), $this->connections()->forShop($second)->id());

        $exchanges = $this->engine->requestsTo('/api/setup/exchange');
        self::assertSame(['customer_account_scope' => 'global'], $exchanges[0]['body']['store_config'], 'one group that shares customers');
        self::assertStringContainsString('shop' . $second . '.example.invalid', $exchanges[2]['body']['plugin_info']['site_url']);
        self::assertArrayNotHasKey('connection', $exchanges[2]['body'], 'the second shop never held a prefix');

        try {
            $setup->connect(0, $this->engine->setupLink('tok-all'));
            self::fail('The installation connected in separate stores.');
        } catch (\InvalidArgumentException $error) {
            self::assertCount(3, $this->engine->requestsTo('/api/setup/exchange'));
        }
    }

    /**
     * @return array{int, string} the HTTP status and the code of the failure that $call meets
     */
    private function failure(callable $call): array
    {
        try {
            $call();
        } catch (EngineException $failure) {
            return [$failure->httpStatus(), $failure->errorCode()];
        }
        self::fail('The call went through.');
    }
}
