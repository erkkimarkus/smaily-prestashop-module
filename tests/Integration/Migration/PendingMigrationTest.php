<?php
declare(strict_types=1);

namespace Tests\Integration\Migration;

use PrestaShop\Module\SmailyForPrestaShop\Config\TickSettings;
use PrestaShop\Module\SmailyForPrestaShop\Engine\Client as EngineClient;
use PrestaShop\Module\SmailyForPrestaShop\Hook\Dispatcher;
use PrestaShop\Module\SmailyForPrestaShop\Http\Response;
use PrestaShop\Module\SmailyForPrestaShop\Http\Transport;
use PrestaShop\Module\SmailyForPrestaShop\Install\Schema;
use PrestaShop\Module\SmailyForPrestaShop\Migration\LegacyState;
use PrestaShop\Module\SmailyForPrestaShop\Migration\Upgrade300;
use PrestaShop\Module\SmailyForPrestaShop\Report\ReportSender;
use PrestaShop\Module\SmailyForPrestaShop\Runtime\Services;
use PrestaShop\Module\SmailyForPrestaShop\Scheduler\Door;
use PrestaShop\Module\SmailyForPrestaShop\Scheduler\Tick;
use PrestaShop\Module\SmailyForPrestaShop\Scheduler\TickReport;
use PrestaShop\Module\SmailyForPrestaShop\Scheduler\TrafficTick;
use PrestaShop\Module\SmailyForPrestaShop\Update\LatestRelease;
use Tests\Integration\IntegrationTestCase;

require_once __DIR__ . '/LegacyStore.php';

/**
 * A migration that stopped, or never started because the files were copied by FTP, completes from
 * the tick: from each door with its token, and from the fallback tick after a page request. Once the
 * schema is current, the tick's migration job does no migration work.
 */
final class PendingMigrationTest extends IntegrationTestCase
{
    private const TOKENS = [TickSettings::CUSTOMER_CRON_TOKEN, TickSettings::CART_CRON_TOKEN];

    protected function setUp(): void
    {
        parent::setUp();
        LegacyStore::clear();
    }

    protected function tearDown(): void
    {
        if (defined('_PS_ROOT_DIR_')) {
            LegacyStore::restore();
            self::recordModuleVersion('3.0.0');
        }
        parent::tearDown();
    }

    /**
     * @return array<string, array{string, string}>
     */
    public function doors(): array
    {
        return [
            'tick URL' => [Door::TICK, TickSettings::TICK_TOKEN],
            'customer cron URL' => [Door::CUSTOMER_CRON, TickSettings::CUSTOMER_CRON_TOKEN],
            'cart cron URL' => [Door::CART_CRON, TickSettings::CART_CRON_TOKEN],
        ];
    }

    /**
     * @dataProvider doors
     */
    public function testEachDoorWithItsTokenCompletesAPendingMigration(string $door, string $tokenKey): void
    {
        LegacyStore::loadFixture('2.2.0-configured.sql');
        self::recordModuleVersion('2.2.0');
        $token = (new TickSettings())->token($tokenKey, 1);

        [$status] = Services::fromProviders(Services::PROVIDERS)->get(Door::class)->open($door, $token, 1, 30);

        self::assertSame(200, $status);
        self::assertTrue(Schema::isCurrent());
        foreach (LegacyState::KEYS as $key) {
            if (in_array($key, self::TOKENS, true)) {
                self::assertGreaterThan(0, LegacyStore::countRows($key), $key . ' stays');
            } else {
                self::assertSame(0, LegacyStore::countRows($key), $key . ' is deleted');
            }
        }
    }

    public function testAPageRequestCompletesAPendingMigrationAfterItsResponse(): void
    {
        LegacyStore::loadFixture('2.2.0-configured.sql');
        self::recordModuleVersion('2.2.0');
        $services = Services::fromProviders(Services::PROVIDERS);
        $shutdown = [];
        $services->set(TrafficTick::class, function (Services $services) use (&$shutdown): TrafficTick {
            return new TrafficTick(
                $services->get(TickSettings::class),
                fn (): Tick => $services->get(Tick::class),
                'time',
                function (): void {
                },
                function (callable $callback) use (&$shutdown): void {
                    $shutdown[] = $callback;
                }
            );
        });
        $dispatcher = new Dispatcher([Schema::class, 'isCurrent'], fn (): Services => $services, function (string $hook, \Throwable $error): void {
            self::fail(sprintf('A listener of %s failed with %s', $hook, get_class($error)));
        });

        $dispatcher->run('actionFrontControllerSetMedia', []);

        self::assertFalse(Schema::isCurrent(), 'nothing runs during the request');
        self::assertCount(1, $shutdown);
        ($shutdown[0])();
        self::assertTrue(Schema::isCurrent());
        self::assertSame(0, LegacyStore::countRows('SMAILY_PASSWORD'));
    }

    public function testOnceTheSchemaIsCurrentTheMigrationJobDoesNoMigrationWork(): void
    {
        LegacyStore::restore();
        $services = Services::fromProviders(Services::PROVIDERS);
        $built = false;
        $services->set(Upgrade300::class, function () use (&$built): Upgrade300 {
            $built = true;
            throw new \LogicException('The migration was started.');
        });
        // The update check asks no real GitHub: its request finds no release.
        $services->set(LatestRelease::class, fn (): LatestRelease => new LatestRelease(new class() implements Transport {
            public function request(string $method, string $url, array $options = []): Response
            {
                return new Response(404, '');
            }
        }, '3.0.0'));
        // The health check asks no real engine: a connection that a test left behind finds no answer.
        $services->set(EngineClient::class, fn (): EngineClient => new EngineClient(new class() implements Transport {
            public function request(string $method, string $url, array $options = []): Response
            {
                return new Response(404, '');
            }
        }, '3.0.0'));
        // The installation report asks no real receiver: an account that a test left behind sends nowhere.
        $services->set(ReportSender::class, fn (): ReportSender => new ReportSender(new class() implements Transport {
            public function request(string $method, string $url, array $options = []): Response
            {
                return new Response(404, '');
            }
        }, '3.0.0'));

        $report = $services->get(Tick::class)->run('tick', 50);

        self::assertSame(TickReport::RAN, $report->status());
        self::assertSame(['queue_flush', 'queue_janitor', 'contacts_reconcile', 'contacts_refresh', 'contacts_import', 'abandoned_cart', 'engine_health', 'ingest_flush', 'catalog_manifest', 'storefront_cache', 'installation_report', 'migration', 'update_check'], $report->jobs());
        self::assertFalse($built);
    }

    private static function recordModuleVersion(string $version): void
    {
        self::db()->execute(
            'UPDATE `' . _DB_PREFIX_ . 'module` SET `version` = \'' . pSQL($version) . '\' WHERE `name` = \'smailyforprestashop\''
        );
    }
}
