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
use PrestaShop\Module\SmailyForPrestaShop\Admin\Setup\ImportDetails;
use PrestaShop\Module\SmailyForPrestaShop\Admin\UserGuide;
use PrestaShop\Module\SmailyForPrestaShop\Config\AdminSettings;
use PrestaShop\Module\SmailyForPrestaShop\Contacts\Import\ContactImport;
use PrestaShop\Module\SmailyForPrestaShop\Runtime\Services;
use PrestaShop\Module\SmailyForPrestaShop\Scope\AccountResolver;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The Settings page. The shell renders its frame (layout/settings.html.twig); this controller
 * passes the content of the tabs Connection, Contacts, Automations, Intelligence and RSS
 * (views/templates/admin/settings/) as `smaily_settings`, read by the Initial setup's panels for
 * the shop of PrestaShop's shop selector (SettingsScope), and the store-layout bar with its Change
 * on a multishop store, which says what the change does with the Campaign Intelligence
 * connections.
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
        if (!$this->opens('settings')) {
            // The shell refuses the page, or sends it to the Initial setup; no setting is read for it.
            return $this->renderPage('settings');
        }

        $services = Services::instance();
        $panels = new SettingsPanels($services, self::translator());
        $setup = $panels->setup();
        $selected = AbstractJsonAction::shopId();
        $context = $panels->context($selected);
        $overrides = $panels->overrides();

        $ownShop = $context->ownShop();
        $shared = $context->shared();
        $connectionScope = $context->connectionScope($overrides);
        $automationsScope = $context->automationsScope($overrides, $ownShop !== null && $overrides->hasOwn(ShopOverrides::AUTOMATIONS, $ownShop));
        $own = $ownShop === null ? null : [
            'connection' => $connectionScope !== $shared,
            'contacts' => $overrides->hasOwn(ShopOverrides::CONTACTS, $ownShop),
            'automations' => $automationsScope !== $shared,
            'automations_required' => $overrides->keepsOwnAutomations($ownShop),
        ];
        $connection = $setup->connection()->values($connectionScope);
        $contacts = $setup->contacts()->values($context->scope($own['contacts'] ?? false));
        $target = $shared->targetShop();
        /** @var ContactImport $import */
        $import = $services->get(ContactImport::class);
        $intelligence = $setup->intelligence()->values($shared);
        $account = $services->get(AccountResolver::class)->for($selected, '');
        $promoted = LayoutChange::promotedShop($selected);

        return $this->renderPage('settings', [
            'smaily_settings' => [
                'shape' => $setup->shape()->values($shared),
                'shop_name' => $shared->separateStores() && $target !== null ? (string) self::shopName($target) : '',
                // Whose values all shops use after a change to one store: the shop the merchant looks at.
                'promoted_shop' => (string) self::shopName($promoted),
                'connection_notes' => $shared->multishop() ? $panels->connectionNotes($shared->separateStores(), $promoted) : [],
                'own' => $own,
                'invitation' => $connection !== null && $connection['subdomain'] === '',
                'intelligence_invitation' => AdminSettings::invitesToIntelligence($intelligence['connected'] ?? null, $account !== null && $account->hasCredentials())
                    && self::pendingUpgrade() === null,
                'connection' => $connection,
                'languages' => $connectionScope->languages(),
                'language_modes' => $context->languageModes(),
                'account_per_language_elsewhere' => $context->accountPerLanguageElsewhere(),
                'contacts' => $contacts,
                'import' => $target === null ? null : ImportDetails::contacts($import->progress($target), self::translator()),
                'estimate' => $target === null ? [] : $import->estimate($target),
                // One store with several shops: the import, and its estimate, take every shop.
                'all_shops' => $shared->multishop() && !$shared->separateStores() ? $shared->shopNames() : [],
                'automations' => $setup->automations()->values($automationsScope),
                'automations_languages' => $automationsScope->languages(),
                'pick_workflows' => $ownShop !== null && $overrides->needsOwnWorkflows($ownShop),
                'intelligence' => $intelligence,
                // One store with several shops: the tab says that one connection serves them all.
                'intelligence_shops' => $shared->multishop() && !$shared->separateStores() ? $shared->shopNames() : [],
                // The guide's section on placing the recommendations block.
                'intelligence_guide' => UserGuide::link(UserGuide::SECTION_STOREFRONT_RECOMMENDATIONS),
                'rss' => $panels->rss()->values(),
            ],
        ], self::TEMPLATE);
    }
}
