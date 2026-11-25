<?php
declare(strict_types=1);

namespace Tests\Integration\Engine;

use PrestaShop\Module\SmailyForPrestaShop\Config\EngineSettings;
use PrestaShop\Module\SmailyForPrestaShop\Engine\Capture\IngestMarkers;
use PrestaShop\Module\SmailyForPrestaShop\Engine\Connection;
use PrestaShop\Module\SmailyForPrestaShop\Engine\Connections;
use PrestaShop\Module\SmailyForPrestaShop\Engine\Ingest\IngestQueue;
use PrestaShop\Module\SmailyForPrestaShop\Engine\Setup;
use PrestaShop\Module\SmailyForPrestaShop\Http\Transport;
use PrestaShop\Module\SmailyForPrestaShop\Runtime\Services;
use PrestaShop\Module\SmailyForPrestaShop\Scope\StoreLayout;
use PrestaShop\Module\SmailyForPrestaShop\Smaily\Account;
use PrestaShop\Module\SmailyForPrestaShop\Smaily\AccountStore;
use Tests\Integration\Fixtures\EngineMockServer;
use Tests\Integration\IntegrationTestCase;

require_once __DIR__ . '/../Fixtures/EngineMockServer.php';

/**
 * The base of the Campaign Intelligence tests: the runtime with the engine mock in place of the
 * engine, an empty connection table, and no Smaily account but the ones a test stores. Every
 * request of a test goes to the mock or nowhere (EngineMockServer::transport()).
 */
abstract class EngineTestCase extends IntegrationTestCase
{
    protected const CONNECTION_TABLE = 'smaily_connect_connection';

    private const ACCOUNT_TABLE = 'smaily_connect_account';

    private const KEYS = [StoreLayout::SEPARATE_STORES, EngineSettings::PREVIOUS_PREFIXES];

    protected EngineMockServer $engine;

    protected Services $services;

    /** @var list<array<string, mixed>> */
    private array $savedAccounts = [];

    private int $tokens = 0;

    /** The last row of the ingest queue before the test. */
    private int $ingestRows = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ingestRows = (int) self::db()->getValue('SELECT MAX(`id`) FROM `' . _DB_PREFIX_ . IngestQueue::TABLE . '`', false);
        $this->engine = EngineMockServer::start();
        $this->savedAccounts = self::db()->executeS('SELECT * FROM `' . _DB_PREFIX_ . self::ACCOUNT_TABLE . '`', true, false) ?: [];
        self::db()->delete(self::ACCOUNT_TABLE);
        self::db()->delete(self::CONNECTION_TABLE);
        self::deleteKeys();
        $this->services = $this->runtime();
    }

    protected function tearDown(): void
    {
        if (defined('_PS_ROOT_DIR_')) {
            // No connection may outlive its test: a later test that runs the whole tick would ask
            // the real engine about it.
            self::db()->delete(self::CONNECTION_TABLE);
            self::db()->delete(self::ACCOUNT_TABLE);
            // The test's hooks noted ingest markers in its runtime and in the shop's own, which
            // would write them when the run ends: they are written now, and the test's rows go.
            $this->services->get(IngestMarkers::class)->commit();
            Services::instance()->get(IngestMarkers::class)->commit();
            self::db()->delete(IngestQueue::TABLE, '`id` > ' . $this->ingestRows);
            foreach ($this->savedAccounts as $row) {
                self::db()->insert(self::ACCOUNT_TABLE, array_map(static fn ($value) => pSQL((string) $value), $row));
            }
            self::deleteKeys();
        }
        parent::tearDown();
    }

    /**
     * A runtime whose every request goes to the engine mock.
     */
    protected function runtime(): Services
    {
        $services = Services::fromProviders(Services::PROVIDERS);
        $engine = $this->engine;
        $services->set(Transport::class, static fn (): Transport => $engine->transport());

        return $services;
    }

    /**
     * Connects a shop with a setup link that the mock has not seen.
     */
    protected function connect(int $idShop = 0): Connection
    {
        return $this->services->get(Setup::class)->connect($idShop, $this->engine->setupLink('tok' . ++$this->tokens));
    }

    protected function connections(): Connections
    {
        return $this->services->get(Connections::class);
    }

    protected function storeAccount(int $idShop, string $subdomain, string $language = ''): void
    {
        $this->services->get(AccountStore::class)->save(new Account($idShop, $language, $subdomain, 'api-user', 'not-a-real-password'));
    }

    protected static function connectionRows(): int
    {
        return (int) self::db()->getValue('SELECT COUNT(*) FROM `' . _DB_PREFIX_ . self::CONNECTION_TABLE . '`', false);
    }

    /**
     * The stored row of an id_shop, as the database holds it.
     *
     * @return array<string, string>
     */
    protected static function row(int $idShop): array
    {
        $rows = self::db()->executeS('SELECT * FROM `' . _DB_PREFIX_ . self::CONNECTION_TABLE . '` WHERE `id_shop` = ' . $idShop, true, false);
        self::assertCount(1, $rows, 'one connection row of shop ' . $idShop);

        return $rows[0];
    }

    private static function deleteKeys(): void
    {
        foreach (self::KEYS as $key) {
            \Configuration::deleteByName($key);
        }
    }
}
