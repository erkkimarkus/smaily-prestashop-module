<?php
declare(strict_types=1);

namespace Tests\Integration\Migration;

use PrestaShop\Module\SmailyForPrestaShop\Config\TickSettings;
use PrestaShop\Module\SmailyForPrestaShop\Install\Schema;
use PrestaShop\Module\SmailyForPrestaShop\Migration\LegacyState;
use PrestaShop\Module\SmailyForPrestaShop\Migration\Upgrade300;
use PrestaShop\Module\SmailyForPrestaShop\Runtime\Services;
use PrestaShop\Module\SmailyForPrestaShop\Scheduler\Door;
use PrestaShop\Module\SmailyForPrestaShop\Scheduler\Tick;
use PrestaShop\Module\SmailyForPrestaShop\Scheduler\TickReport;
use Tests\Integration\IntegrationTestCase;

require_once __DIR__ . '/LegacyStore.php';

/**
 * A migration that stopped, or never started because the files were copied by FTP, completes from
 * the tick. Once the schema is current, the tick's migration job does no migration work.
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

    public function testOnceTheSchemaIsCurrentTheMigrationJobDoesNoMigrationWork(): void
    {
        LegacyStore::restore();
        $services = Services::fromProviders(Services::PROVIDERS);
        $built = false;
        $services->set(Upgrade300::class, function () use (&$built): Upgrade300 {
            $built = true;
            throw new \LogicException('The migration was started.');
        });

        $report = $services->get(Tick::class)->run('tick', 50);

        self::assertSame(TickReport::RAN, $report->status());
        self::assertSame(['queue_flush', 'queue_janitor', 'migration'], $report->jobs());
        self::assertFalse($built);
    }

    private static function recordModuleVersion(string $version): void
    {
        self::db()->execute(
            'UPDATE `' . _DB_PREFIX_ . 'module` SET `version` = \'' . pSQL($version) . '\' WHERE `name` = \'smailyforprestashop\''
        );
    }
}
