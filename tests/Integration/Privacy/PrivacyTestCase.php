<?php
declare(strict_types=1);

namespace Tests\Integration\Privacy;

use PrestaShop\Module\SmailyForPrestaShop\Engine\Client;
use PrestaShop\Module\SmailyForPrestaShop\Engine\Connection;
use PrestaShop\Module\SmailyForPrestaShop\Engine\Connections;
use PrestaShop\Module\SmailyForPrestaShop\Engine\Setup;
use PrestaShop\Module\SmailyForPrestaShop\Http\Response;
use PrestaShop\Module\SmailyForPrestaShop\Http\Transport;
use PrestaShop\Module\SmailyForPrestaShop\Privacy\ProfilingRecord;
use PrestaShop\Module\SmailyForPrestaShop\Queue\EventQueue;
use PrestaShop\Module\SmailyForPrestaShop\Runtime\Services;
use Tests\Integration\Discount\DiscountTestCase;
use Tests\Integration\Fixtures\EngineMockServer;

require_once __DIR__ . '/../Discount/DiscountTestCase.php';
require_once __DIR__ . '/../Fixtures/EngineMockServer.php';

/**
 * The base of the privacy tests: the store of DiscountTestCase (one Smaily account that the
 * Smaily mock accepts, the three store automations on, cart rules of its own) with the engine
 * mock in place of Campaign Intelligence. A request to the engine's host goes to the engine mock,
 * a Smaily request to the Smaily mock, and nothing leaves the test.
 *
 * The connection table, the privacy record, the attribution table and the event queue start and
 * end empty: no connection may outlive its test, as a later test that runs the whole tick would
 * ask the real engine about it. Every shopper is invented.
 */
abstract class PrivacyTestCase extends DiscountTestCase
{
    protected const CONNECTION_TABLE = 'smaily_connect_connection';

    protected const ATTRIBUTION_TABLE = 'smaily_connect_order_attribution';

    private const TABLES = [self::CONNECTION_TABLE, ProfilingRecord::TABLE, self::ATTRIBUTION_TABLE, EventQueue::TABLE, 'smaily_connect_cart'];

    protected EngineMockServer $engine;

    private int $tokens = 0;

    private ?Services $global = null;

    private bool $swapped = false;

    protected function setUp(): void
    {
        if (defined('_PS_ROOT_DIR_')) {
            // Before the parent builds the services, which take the mock's transport.
            $this->engine = EngineMockServer::start();
        }
        parent::setUp();
        self::emptyTables();
    }

    protected function tearDown(): void
    {
        if (defined('_PS_ROOT_DIR_')) {
            if ($this->swapped) {
                self::instance()->setValue(null, $this->global);
            }
            self::emptyTables();
        }
        parent::tearDown();
    }

    protected function services(): Services
    {
        $services = parent::services();
        $smaily = $this->mock->transport();
        $engine = $this->engine->transport();
        $services->set(Transport::class, static fn (): Transport => new class($engine, $smaily) implements Transport {
            private Transport $engine;

            private Transport $smaily;

            public function __construct(Transport $engine, Transport $smaily)
            {
                $this->engine = $engine;
                $this->smaily = $smaily;
            }

            public function request(string $method, string $url, array $options = []): Response
            {
                return strpos($url, EngineMockServer::ENGINE . '/') === 0
                    ? $this->engine->request($method, $url, $options)
                    : $this->smaily->request($method, $url, $options);
            }
        });

        return $services;
    }

    /**
     * Makes the module's hooks run on the test's services, so that a hook's request goes to the
     * mocks; tearDown() puts the shop's own services back.
     */
    protected function hooksUseTheMocks(): void
    {
        if (!$this->swapped) {
            $this->global = self::instance()->getValue();
            $this->swapped = true;
        }
        self::instance()->setValue(null, $this->services);
    }

    /**
     * Connects the installation with a setup link that the mock has not seen.
     */
    protected function connect(): Connection
    {
        return $this->services->get(Setup::class)->connect(0, $this->engine->setupLink('tok' . ++$this->tokens));
    }

    /**
     * A second connection, stored for another shop id, with a key of its own that the mock knows.
     */
    protected function connectAlso(int $idShop): Connection
    {
        $answer = $this->services->get(Client::class)->setupExchange(Client::BASE_URL, [
            'setup_token' => 'tok' . ++$this->tokens,
            'capabilities' => [Setup::CAPABILITY_CONNECTION_PREFIX],
        ]);

        return $this->services->get(Connections::class)->save($idShop, '', (string) $answer['tenant_id'], (string) $answer['api_key'], [
            'engine_base_url' => $answer['engine_base_url'],
            'endpoints' => $answer['endpoints'],
            'config' => $answer['config'],
        ]);
    }

    /**
     * An event-queue row as a queue writer stores it; delivered unless $columns say else.
     *
     * @param array<string, mixed> $columns
     */
    protected function row(string $email, array $columns = []): int
    {
        $now = gmdate('Y-m-d H:i:s');
        $columns += [
            'id_shop' => $this->idShop,
            'event_type' => 'contact.sync',
            'entity_id' => $email,
            'language' => 'en',
            'payload' => (string) json_encode(['email' => $email, 'customer' => ['firstname' => 'Testfirst', 'lastname' => 'Testlast']]),
            'status' => 'sent',
            'attempts' => 0,
            'last_request' => (string) json_encode([['email' => $email, 'first_name' => 'Testfirst']]),
            'last_response' => '{"http_status":200,"body":{"code":101}}',
            'created_at' => $now,
            'updated_at' => $now,
        ];
        $values = array_map(static fn ($value) => $value === null ? null : pSQL((string) $value, true), $columns);
        self::assertTrue(self::db()->insert(EventQueue::TABLE, $values, true), self::db()->getMsgError());

        return (int) self::db()->Insert_ID();
    }

    /**
     * Every row of a table of the module, oldest first.
     *
     * @return list<array<string, string|null>>
     */
    protected static function table(string $table, string $order = '1'): array
    {
        return self::db()->executeS('SELECT * FROM `' . _DB_PREFIX_ . $table . '` ORDER BY ' . $order, true, false) ?: [];
    }

    /**
     * The requests of the engine mock to a privacy route of the address.
     *
     * @return list<array<string, mixed>>
     */
    protected function engineRequests(string $method, string $email, string $suffix = ''): array
    {
        return array_values(array_filter(
            $this->engine->requestsTo('/api/v1/customer/' . rawurlencode($email) . $suffix),
            static fn (array $request): bool => $request['method'] === $method
        ));
    }

    /**
     * Makes every waiting row due now.
     */
    protected static function makeDue(): void
    {
        self::db()->execute('UPDATE `' . _DB_PREFIX_ . EventQueue::TABLE . '` SET `next_retry_at` = NULL WHERE `status` = \'pending\'');
    }

    private static function instance(): \ReflectionProperty
    {
        $property = new \ReflectionProperty(Services::class, 'instance');
        $property->setAccessible(true);

        return $property;
    }

    private static function emptyTables(): void
    {
        foreach (self::TABLES as $table) {
            self::db()->delete($table);
        }
    }
}
