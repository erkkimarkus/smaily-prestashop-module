<?php
declare(strict_types=1);

namespace Tests\Integration\Admin;

use PrestaShop\Module\SmailyForPrestaShop\Admin\Menu;
use Tests\Integration\IntegrationTestCase;

/**
 * The admin menu is "Smaily Connect" under Customers with the four pages, whether PrestaShop
 * installed it (with its copy of the parent tab) or the upgrade added it.
 */
final class MenuTest extends IntegrationTestCase
{
    private const EXPECTED = [
        ['AdminSmailyConnect', 'AdminParentCustomer', 'smailyforprestashop_index', 'Smaily Connect'],
        ['AdminSmailyConnectDashboard', 'AdminSmailyConnect', 'smailyforprestashop_dashboard', 'Dashboard'],
        ['AdminSmailyConnectSetup', 'AdminSmailyConnect', 'smailyforprestashop_setup', 'Initial setup'],
        ['AdminSmailyConnectSettings', 'AdminSmailyConnect', 'smailyforprestashop_settings', 'Settings'],
        ['AdminSmailyConnectLog', 'AdminSmailyConnect', 'smailyforprestashop_log', 'Log'],
    ];

    protected function tearDown(): void
    {
        if (defined('_PS_ROOT_DIR_')) {
            self::removeMenu();
            Menu::install(self::module()->getTabs());
        }
        parent::tearDown();
    }

    public function testAMissingMenuIsAddedUnderCustomers(): void
    {
        self::removeMenu();

        self::assertTrue(Menu::install(self::module()->getTabs()));

        self::assertSame(self::EXPECTED, self::menu());
        self::assertSame(['ROLE_MOD_TAB_ADMINSMAILYCONNECT_CREATE', 'ROLE_MOD_TAB_ADMINSMAILYCONNECT_DELETE', 'ROLE_MOD_TAB_ADMINSMAILYCONNECT_READ', 'ROLE_MOD_TAB_ADMINSMAILYCONNECT_UPDATE'], self::roles('ROLE\\_MOD\\_TAB\\_ADMINSMAILYCONNECT\\_%'));
    }

    public function testTheCopyOfTheParentThatPrestaShopMakesIsRemoved(): void
    {
        self::removeMenu();
        // As PrestaShop's tab register leaves it: the copy "_MTR" is the parent, the original its first child.
        $copy = self::addTab('AdminSmailyConnect_MTR', (int) \Tab::getIdFromClassName('AdminParentCustomer'), 'smailyforprestashop_index', 'Smaily Connect');
        self::addTab('AdminSmailyConnect', $copy, 'smailyforprestashop_index', 'Smaily Connect');
        foreach (array_slice(self::EXPECTED, 1) as [$className, , $route, $name]) {
            self::addTab($className, $copy, $route, $name);
        }

        self::assertTrue(Menu::install(self::module()->getTabs()));

        self::assertSame(self::EXPECTED, self::menu());
        self::assertSame((string) $copy, (string) \Tab::getIdFromClassName('AdminSmailyConnect'), 'the copy keeps its id and its children');
        self::assertSame([], self::roles('ROLE\\_MOD\\_TAB\\_ADMINSMAILYCONNECT\\_MTR\\_%'));
        self::assertCount(4, self::roles('ROLE\\_MOD\\_TAB\\_ADMINSMAILYCONNECT\\_%'));
    }

    public function testASecondInstallChangesNothing(): void
    {
        Menu::install(self::module()->getTabs());
        $before = self::db()->executeS('SELECT * FROM `' . _DB_PREFIX_ . 'tab` WHERE `module` = \'smailyforprestashop\' ORDER BY `id_tab`');

        self::assertTrue(Menu::install(self::module()->getTabs()));

        self::assertSame($before, self::db()->executeS('SELECT * FROM `' . _DB_PREFIX_ . 'tab` WHERE `module` = \'smailyforprestashop\' ORDER BY `id_tab`'));
    }

