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

namespace PrestaShop\Module\SmailyForPrestaShop\Admin\Log;

if (!defined('_PS_VERSION_')) {
    exit;
}

use PrestaShop\Module\SmailyForPrestaShop\Admin\AdminTime;
use PrestaShop\Module\SmailyForPrestaShop\Admin\Api\AbstractJsonAction;
use PrestaShop\Module\SmailyForPrestaShop\Admin\BaseController;
use PrestaShop\Module\SmailyForPrestaShop\Engine\Connections;
use PrestaShop\Module\SmailyForPrestaShop\Log\ResendGuard;
use PrestaShop\Module\SmailyForPrestaShop\Runtime\Services;
use Symfony\Component\HttpFoundation\Request;

/**
 * The Details of one row of the Log: body `{"id": "<log id>"}`, answer `{"html": "<the panel>"}`
 * (views/templates/admin/log/details.html.twig). Reads only. "Send again" shows only for a row
 * that the resend guard clears and an employee who may change the Log. A row to Campaign
 * Intelligence names its engine store where the installation holds several, and says when it waits
 * for the account to be active again (EngineStore).
 */
final class Details extends AbstractJsonAction
{
    protected const PERMISSION = 'read';

    private const TEMPLATE = '@Modules/smailyforprestashop/views/templates/admin/log/details.html.twig';

    protected function handle(Request $request, int $idShop)
    {
        $row = LogController::rows()->find((string) ($this->body($request)['id'] ?? ''));
        if ($row === null) {
            return $this->error('not_found', $this->trans('This log row no longer exists — it has likely been pruned by retention.', BaseController::DOMAIN), 404);
        }
        $translate = LogController::translate();
        $store = new EngineStore(Services::instance()->get(Connections::class));
        $details = (new LogView($translate, AdminTime::forEmployee()))->details(
            $row,
            (new ResendGuard())->refusalOf($row),
            $store->label($row, $translate),
            $store->waitsForAccount($row)
        );
        $details['resend'] = ($details['resend'] ?? false) && $this->isGranted('update', (string) $request->attributes->get('_legacy_controller'));

        return ['html' => $this->renderView(self::TEMPLATE, ['details' => $details])];
    }
}
