<?php
declare(strict_types=1);

namespace Tests\Integration\Install;

use PrestaShop\Module\SmailyForPrestaShop\Install\HookList;
use Tests\Integration\IntegrationTestCase;
use Tests\Integration\Migration\LegacyStore;

require_once __DIR__ . '/../Migration/LegacyStore.php';

/**
 * A command-line install in a store of two shops enables the module, with its hooks, in both
 * shops: the console has no shop selection, and PrestaShop enables a module in the shops of the
 * context it installs in.
 */
final class ConsoleInstallTest extends IntegrationTestCase
{
    protected function tearDown(): void
    {
        if (defined('_PS_ROOT_DIR_')) {
            self::console('uninstall');
            LegacyStore::removeSecondShop();
            self::console('install');
            // The install gave the module a new id: the tests after this one load it, and the
            // hooks' module lists, again.
            \Module::resetStaticCache();
            \Cache::clean('*');
            LegacyStore::restore();
        }
        parent::tearDown();
    }

    public function testEnablesTheModuleInEveryShop(): void
    {
        LegacyStore::loadFixture('2.2.0-multistore-shared.sql');
        self::assertSame(0, self::console('uninstall'));

        self::assertSame(0, self::console('install'));

        $id = (int) self::db()->getValue(
            'SELECT `id_module` FROM `' . _DB_PREFIX_ . 'module` WHERE `name` = \'smailyforprestashop\'',
            false
        );
        $shops = array_map('intval', array_column(self::db()->executeS(
            'SELECT `id_shop` FROM `' . _DB_PREFIX_ . 'module_shop` WHERE `id_module` = ' . $id . ' ORDER BY `id_shop`',
            true,
            false
        ) ?: [], 'id_shop'));
        self::assertSame([1, 2], $shops);
        foreach ([1, 2] as $shop) {
            self::assertSame(count(HookList::HOOKS), (int) self::db()->getValue(
                'SELECT COUNT(DISTINCT `id_hook`) FROM `' . _DB_PREFIX_ . 'hook_module`'
                . ' WHERE `id_module` = ' . $id . ' AND `id_shop` = ' . $shop,
                false
            ), 'hooks of shop ' . $shop);
        }
    }

    private static function console(string $action): int
    {
        exec('php ' . _PS_ROOT_DIR_ . '/bin/console prestashop:module ' . $action . ' smailyforprestashop 2>&1', $output, $code);

        return $code;
    }
}
