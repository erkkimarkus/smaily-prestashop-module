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

use PrestaShop\Module\SmailyForPrestaShop\Admin\BaseController;
use Symfony\Component\HttpFoundation\Request;

/**
 * Changes how the shops of a configured multishop store are run (Admin\Settings\LayoutChange):
 * POST {"shape": "one" | "separate"}. To one store, the values of the shop selected in
 * PrestaShop's shop selector (the default shop with "All shops") become the shared ones; the page
 * asks the merchant before it posts. The same shape as now changes nothing.
 */
final class ChangeLayout extends AbstractSettingsAction
{
    protected function handle(Request $request, int $idShop)
    {
        $shape = (string) ($this->body($request)['shape'] ?? '');
        if (!\Shop::isFeatureActive() || !in_array($shape, ['one', 'separate'], true)) {
            return $this->fieldErrors([['field' => 'shape', 'message' => $this->trans('Pick how your shops are run.', BaseController::DOMAIN)]]);
        }
        $change = $this->settings()->layoutChange();
        if ($shape === 'separate') {
            $change->toSeparateStores();
        } else {
            $change->toOneStore($idShop > 0 ? $idShop : (int) \Configuration::get('PS_SHOP_DEFAULT'));
        }

        return [];
    }
}
