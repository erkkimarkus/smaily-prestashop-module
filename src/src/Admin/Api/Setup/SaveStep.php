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
use PrestaShop\Module\SmailyForPrestaShop\Admin\Settings\LayoutChange;
use PrestaShop\Module\SmailyForPrestaShop\Admin\Setup\SetupPanels;
use PrestaShop\Module\SmailyForPrestaShop\Admin\Setup\SetupScope;
use PrestaShop\Module\SmailyForPrestaShop\Admin\Setup\StoreShapePanel;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Saves one step of the Initial setup, as Magento's Api\SaveStep: POST {"step": …, "data": {…}}.
 *
 * - `shape`: how the shops are run (StoreShapePanel). A first answer of separate stores is the
 *   same change as on Settings (Admin\Settings\LayoutChange, as Api\Settings\ChangeLayout runs
 *   it): the values and the Campaign Intelligence connection that a store holds for all shops
 *   become the selected or default shop's, as Magento's wizard does. A first answer of one store
 *   is the layout those values are in, so it is only stored.
 * - `connect`: the Smaily account and the language mode (ConnectionPanel). Smaily must accept every
 *   account the step would store: else nothing is saved, the step is not reached, and the answer
 *   is "Test your Smaily connection to continue." (owner, 2026-11-25; Linear CON-397), as
 *   WooCommerce and Shopify. A saved step answers `connected`.
 * - `contacts`: the Contacts settings (ContactsPanel); contact sync starts with it.
 * - `automations`: the store automations (AutomationsPanel).
 * - `intelligence`: the confirmation that the store's cookie banner covers Smaily tracking
 *   (IntelligencePanel); the connection itself is made at once by Api\EngineExchange.
 * - `finish`: the setup is done; the Configure button opens the Dashboard from now on.
 */
final class SaveStep extends AbstractSetupAction
{
    protected function handle(Request $request, int $idShop)
    {
        return $this->reported(fn () => $this->save($request, $idShop));
    }

    /**
     * @return array<string, mixed>|JsonResponse
     */
    private function save(Request $request, int $idShop)
    {
        $body = $this->body($request);
        $data = is_array($body['data'] ?? null) ? $body['data'] : [];
        $panels = $this->panels();
        $scope = $panels->scope($idShop);

        switch ((string) ($body['step'] ?? '')) {
            case 'shape':
                $errors = $this->saveShape($panels, $scope, $data, $idShop);

                return $errors === [] ? [] : $this->fieldErrors($errors);
            case 'connect':
                $result = $panels->connection()->save($scope, $data, true);
                if ($result['errors'] !== []) {
                    return $this->fieldErrors($result['errors']);
                }

                return ['connected' => $result['connected'], 'error' => $result['error']];
            case 'contacts':
                $errors = $panels->contacts()->save($scope, $data);

                return $errors === [] ? [] : $this->fieldErrors($errors);
            case 'automations':
                $errors = $panels->automations()->save($scope, $data);

                return $errors === [] ? [] : $this->fieldErrors($errors);
            case 'intelligence':
                $errors = $panels->intelligence()->save($scope, $data);

                return $errors === [] ? [] : $this->fieldErrors($errors);
            case 'finish':
                $panels->progress()->finish();

                return ['dashboard' => $this->generateUrl(BaseController::PAGES['dashboard']['route'])];
            default:
                return $this->error('unknown_step', $this->trans('Saving failed.', BaseController::DOMAIN));
        }
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return list<array{field: string, message: string}>
     */
    private function saveShape(SetupPanels $panels, SetupScope $scope, array $data, int $idShop): array
    {
        if ($scope->asksLayoutFirst() && ($data['shape'] ?? null) === StoreShapePanel::SEPARATE_STORES) {
            $this->settings()->layoutChange()->toSeparateStores(LayoutChange::promotedShop($idShop));

            return [];
        }

        return $panels->shape()->save($scope, $data);
    }
}
