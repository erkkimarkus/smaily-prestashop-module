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

namespace PrestaShop\Module\SmailyForPrestaShop\Admin\Api\Setup;

if (!defined('_PS_VERSION_')) {
    exit;
}

use PrestaShop\Module\SmailyForPrestaShop\Admin\BaseController;
use PrestaShop\Module\SmailyForPrestaShop\Admin\Setup\ImportDetails;
use PrestaShop\Module\SmailyForPrestaShop\Contacts\Import\ContactImport;
use PrestaShop\Module\SmailyForPrestaShop\Runtime\Services;
use Symfony\Component\HttpFoundation\Request;

/**
 * The first contact import of the page's scope (Contacts\Import\ContactImport): POST {"action":
 * "start"} starts it (or resumes a stopped one), {"action": "stop"} stops it after the page it
 * sends. Both answer `{"import": <progress>|null}`; the counts and the state carry no contact.
 *
 * A start, which the Initial setup offers on step 2, starts nothing while the scope has no Smaily
 * account stored and answers the words of step 1, as SaveStep's later steps
 * (ConnectionPanel::laterStepErrors()); a stop always stops. Settings' Contacts tab calls the same
 * endpoint.
 */
final class ContactImportAction extends AbstractSetupAction
{
    protected function handle(Request $request, int $idShop)
    {
        $panels = $this->panels();
        $scope = $panels->scope($idShop);
        $target = $scope->targetShop();
        if ($target === null) {
            return $this->error('no_shop', $this->trans('These shops are set up as separate stores. Reload the page and set each shop up on its own.', BaseController::DOMAIN));
        }
        /** @var ContactImport $import */
        $import = Services::instance()->get(ContactImport::class);
        $action = (string) ($this->body($request)['action'] ?? '');
        if ($action === 'start') {
            $refused = $panels->connection()->laterStepErrors($scope);
            if ($refused !== []) {
                return $this->fieldErrors($refused);
            }
            $import->start($target);
        } elseif ($action === 'stop') {
            $import->stop($target);
        } else {
            return $this->error('unknown_action', $this->trans('Saving failed.', BaseController::DOMAIN));
        }

        return ['import' => ImportDetails::contacts($import->progress($target), BaseController::translator())];
    }
}
