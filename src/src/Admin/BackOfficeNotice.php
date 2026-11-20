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

use PrestaShop\Module\SmailyForPrestaShop\Config\MigrationNotices;
use PrestaShop\Module\SmailyForPrestaShop\Install\Schema;

/**
 * The one line on the other back-office pages (hook displayBackOfficeHeader) while the upgrade is
 * not finished, or while it has left notices that no admin has dismissed. It links to the
 * Dashboard, which shows the banner and the notices, and it is drawn for an employee who may open
 * the Dashboard only. The module's own pages show the full banner and notices instead.
 *
 * The hook prints into the page's head, so the line is a small script that puts a PrestaShop alert
 * at the top of the page's content.
 */
final class BackOfficeNotice
{
    private MigrationNotices $notices;

    public function __construct(MigrationNotices $notices)
    {
        $this->notices = $notices;
    }

    /**
     * @param array<string, mixed> $params
     */
    public function __invoke(array $params): ?string
    {
        $context = \Context::getContext();
        $tab = BaseController::PAGES['dashboard']['tab'];
        if (!$context->employee || !\Tab::checkTabRights((int) \Tab::getIdFromClassName($tab))) {
            return null;
        }
        $pending = !Schema::isCurrent();
        if (!$pending && $this->notices->all() === []) {
            return null;
        }

        $translator = $context->getTranslator();
        $line = [
            'text' => $pending
                ? $translator->trans('Smaily Connect: the upgrade to version 3.0.0 is not finished.', [], BaseController::DOMAIN)
                : $translator->trans('Smaily Connect: the upgrade left notices for you.', [], BaseController::DOMAIN),
            'action' => $pending
                ? $translator->trans('Finish upgrade', [], BaseController::DOMAIN)
                : $translator->trans('Read them', [], BaseController::DOMAIN),
            'url' => $context->link->getAdminLink($tab),
        ];

        return '<script>document.addEventListener("DOMContentLoaded",function(){'
            . 'var line=' . json_encode($line, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) . ';'
            . 'if(document.querySelector(".smaily-ui"))return;'
            . 'var content=document.querySelector("#content.bootstrap")||document.querySelector(".content-div .container-fluid")||document.querySelector(".content-div");'
            . 'if(!content)return;'
            . 'var alert=document.createElement("div");alert.className="alert alert-warning";alert.setAttribute("role","status");'
            . 'var text=document.createElement("p");text.className="alert-text";text.textContent=line.text+" ";'
            . 'var link=document.createElement("a");link.href=line.url;link.textContent=line.action;'
            . 'text.appendChild(link);alert.appendChild(text);content.insertBefore(alert,content.firstChild);'
            . '});</script>';
    }
}
