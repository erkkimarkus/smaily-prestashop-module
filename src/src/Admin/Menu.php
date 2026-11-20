<?php
/**
 * 2024 Smaily
 *
 * NOTICE OF LICENSE
 *
 * Smaily for PrestaShop is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * any later version.
 *
 * Smaily for PrestaShop is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with Smaily for PrestaShop. If not, see <http://www.gnu.org/licenses/>.
 *
 * @author    Smaily <info@smaily.com>
 * @copyright 2024 Smaily
 * @license   GPL3
 */
declare(strict_types=1);

namespace PrestaShop\Module\SmailyForPrestaShop\Admin;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * The admin menu that the main class's $tabs declares: "Smaily Connect" under Customers with its
 * four pages.
 *
 * PrestaShop installs $tabs on a fresh install, and removes every tab of the module on uninstall.
 * When it installs the first child of a tab that has none yet, it copies the parent to
 * "<class>_MTR", makes the copy the parent and the original its first child: the menu would show
 * "Smaily Connect" again beside Dashboard. install() removes that copy, and adds the tabs that are
 * missing, which an upgrade needs: PrestaShop installs a module's tabs on install only.
 */
final class Menu
{
    public const MODULE = 'smailyforprestashop';

    /** The suffix that PrestaShop gives the copy of a parent tab. */
    private const COPY = '_MTR';

    /**
     * Brings the menu to what $tabs declares (parents before their children). Idempotent.
     *
     * @param list<array<string, mixed>> $tabs
     */
    public static function install(array $tabs): bool
    {
        $done = true;
        foreach ($tabs as $definition) {
            $done = self::removeCopy((string) $definition['class_name']) && $done;
        }
        foreach ($tabs as $definition) {
            if (!self::id((string) $definition['class_name'])) {
                $done = self::add($definition) && $done;
            }
        }
        \Tab::resetStaticCache();

        return $done;
    }

    /**
     * Where PrestaShop made "<class>_MTR" the parent and moved <class> under it, deletes <class>
     * and gives the copy the class name and the permissions' names back.
     */
    private static function removeCopy(string $className): bool
    {
        $copyId = self::id($className . self::COPY);
        if (!$copyId) {
            return true;
        }
        $originalId = self::id($className);
        if ($originalId && !(new \Tab($originalId))->delete()) {
            return false;
        }

        $copy = new \Tab($copyId);
        $copy->class_name = $className;
        if (!$copy->update()) {
            return false;
        }
        $slug = 'ROLE_MOD_TAB_' . strtoupper($className);
        foreach (['CREATE', 'READ', 'UPDATE', 'DELETE'] as $action) {
            if (!\Db::getInstance()->execute(
                'UPDATE `' . _DB_PREFIX_ . 'authorization_role` SET `slug` = \'' . pSQL($slug . '_' . $action) . '\''
                . ' WHERE `slug` = \'' . pSQL($slug . strtoupper(self::COPY) . '_' . $action) . '\''
            )) {
                return false;
            }
        }
        \Tab::resetStaticCache();

        return true;
    }

    /**
     * @param array<string, mixed> $definition
     */
    private static function add(array $definition): bool
    {
        $parentId = self::id((string) $definition['parent_class_name']);
        if (!$parentId) {
            return false;
        }

        $tab = new \Tab();
        $tab->active = true;
        $tab->enabled = true;
        $tab->class_name = (string) $definition['class_name'];
        $tab->route_name = (string) ($definition['route_name'] ?? '');
        $tab->module = self::MODULE;
        $tab->id_parent = $parentId;
        $tab->wording = $definition['wording'] ?? null;
        $tab->wording_domain = $definition['wording_domain'] ?? null;
        $tab->name = [];
        foreach (\Language::getLanguages(false) as $language) {
            $tab->name[(int) $language['id_lang']] = (string) $definition['name'];
        }

        return (bool) $tab->add();
    }

    private static function id(string $className): int
    {
        return (int) \Db::getInstance()->getValue(
            'SELECT `id_tab` FROM `' . _DB_PREFIX_ . 'tab` WHERE `class_name` = \'' . pSQL($className) . '\'',
            false
        );
    }
}
