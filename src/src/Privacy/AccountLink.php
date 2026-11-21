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

namespace PrestaShop\Module\SmailyForPrestaShop\Privacy;

if (!defined('_PS_VERSION_')) {
    exit;
}

use PrestaShop\Module\SmailyForPrestaShop\Engine\Connections;

/**
 * The link "Personalization" of the customer's account (hook `displayCustomerAccount`), to the
 * page where a shopper allows or stops personalization (front controller `personalization`).
 *
 * The link and the page exist only in a shop that has a Campaign Intelligence connection, as in
 * the Magento connector: elsewhere nothing personalizes, and the page's sentence would be untrue.
 *
 * The markup is the theme's own for an account link. The classic theme lists the account's links
 * as tiles on the account page; a theme with an account menu (PrestaShop 9's default theme,
 * Hummingbird) lists them in a menu beside every account page.
 */
final class AccountLink
{
    public const MODULE = 'smailyforprestashop';

    public const CONTROLLER = 'personalization';

    private const TEMPLATE = 'module:smailyforprestashop/views/templates/front/personalization-link.tpl';

    private Connections $connections;

    public function __construct(Connections $connections)
    {
        $this->connections = $connections;
    }

    /**
     * Whether the shop has the page.
     */
    public function available(int $idShop): bool
    {
        return $this->connections->forShop($idShop) !== null;
    }

    /**
     * The link's HTML, or null in a shop without the page.
     */
    public function render(\Context $context): ?string
    {
        $module = \Module::getInstanceByName(self::MODULE);
        if (!$module instanceof \Module || !$this->available((int) $context->shop->id)) {
            return null;
        }
        $context->smarty->assign('smaily_personalization_link', [
            'url' => self::url($context),
            'account_menu' => self::themeHasAccountMenu(),
        ]);

        return $module->fetch(self::TEMPLATE);
    }

    public static function url(\Context $context): string
    {
        return $context->link->getModuleLink(self::MODULE, self::CONTROLLER, [], true);
    }

    /**
     * Whether the shop's theme, or its parent theme, draws the account's links as a menu
     * (`components/account-menu.tpl`, as Hummingbird does) and not as the classic theme's tiles.
     */
    public static function themeHasAccountMenu(): bool
    {
        foreach ([_PS_THEME_DIR_, defined('_PS_PARENT_THEME_DIR_') ? _PS_PARENT_THEME_DIR_ : ''] as $theme) {
            if ($theme !== '' && is_file($theme . 'templates/components/account-menu.tpl')) {
                return true;
            }
        }

        return false;
    }
}
