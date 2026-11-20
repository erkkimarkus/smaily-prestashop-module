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

namespace PrestaShop\Module\SmailyForPrestaShop\Admin\Api\Settings;

if (!defined('_PS_VERSION_')) {
    exit;
}

use PrestaShop\Module\SmailyForPrestaShop\Admin\Settings\LayoutChange;
use PrestaShop\Module\SmailyForPrestaShop\Admin\Setup\StoreShapePanel;
use Symfony\Component\HttpFoundation\Request;

/**
 * Changes how the shops of a configured multishop store are run (Admin\Settings\LayoutChange):
 * POST {"shape": "one" | "separate"}. To one store, the values of the shop selected in
 * PrestaShop's shop selector (the default shop with "All shops") become the shared ones and every
 * shop's own values are removed; the page asks the merchant before it posts. The same shape as now
 * changes nothing. A store that has not said how its shops are run answers "one" through the
 * Initial setup's panel (Admin\Setup\StoreShapePanel), which owns that first answer.
 */
final class ChangeLayout extends AbstractSettingsAction
{
    protected function handle(Request $request, int $idShop)
    {
        $shape = (string) ($this->body($request)['shape'] ?? '');
        $settings = $this->settings();
        $scope = $settings->context($idShop)->shared();
        $panel = $settings->setup()->shape();
        $errors = $panel->shapeErrors($scope, $shape);
        if ($errors !== []) {
            return $this->fieldErrors($errors);
        }
        if ($shape === StoreShapePanel::SEPARATE_STORES) {
            $settings->layoutChange()->toSeparateStores();
        } elseif ($scope->separateStores()) {
            $settings->layoutChange()->toOneStore(LayoutChange::promotedShop($idShop));
        } else {
            $panel->save($scope, ['shape' => $shape]);
        }

        return [];
    }
}
