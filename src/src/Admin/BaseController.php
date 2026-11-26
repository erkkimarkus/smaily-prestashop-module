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

use PrestaShop\Module\SmailyForPrestaShop\Admin\Api\AbstractJsonAction;
use PrestaShop\Module\SmailyForPrestaShop\Config\AdminSettings;
use PrestaShop\Module\SmailyForPrestaShop\Config\MigrationNotices;
use PrestaShop\Module\SmailyForPrestaShop\Install\Schema;
use PrestaShop\Module\SmailyForPrestaShop\Migration\Upgrade300;
use PrestaShop\Module\SmailyForPrestaShop\Runtime\Services;
use PrestaShop\Module\SmailyForPrestaShop\Update\UpdateNotice;
use PrestaShopBundle\Controller\Admin\FrameworkBundleAdminController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The base of the four admin pages: Dashboard, Initial setup, Settings and Log.
 *
 * It checks the employee's right to the page's menu tab, repairs the menu (Menu), sends every page
 * but the Initial setup there while a fresh install's setup is not finished, and renders the
 * page's frame (views/templates/admin/layout/<page>.html.twig, or a page template that extends it)
 * with the shell around it: the Smaily Connect logo, the pending-upgrade banner, the upgrade
 * notices, the notice of a newer release and the page navigation. The page's content comes from
 * the templates of each page's folder that the frame includes by name.
 *
 * FrameworkBundleAdminController works on PrestaShop 8 and 9 (deprecated since 9.0; its successor
 * exists only from 9.0). The shop is the one that PrestaShop's own shop selector set.
 */
abstract class BaseController extends FrameworkBundleAdminController
{
    use ContextTranslation;

    public const DOMAIN = 'Modules.Smailyforprestashop.Admin';

    /** The CSRF token id of the JSON endpoints (Api\AbstractJsonAction). */
    public const API_TOKEN_ID = 'smailyforprestashop_api';

    /** Each page: its route, and the menu tab whose rights open it. */
    public const PAGES = [
        'dashboard' => ['route' => 'smailyforprestashop_dashboard', 'tab' => 'AdminSmailyConnectDashboard'],
        'setup' => ['route' => 'smailyforprestashop_setup', 'tab' => 'AdminSmailyConnectSetup'],
        'settings' => ['route' => 'smailyforprestashop_settings', 'tab' => 'AdminSmailyConnectSettings'],
        'log' => ['route' => 'smailyforprestashop_log', 'tab' => 'AdminSmailyConnectLog'],
    ];

    private const TEMPLATE = '@Modules/smailyforprestashop/views/templates/admin/layout/%s.html.twig';

    /**
     * The route that the module's Configure button opens: the Initial setup while a fresh install
     * has not finished it, else the Dashboard.
     */
    public static function entryRoute(): string
    {
        return self::PAGES[self::isSetupForced() ? 'setup' : 'dashboard']['route'];
    }

    /**
     * A fresh install has stored no setup state and no migration (or the upgrade stored `new`): it
     * opens the Initial setup until it is finished. An upgraded store, or one whose upgrade is not
     * finished, is not sent there.
     */
    public static function isSetupForced(): bool
    {
        $state = (new AdminSettings())->setupState();
        if ($state !== null) {
            return $state === AdminSettings::SETUP_NEW;
        }

        return !isset(Services::instance()->get(Upgrade300::class)->state()['status']) && Schema::isCurrent();
    }

    /**
     * Whether the page opens: the employee may read it, and it is not sent to the Initial setup. A
     * controller asks before it collects what only an opened page shows; renderPage() answers
     * the request either way.
     */
    protected function opens(string $page): bool
    {
        return $this->isGranted('read', self::PAGES[$page]['tab']) && ($page === 'setup' || !self::isSetupForced());
    }

