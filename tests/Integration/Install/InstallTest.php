<?php
declare(strict_types=1);

namespace Tests\Integration\Install;

use PrestaShop\Module\SmailyForPrestaShop\Install\HookList;
use PrestaShop\Module\SmailyForPrestaShop\Install\Schema;
use Tests\Integration\IntegrationTestCase;

/**
 * A fresh install leaves every table, every hook and the current schema version in the shop.
 */
final class InstallTest extends IntegrationTestCase
{
    public function testInstallsVersion300(): void
    {
        self::assertSame('3.0.0', self::module()->version);
        self::assertSame(
            '3.0.0',
            self::db()->getValue('SELECT `version` FROM `' . _DB_PREFIX_ . 'module` WHERE `name` = \'smailyforprestashop\'')
        );
    }

    public function testCreatesEveryTable(): void
    {
        foreach (Schema::tables() as $table) {
            self::assertNotEmpty(
                self::db()->executeS('SHOW TABLES LIKE \'' . _DB_PREFIX_ . $table . '\''),
                $table . ' exists'
            );
        }
    }

    public function testRegistersEveryHook(): void
    {
        $rows = self::db()->executeS(
            'SELECT DISTINCT h.`name` FROM `' . _DB_PREFIX_ . 'hook_module` hm'
            . ' JOIN `' . _DB_PREFIX_ . 'hook` h ON h.`id_hook` = hm.`id_hook`'
            . ' WHERE hm.`id_module` = ' . (int) self::module()->id
        );
        $registered = array_map('strtolower', array_column($rows, 'name'));

        $missing = array_diff(array_map('strtolower', HookList::HOOKS), $registered);
        self::assertSame([], array_values($missing), 'hooks not registered');
        self::assertCount(count(HookList::HOOKS), $registered);
    }

    public function testStoresTheCurrentSchemaVersion(): void
    {
        self::assertSame(Schema::VERSION, \Configuration::getGlobalValue(Schema::VERSION_KEY));
        self::assertTrue(Schema::isCurrent());
    }
}
