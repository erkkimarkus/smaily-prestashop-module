<?php
declare(strict_types=1);

namespace Tests\Integration\Engine;

use PrestaShop\Module\SmailyForPrestaShop\Config\EngineSettings;
use PrestaShop\Module\SmailyForPrestaShop\Engine\Client;
use PrestaShop\Module\SmailyForPrestaShop\Engine\Disconnector;
use PrestaShop\Module\SmailyForPrestaShop\Install\Schema;
use PrestaShop\Module\SmailyForPrestaShop\Install\Uninstaller;
use PrestaShop\Module\SmailyForPrestaShop\Migration\LegacyOverride;
use PrestaShop\Module\SmailyForPrestaShop\Scope\StoreLayout;
use Tests\Integration\Fixtures\EngineMockServer;
use Tests\Integration\Migration\LegacyStore;

require_once __DIR__ . '/EngineTestCase.php';
require_once __DIR__ . '/../Migration/LegacyStore.php';

/**
 * A disconnect tells the engine once with the connection's own key and removes the stored
 * connection whatever the engine answers. An uninstall ends every connection before the module's
 * data goes, and an engine that fails or does not answer never blocks it.
 */
final class DisconnectTest extends EngineTestCase
{
    private const UNINSTALL = '/api/v1/store/uninstall';

    protected function tearDown(): void
    {
        if (defined('_PS_ROOT_DIR_') && !Schema::isCurrent()) {
            // An uninstall test removed the module's data: the next test needs a fresh install.
            LegacyStore::restore();
        }
        parent::tearDown();
    }

    public function testADisconnectTellsTheEngineWithTheStoredKeyAndRemovesTheConnection(): void
    {
        $this->engine->set('next_prefix', 'c2-');
        $connection = $this->connect();

        $outcome = $this->services->get(Disconnector::class)->disconnect($connection);

        self::assertSame(Disconnector::PAUSED, $outcome);
        $requests = $this->engine->requestsTo(self::UNINSTALL);
        self::assertCount(1, $requests);
        self::assertSame(['POST', EngineMockServer::keyId($connection->apiKey()), ''], [$requests[0]['method'], $requests[0]['key'], $requests[0]['raw']]);
        self::assertSame('uninstall', $this->engine->connections()[EngineMockServer::keyId($connection->apiKey())]['revoked']);
        self::assertSame(0, self::connectionRows(), 'no connection row and no key remains');
        self::assertNull($this->connections()->find(0));
        self::assertSame('c2-', $this->services->get(EngineSettings::class)->previousPrefix(0));
    }

    public function testTwoShopsOnOneEngineStoreDisconnectOneByOne(): void
    {
        \Configuration::updateGlobalValue(StoreLayout::SEPARATE_STORES, '1');
        $this->services = $this->runtime();
        $this->engine->set('tenant_id', '550e8400-e29b-41d4-a716-446655440000');
        $first = $this->connect(991);
        $second = $this->connect(992);
        $disconnector = $this->services->get(Disconnector::class);

        self::assertSame(Disconnector::CONNECTION_REMOVED, $disconnector->disconnect($first));
        self::assertNull($this->connections()->find(991));
        self::assertSame($second->apiKey(), $this->connections()->find(992)->apiKey(), 'the other shop stays connected');
        self::assertSame(Disconnector::PAUSED, $disconnector->disconnect($second));
        self::assertSame(0, self::connectionRows());
    }

    /**
     * @dataProvider engineFailures
     */
    public function testAnEngineThatDoesNotTakeTheCallStillLosesTheConnectionHere(int $status, string $outcome): void
    {
        $connection = $this->connect();
        $this->engine->fail('uninstall', $status, null, 'No customer with email shopper@example.test');

        self::assertSame($outcome, $this->services->get(Disconnector::class)->disconnect($connection));

        self::assertCount(1, $this->engine->requestsTo(self::UNINSTALL), 'one attempt, no retry');
        self::assertSame(0, self::connectionRows());
    }

    /**
     * @return iterable<string, array{int, string}>
     */
    public static function engineFailures(): iterable
    {
        yield 'a key the engine no longer takes' => [401, Disconnector::KEY_REFUSED];
        yield 'an engine error' => [500, Disconnector::NOT_TOLD];
        yield 'a rate limit' => [429, Disconnector::NOT_TOLD];
    }

    public function testAConnectionWhoseKeyDoesNotDecryptIsRemovedWithoutACall(): void
    {
        $this->connect();
        self::db()->update(self::CONNECTION_TABLE, ['api_key_enc' => 'not-a-ciphertext'], '`id_shop` = 0');

        self::assertSame(Disconnector::NOT_TOLD, $this->services->get(Disconnector::class)->disconnect($this->connections()->find(0)));

        self::assertCount(0, $this->engine->requestsTo(self::UNINSTALL));
        self::assertSame(0, self::connectionRows());
    }

    public function testAnUninstallEndsEveryConnectionOnceBeforeTheDataGoes(): void
    {
        LegacyStore::rememberTokens();
        \Configuration::updateGlobalValue(StoreLayout::SEPARATE_STORES, '1');
        $this->services = $this->runtime();
        $first = $this->connect(991);
        $second = $this->connect(992);

        self::assertTrue((new Uninstaller($this->services, new LegacyOverride(_PS_OVERRIDE_DIR_)))->uninstall());

        self::assertSame(
            [EngineMockServer::keyId($first->apiKey()), EngineMockServer::keyId($second->apiKey())],
            array_column($this->engine->requestsTo(self::UNINSTALL), 'key'),
            'one call per connection, each with its own key'
        );
        self::assertSame(['uninstall', 'uninstall'], array_column($this->engine->connections(), 'revoked'));
        self::assertSame([], self::db()->executeS('SHOW TABLES LIKE \'' . _DB_PREFIX_ . 'smaily\\_connect\\_connection\''), 'no connection table remains');
        self::assertSame([], self::db()->executeS(
            'SELECT `name` FROM `' . _DB_PREFIX_ . 'configuration` WHERE `name` LIKE \'SMAILY\\_CONNECT\\_ENGINE%\''
        ));
    }

    public function testAnEngineThatDoesNotAnswerDoesNotBlockTheUninstall(): void
    {
        \Configuration::updateGlobalValue(StoreLayout::SEPARATE_STORES, '1');
        $this->services = $this->runtime();
        $this->connect(991);
        $this->connect(992);
        // Neither call gets an answer in time.
        $this->engine->delay('uninstall', Client::DISCONNECT_TIMEOUT_SECONDS + 1.5);

        $start = microtime(true);
        $removed = (new Uninstaller($this->services, new LegacyOverride(_PS_OVERRIDE_DIR_)))->uninstall();
        $seconds = microtime(true) - $start;

        self::assertTrue($removed);
        self::assertLessThan(2 * Client::DISCONNECT_TIMEOUT_SECONDS + 2, $seconds, 'each connection waits 3 seconds at most');
        self::assertCount(2, $this->engine->requestsTo(self::UNINSTALL), 'both connections were tried, once each');
        self::assertFalse(Schema::isCurrent());
        self::assertSame([], self::db()->executeS('SHOW TABLES LIKE \'' . _DB_PREFIX_ . 'smaily\\_connect\\_connection\''));
    }

    public function testAnUninstallWithoutAConnectionAsksNothing(): void
    {
        self::assertTrue((new Uninstaller($this->services, new LegacyOverride(_PS_OVERRIDE_DIR_)))->uninstall());

        self::assertSame([], $this->engine->requests());
    }
}
