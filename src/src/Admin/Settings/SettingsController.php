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

namespace PrestaShop\Module\SmailyForPrestaShop\Admin\Settings;

if (!defined('_PS_VERSION_')) {
    exit;
}

use PrestaShop\Module\SmailyForPrestaShop\Admin\Api\AbstractJsonAction;
use PrestaShop\Module\SmailyForPrestaShop\Admin\BaseController;
use PrestaShop\Module\SmailyForPrestaShop\Contacts\Import\ContactImport;
use PrestaShop\Module\SmailyForPrestaShop\Runtime\Services;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The Settings page. The shell renders its frame (layout/settings.html.twig); this controller
 * passes the content of the tabs Connection, Contacts and RSS (views/templates/admin/settings/) as
 * `smaily_settings`, read by the Initial setup's panels for the shop of PrestaShop's shop selector
 * (SettingsScope), and the store-layout bar with its Change on a multishop store.
 *
 * No password reaches the page: the panels say only whether one is stored. The connection's state
 * is asked of Smaily after the page has loaded (views/js/admin/settings.js), so a slow Smaily never
 * holds the page.
 */
final class SettingsController extends BaseController
{
    private const TEMPLATE = '@Modules/smailyforprestashop/views/templates/admin/settings/index.html.twig';

    public function index(Request $request): Response
    {
        $services = Services::instance();
        $panels = new SettingsPanels($services, self::translator());
        $setup = $panels->setup();
        $selected = AbstractJsonAction::shopId();
        $context = $panels->context($selected);
        $overrides = $panels->overrides();

        $ownShop = $context->ownShop();
        $own = $ownShop === null ? null : [
            'connection' => $overrides->hasOwn(ShopOverrides::CONNECTION, $ownShop),
            'contacts' => $overrides->hasOwn(ShopOverrides::CONTACTS, $ownShop),
        ];
        $shared = $context->shared();
        $connectionScope = $context->scope($own['connection'] ?? false);
        $connection = $setup->connection()->values($connectionScope);
        $contacts = $setup->contacts()->values($context->scope($own['contacts'] ?? false));
        $target = $shared->targetShop();
        /** @var ContactImport $import */
        $import = $services->get(ContactImport::class);

        return $this->renderPage('settings', [
            'smaily_settings' => [
                'shape' => $setup->shape()->values($shared),
                'shop_name' => $shared->separateStores() && $target !== null ? self::shopName($target) : '',
                // Whose values all shops use after a change to one store: the shop the merchant looks at.
                'promoted_shop' => self::shopName($selected > 0 ? $selected : (int) \Configuration::get('PS_SHOP_DEFAULT')),
                'own' => $own,
                'invitation' => $connection !== null && $connection['subdomain'] === '',
                'connection' => $connection,
                'languages' => $connectionScope->languages(),
                'language_modes' => $ownShop === null ? $connectionScope->languageModes() : $context->scope(true)->languageModes(),
                'account_per_language_elsewhere' => $ownShop === null && $shared->multilingual() && !$shared->offersAccountPerLanguage(),
                'contacts' => $contacts,
                'import' => $target === null ? null : $import->progress($target),
                'rss' => $panels->rss()->values(),
            ],
        ], self::TEMPLATE);
    }

    private static function shopName(int $idShop): string
    {
        $shop = \Shop::getShop($idShop);

        return is_array($shop) ? (string) $shop['name'] : '';
    }
}
