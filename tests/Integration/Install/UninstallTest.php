<?php
declare(strict_types=1);

namespace Tests\Integration\Install;

use PrestaShop\Module\SmailyForPrestaShop\Install\HookList;
use PrestaShop\Module\SmailyForPrestaShop\Install\Schema;
use PrestaShop\Module\SmailyForPrestaShop\Install\Uninstaller;
use PrestaShop\Module\SmailyForPrestaShop\Migration\LegacyOverride;
use PrestaShop\Module\SmailyForPrestaShop\Runtime\Services;
use Tests\Integration\IntegrationTestCase;
use Tests\Integration\Migration\LegacyStore;

require_once __DIR__ . '/../Migration/LegacyStore.php';

/**
 * Uninstall removes all module data: every key and table of the module, the legacy ones included,
 * after the listeners that must run first. A fresh install works afterwards.
 */
final class UninstallTest extends IntegrationTestCase
{
    protected function tearDown(): void
    {
        if (defined('_PS_ROOT_DIR_')) {
            if (self::moduleId() === 0) {
                self::console('install');
            }
            LegacyStore::restore();
            if (is_file(self::overrideFile())) {
                unlink(self::overrideFile());
            }
        }
        parent::tearDown();
    }

    public function testRemovesEveryKeyAndTableAndAFreshInstallWorksAfterwards(): void
    {
        LegacyStore::loadFixture('2.2.0-configured.sql');
        \Configuration::updateGlobalValue('SMAILY_CONNECT_SETUP_STATE', 'upgraded');
        \Configuration::updateValue('SMAILY_CONNECT_CONTACTS_ENABLED', '1', false, 1, 1);

        self::assertTrue(self::module()->uninstall());

        $left = self::db()->executeS(
            'SELECT DISTINCT `name` FROM `' . _DB_PREFIX_ . 'configuration` WHERE `name` LIKE \'SMAILY\\_%\''
        );
        self::assertSame([], $left);
        foreach (array_merge(Schema::tables(), ['smaily_cart']) as $table) {
            self::assertSame([], self::db()->executeS('SHOW TABLES LIKE \'' . _DB_PREFIX_ . str_replace('_', '\\_', $table) . '\''), $table);
        }

        // The module manager needs the Symfony kernel, which a test process does not boot.
        self::assertSame(0, self::console('install'));
        \Configuration::resetStaticCache();
        \Configuration::loadConfiguration();
        self::assertTrue(Schema::isCurrent());
        foreach (Schema::tables() as $table) {
            self::assertNotEmpty(self::db()->executeS('SHOW TABLES LIKE \'' . _DB_PREFIX_ . str_replace('_', '\\_', $table) . '\''), $table);
        }
        self::assertSame(count(HookList::HOOKS), (int) self::db()->getValue(
            'SELECT COUNT(DISTINCT `id_hook`) FROM `' . _DB_PREFIX_ . 'hook_module` WHERE `id_module` = ' . self::moduleId(),
            false
        ));
    }

    private static function overrideFile(): string
    {
        return _PS_OVERRIDE_DIR_ . LegacyOverride::RELATIVE_PATH;
    }

    private static function moduleId(): int
    {
        return (int) self::db()->getValue(
            'SELECT `id_module` FROM `' . _DB_PREFIX_ . 'module` WHERE `name` = \'smailyforprestashop\'',
            false
        );
    }

    private static function console(string $action): int
    {
        exec('php ' . _PS_ROOT_DIR_ . '/bin/console prestashop:module ' . $action . ' smailyforprestashop 2>&1', $output, $code);

        return $code;
    }

    public function testRunsTheListenersBeforeTheDataGoesAndOneFailureStopsNothing(): void
    {
        $seen = [];
        $services = Services::fromProviders([]);
        $services->listen(Uninstaller::BEFORE_REMOVAL, static function () use (&$seen): void {
            $seen[] = Schema::isCurrent() ? 'schema present' : 'schema gone';
            throw new \RuntimeException('engine unreachable');
        });
        $services->listen(Uninstaller::BEFORE_REMOVAL, static function () use (&$seen): void {
            $seen[] = 'second';
        });

        self::assertTrue((new Uninstaller($services, new LegacyOverride(_PS_OVERRIDE_DIR_)))->uninstall());

        self::assertSame(['schema present', 'second'], $seen);
        self::assertFalse(Schema::isCurrent());
    }

    public function testRemovesOnlyTheOverrideThat2xShipped(): void
    {
        if (!is_dir(dirname(self::overrideFile()))) {
            mkdir(dirname(self::overrideFile()), 0755, true);
        }
        file_put_contents(self::overrideFile(), "<?php\n// another module's override\n");
        $uninstaller = new Uninstaller(Services::fromProviders([]), new LegacyOverride(_PS_OVERRIDE_DIR_));

        $uninstaller->uninstall();
        self::assertFileExists(self::overrideFile());

        copy(__DIR__ . '/../../fixtures/legacy/override-2.x.ps_emailsubscription.php.txt', self::overrideFile());
        $uninstaller->uninstall();
        self::assertFileDoesNotExist(self::overrideFile());
    }
}
