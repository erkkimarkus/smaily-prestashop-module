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

namespace PrestaShop\Module\SmailyForPrestaShop\Admin\Setup;

if (!defined('_PS_VERSION_')) {
    exit;
}

use PrestaShop\Module\SmailyForPrestaShop\Admin\Api\AbstractJsonAction;
use PrestaShop\Module\SmailyForPrestaShop\Admin\BaseController;
use PrestaShop\Module\SmailyForPrestaShop\Admin\Settings\LayoutChange;
use PrestaShop\Module\SmailyForPrestaShop\Admin\Settings\SettingsPanels;
use PrestaShop\Module\SmailyForPrestaShop\Admin\Settings\ShopOverrides;
use PrestaShop\Module\SmailyForPrestaShop\Admin\UserGuide;
use PrestaShop\Module\SmailyForPrestaShop\Config\TickSettings;
use PrestaShop\Module\SmailyForPrestaShop\Contacts\Import\ContactImport;
use PrestaShop\Module\SmailyForPrestaShop\Runtime\Services;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The Initial setup page: the shell renders its frame (layout/setup.html.twig), and this
 * controller passes the content of its steps (views/templates/admin/setup/) as `smaily_setup`, read
 * by the panels for the scope of PrestaShop's shop selector (SetupScope).
 *
 * Like Magento, the page opens on Connect with every step reached so far open in the rail
 * (SetupProgress); `?step=n` opens a step already reached, which the setup uses to show the
 * Overview after Finish. A finished setup opens step 1 on a summary of the connection with "Edit
 * credentials", and in one store of several shops steps 1 to 3 list the shops that hold their own
 * values (ShopsWithOwnValues), as Magento's wizard does. The invitation shows above step 1 while
 * the scope has no Smaily account. No password reaches the page: the panels say only whether one
 * is stored.
 */
final class SetupController extends BaseController
{
    private const TEMPLATE = '@Modules/smailyforprestashop/views/templates/admin/setup/index.html.twig';

    public function index(Request $request): Response
    {
        if (!$this->opens('setup')) {
            // The shell refuses the page; nothing of the setup is read for it.
            return $this->renderPage('setup');
        }

        $services = Services::instance();
        $panels = new SetupPanels($services, self::translator());
        $selected = AbstractJsonAction::shopId();
        $scope = $panels->scope($selected);
        $target = $scope->targetShop();
        $reached = $panels->progress()->reachedStep($scope);
        $step = max(1, min($reached, (int) $request->query->get('step', 1)));

        $connection = $panels->connection()->values($scope);
        $contacts = $panels->contacts()->values($scope);
        /** @var ContactImport $import */
        $import = $services->get(ContactImport::class);
        $progress = $target === null ? null : $import->progress($target);
        $intelligence = $panels->intelligence()->values($scope);
        $settings = new SettingsPanels($services, self::translator());
        $own = $settings->shopsWithOwnValues();
        $promoted = LayoutChange::promotedShop($selected);

        return $this->renderPage('setup', [
            'smaily_setup_step' => $step,
            'smaily_setup_reached' => $reached,
            'smaily_invitation' => $connection !== null && $connection['subdomain'] === '',
            'smaily_setup' => [
                'shape' => $panels->shape()->values($scope),
                'shop_name' => $scope->separateStores() && $target !== null ? (string) self::shopName($target) : '',
                // The bar's Change is Settings' change of the layout (Api\Settings\ChangeLayout), and
                // the first answer "Separate stores" is the change from one store (Api\Setup\SaveStep).
                'promoted_shop' => (string) self::shopName($promoted),
                'connection_notes' => $scope->multishop() ? $settings->connectionNotes($scope->separateStores(), $promoted) : [],
                // A finished setup opens on the connection's summary (Magento's isWebsiteSetUp()).
                'completed' => $panels->progress()->isComplete() && $connection !== null
                    && (!$scope->separateStores() || $connection['subdomain'] !== ''),
                'own_accounts' => $own->ownAccounts($scope),
                'own_values' => [
                    'connection' => $own->of($scope, ShopOverrides::CONNECTION),
                    'contacts' => $own->of($scope, ShopOverrides::CONTACTS),
                    'automations' => $own->of($scope, ShopOverrides::AUTOMATIONS),
                ],
                'connection' => $connection,
                'languages' => $scope->languages(),
                'language_modes' => $scope->languageModes(),
                'account_per_language_elsewhere' => $scope->multilingual() && !$scope->offersAccountPerLanguage(),
                'contacts' => $contacts,
                'automations' => $panels->automations()->values($scope),
                'all_shops' => $scope->multishop() && !$scope->separateStores() ? $scope->shopNames() : [],
                'import' => $progress,
                'estimate' => $target === null ? [] : $import->estimate($target),
                'intelligence' => $intelligence,
                'overview' => $connection === null || $contacts === null ? null : [
                    'intelligence' => $intelligence,
                    'accounts' => $this->accounts($connection, $scope),
                    'shape' => $scope->multishop() ? ($scope->separateStores() ? 'separate' : 'one') : null,
                    'language_mode' => $scope->multilingual() ? $connection['language_mode'] : null,
                    'contacts' => $contacts,
                    'import' => $progress,
                    'last_tick' => $this->lastTick($services),
                ],
                'rss_feed' => $settings->rss()->values()['base_url'],
                'user_guide' => UserGuide::URL,
            ],
        ], self::TEMPLATE);
    }

    /**
     * The scope's Smaily accounts for the Overview: in mode `a` one per language that has one, the
     * fallback marked; else the one account. No password.
     *
     * @param array{subdomain: string, language_mode: string, fallback_language: string, accounts: array<string, array{subdomain: string}>} $connection
     *
     * @return list<array{language: string, name: string, subdomain: string, fallback: bool}>
     */
    private function accounts(array $connection, SetupScope $scope): array
    {
        if ($connection['language_mode'] === 'a') {
            $accounts = [];
            foreach ($scope->languages() as $iso => $name) {
                if ($connection['accounts'][$iso]['subdomain'] !== '') {
                    $accounts[] = [
                        'language' => $iso,
                        'name' => $name,
                        'subdomain' => $connection['accounts'][$iso]['subdomain'],
                        'fallback' => $iso === $connection['fallback_language'],
                    ];
                }
            }

            return $accounts;
        }
        if ($connection['subdomain'] === '') {
            return [];
        }

        return [['language' => '', 'name' => '', 'subdomain' => $connection['subdomain'], 'fallback' => false]];
    }

    /**
     * When the background tasks last ran, in the shop's time zone, or null before the first run.
     * (Tools::displayDate() takes other arguments on PrestaShop 8 and 9.)
     */
    private function lastTick(Services $services): ?string
    {
        /** @var TickSettings $tick */
        $tick = $services->get(TickSettings::class);
        $at = $tick->state()->lastTickAt();

        return $at === null ? null : date('Y-m-d H:i', $at);
    }
}
