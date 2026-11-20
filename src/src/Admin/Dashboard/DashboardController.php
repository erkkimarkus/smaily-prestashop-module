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

namespace PrestaShop\Module\SmailyForPrestaShop\Admin\Dashboard;

if (!defined('_PS_VERSION_')) {
    exit;
}

use PrestaShop\Module\SmailyForPrestaShop\Admin\Api\AbstractJsonAction;
use PrestaShop\Module\SmailyForPrestaShop\Admin\BaseController;
use PrestaShop\Module\SmailyForPrestaShop\Config\ContactsSettings;
use PrestaShop\Module\SmailyForPrestaShop\Config\TickSettings;
use PrestaShop\Module\SmailyForPrestaShop\Runtime\Services;
use PrestaShop\Module\SmailyForPrestaShop\Scope\AccountResolver;
use PrestaShop\Module\SmailyForPrestaShop\Scope\StoreLayout;
use PrestaShop\Module\SmailyForPrestaShop\Smaily\AccountStore;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The Dashboard page. The shell renders its frame (layout/dashboard.html.twig); this controller reads
 * the facts of the shop in PrestaShop's shop selector (DashboardFacts), words them (DashboardView)
 * and hands them to the content templates in views/templates/admin/dashboard/ as
 * `smaily_dashboard`. The page writes nothing and calls no outside service.
 */
final class DashboardController extends BaseController
{
    public function index(Request $request): Response
    {
        if (!$this->opens('dashboard')) {
            // The shell refuses the page, or sends it to the Initial setup.
            return $this->renderPage('dashboard');
        }

        $services = Services::instance();
        $facts = new DashboardFacts(
            new DashboardQueries(),
            $services->get(ContactsSettings::class),
            $services->get(StoreLayout::class),
            $services->get(AccountResolver::class),
            $services->get(AccountStore::class),
            $services->get(TickSettings::class)
        );
        $settingsTab = self::PAGES['settings']['tab'];
        $translator = self::translator();
        $view = (new DashboardView(fn (string $id, array $parameters = []): string => $translator->trans($id, $parameters, self::DOMAIN)))->build($facts->collect(
            self::pendingUpgrade()['status'] ?? null,
            AbstractJsonAction::shopId(),
            \Shop::isFeatureActive(),
            $this->isGranted('update', $settingsTab),
            $this->isGranted('read', $settingsTab),
            time()
        ));

        return $this->renderPage('dashboard', [
            'smaily_dashboard' => $view,
            'smaily_invitation' => $view['invitation'],
        ]);
    }
}
