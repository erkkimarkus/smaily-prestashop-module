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
use Symfony\Component\HttpFoundation\Request;

/**
 * Saves one step of the Initial setup, as Magento's Api\SaveStep: POST {"step": …, "data": {…}}.
 *
 * - `shape`: how the shops are run (StoreShapePanel).
 * - `connect`: the Smaily account and the language mode (ConnectionPanel); answers `connected`
 *   and, when Smaily refused or could not be asked, `error`. The save stands either way.
 * - `contacts`: the Contacts settings (ContactsPanel); contact sync starts with it.
 * - `automations`: the store automations (AutomationsPanel).
 * - `finish`: the setup is done; the Configure button opens the Dashboard from now on.
 */
final class SaveStep extends AbstractSetupAction
{
    protected function handle(Request $request, int $idShop)
    {
        $body = $this->body($request);
        $data = is_array($body['data'] ?? null) ? $body['data'] : [];
        $panels = $this->panels();
        $scope = $panels->scope($idShop);

        switch ((string) ($body['step'] ?? '')) {
            case 'shape':
                $errors = $panels->shape()->save($scope, $data);

                return $errors === [] ? [] : $this->fieldErrors($errors);
            case 'connect':
                $result = $panels->connection()->save($scope, $data);
                if ($result['errors'] !== []) {
                    return $this->fieldErrors($result['errors']);
                }

                return ['connected' => $result['connected'], 'error' => $result['error'], 'workflows_removed' => $result['workflows_removed']];
            case 'contacts':
                $errors = $panels->contacts()->save($scope, $data);

                return $errors === [] ? [] : $this->fieldErrors($errors);
            case 'automations':
                $errors = $panels->automations()->save($scope, $data);

                return $errors === [] ? [] : $this->fieldErrors($errors);
            case 'finish':
                $panels->progress()->finish();

                return ['dashboard' => $this->generateUrl(BaseController::PAGES['dashboard']['route'])];
            default:
                return $this->error('unknown_step', $this->trans('Saving failed.', BaseController::DOMAIN));
        }
    }
}