    /**
     * Renders one of the four pages: its frame, or $template when the page has a template that
     * extends the frame. $parameters reach the templates as they are.
     *
     * @param array<string, mixed> $parameters
     */
    protected function renderPage(string $page, array $parameters = [], ?string $template = null): Response
    {
        if (!$this->isGranted('read', self::PAGES[$page]['tab'])) {
            return $this->render(
                sprintf(self::TEMPLATE, 'denied'),
                ['layoutTitle' => $this->pageTitle($page)],
                new Response('', Response::HTTP_FORBIDDEN)
            );
        }
        $module = \Module::getInstanceByName(Menu::MODULE);
        // A fresh install's menu holds PrestaShop's copy of the parent tab until a page repairs it,
        // and its names are not yet in the employee's language.
        Menu::install($module->getTabs());
        Menu::name(
            $module->getTabs(),
            (int) \Context::getContext()->language->id,
            static fn (string $wording, string $domain): string => self::translator()->trans($wording, [], $domain)
        );

        if (!$this->opens($page)) {
            return $this->redirectToRoute(self::PAGES['setup']['route']);
        }

        $upgrade = self::pendingUpgrade();
        $admin = new AdminSettings();

        return $this->render($template ?? sprintf(self::TEMPLATE, $page), $parameters + [
            'layoutTitle' => $this->pageTitle($page),
            'smaily' => [
                'page' => $page,
                'entry' => self::PAGES[$page]['route'] === self::entryRoute(),
                'version' => $module->version,
                'upgrade' => $upgrade,
                // The notices tell what the finished upgrade did; until then the banner speaks.
                'notices' => $upgrade === null ? $this->notices() : [],
                'update' => self::updateNotice(),
                'token' => $this->get('security.csrf.token_manager')->getToken(self::API_TOKEN_ID)->getValue(),
                // The shop selection the page is drawn for; its requests name it again.
                'scope' => AbstractJsonAction::pageScope(),
                'invitation_dismissed' => $admin->isInvitationDismissed(time()),
                'intelligence_invitation_dismissed' => $admin->isIntelligenceInvitationDismissed(time()),
            ],
        ]);
    }

    /**
     * The migration's state while it is not finished, else null: `pending` before it ran or
     * while it runs, `failed` when its last attempt stopped.
     *
     * @return array{status: string}|null
     */
    public static function pendingUpgrade(): ?array
    {
        if (Schema::isCurrent()) {
            return null;
        }
        $state = Services::instance()->get(Upgrade300::class)->state();

        return ['status' => ($state['status'] ?? '') === 'failed' ? 'failed' : 'pending'];
    }

    /**
     * The newer release to tell the merchant about, or null. The pending-upgrade banner has
     * priority: no release shows while the migration is not finished.
     *
     * @return array{version: string, download_url: string, release_url: string}|null
     */
    public static function updateNotice(): ?array
    {
        if (self::pendingUpgrade() !== null) {
            return null;
        }

        return Services::instance()->get(UpdateNotice::class)->current();
    }

    /**
     * The translator of every admin page and JSON endpoint: PrestaShop's own, from the context. On
     * PrestaShop 8.2.8 a controller's container is a service locator that holds no "translator"
     * (facts file 6.6), so no controller asks its container for one.
     */
    public static function translator(): TranslatorInterface
    {
        return \Context::getContext()->getTranslator();
    }

    /**
     * The name of a shop, or null when the store has no such shop.
     */
    public static function shopName(int $idShop): ?string
    {
        $shop = \Shop::getShop($idShop);

        return is_array($shop) ? (string) $shop['name'] : null;
    }

    private function pageTitle(string $page): string
    {
        switch ($page) {
            case 'setup':
                return $this->trans('Smaily Connect — Initial setup', self::DOMAIN);
            case 'settings':
                return $this->trans('Smaily Connect — Settings', self::DOMAIN);
            case 'log':
                return $this->trans('Smaily Connect — Log', self::DOMAIN);
            default:
                return $this->trans('Smaily Connect — Dashboard', self::DOMAIN);
        }
    }

    /**
     * @return list<array{id: string, code: string, text: string}>
     */
    private function notices(): array
    {
        $messages = new NoticeMessages(
            self::translator(),
            static fn (int $idShop): string => self::shopName($idShop) ?? (string) $idShop
        );

        return array_map(static fn (array $notice): array => [
            'id' => $notice['id'],
            'code' => $notice['code'],
            'text' => $messages->text($notice),
        ], Services::instance()->get(MigrationNotices::class)->all());
    }
}