    public function testAPageGivesTheTabsTheirWordingInTheEmployeesLanguage(): void
    {
        Menu::install(self::module()->getTabs());
        $idLang = (int) \Configuration::get('PS_LANG_DEFAULT');
        $asked = [];
        $estonian = static function (string $wording, string $domain) use (&$asked): string {
            $asked[] = $domain;

            return ['Dashboard' => 'Töölaud', 'Initial setup' => 'Algseadistus', 'Settings' => 'Seaded', 'Log' => 'Logi'][$wording] ?? $wording;
        };

        Menu::name(self::module()->getTabs(), $idLang, $estonian);

        self::assertSame(['Smaily Connect', 'Töölaud', 'Algseadistus', 'Seaded', 'Logi'], array_column(self::menu(), 3));
        self::assertSame(['Modules.Smailyforprestashop.Admin'], array_values(array_unique($asked)));

        Menu::name(self::module()->getTabs(), $idLang, static fn (string $wording): string => $wording);
        self::assertSame(array_column(self::EXPECTED, 3), array_column(self::menu(), 3), 'and back, for an employee who reads English');

        Menu::name(self::module()->getTabs(), $idLang, static fn (): string => '');
        self::assertSame(array_column(self::EXPECTED, 3), array_column(self::menu(), 3), 'an empty translation changes nothing');
    }

    public function testTheNamesReachTheTabsThatTheRequestHadAlreadyRead(): void
    {
        Menu::install(self::module()->getTabs());
        $idLang = (int) \Configuration::get('PS_LANG_DEFAULT');
        $parent = (int) \Tab::getIdFromClassName('AdminSmailyConnect');
        // PrestaShop 8.2.8 reads the tabs before the page's controller runs, and builds the page's
        // tab bar from what it read.
        self::assertSame(['Dashboard', 'Initial setup', 'Settings', 'Log'], array_column(\Tab::getTabs($idLang, $parent), 'name'));

        Menu::name(self::module()->getTabs(), $idLang, static fn (string $wording): string => ['Dashboard' => 'Töölaud', 'Initial setup' => 'Algseadistus', 'Settings' => 'Seaded', 'Log' => 'Logi'][$wording] ?? $wording);

        self::assertSame(['Töölaud', 'Algseadistus', 'Seaded', 'Logi'], array_column(\Tab::getTabs($idLang, $parent), 'name'));
    }

    /**
     * @return list<array{string, string, string, string}> class, parent class, route and name of each module tab
     */
    public static function menu(): array
    {
        $rows = self::db()->executeS(
            'SELECT t.`class_name`, p.`class_name` AS `parent`, t.`route_name`, tl.`name` FROM `' . _DB_PREFIX_ . 'tab` t'
            . ' JOIN `' . _DB_PREFIX_ . 'tab` p ON p.`id_tab` = t.`id_parent`'
            . ' JOIN `' . _DB_PREFIX_ . 'tab_lang` tl ON tl.`id_tab` = t.`id_tab` AND tl.`id_lang` = ' . (int) \Configuration::get('PS_LANG_DEFAULT')
            . ' WHERE t.`module` = \'smailyforprestashop\' ORDER BY p.`class_name` = \'AdminParentCustomer\' DESC, t.`position`'
        ) ?: [];

        return array_map(static fn (array $row): array => [$row['class_name'], $row['parent'], $row['route_name'], $row['name']], $rows);
    }

    public static function removeMenu(): void
    {
        foreach (\Tab::getCollectionFromModule('smailyforprestashop') as $tab) {
            $tab->delete();
        }
        \Tab::resetStaticCache();
    }

    private static function addTab(string $className, int $idParent, string $route, string $name): int
    {
        $tab = new \Tab();
        $tab->class_name = $className;
        $tab->route_name = $route;
        $tab->module = 'smailyforprestashop';
        $tab->id_parent = $idParent;
        foreach (\Language::getLanguages(false) as $language) {
            $tab->name[(int) $language['id_lang']] = $name;
        }
        self::assertTrue($tab->add());

        return (int) $tab->id;
    }

    /**
     * @return list<string>
     */
    private static function roles(string $like): array
    {
        return array_column(self::db()->executeS(
            'SELECT `slug` FROM `' . _DB_PREFIX_ . 'authorization_role` WHERE `slug` LIKE \'' . $like . '\' ORDER BY `slug`'
        ) ?: [], 'slug');
    }
}
