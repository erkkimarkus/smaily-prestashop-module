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

namespace PrestaShop\Module\SmailyForPrestaShop\Admin\Api;

if (!defined('_PS_VERSION_')) {
    exit;
}

use PrestaShop\Module\SmailyForPrestaShop\Admin\Api\Settings\AbstractSettingsAction;
use Symfony\Component\HttpFoundation\Request;

/**
 * The cart rules that the Automations panel's discount pickers offer, read from the shop now:
 * POST {"own": bool} answers `{"rules": [{"id", "name"}]}`, the rules that a discount code can be
 * a copy of in the scope the panel saves (Admin\Setup\AutomationsPanel, Discount\TemplateRules).
 * Nothing is cached, so "Refresh workflows" asks again.
 *
 * One class serves the Initial setup's route and the Settings route, as Workflows does; the right
 * to read the page's menu tab is enough. `own` is the Settings switch "Use its own automations on
 * this shop"; the Initial setup posts none.
 */
final class DiscountRules extends AbstractSettingsAction
{
    protected const PERMISSION = 'read';

    protected function handle(Request $request, int $idShop)
    {
        $settings = $this->settings();
        $scope = $settings->context($idShop)->automationsScope($settings->overrides(), !empty($this->body($request)['own']));

        return $settings->setup()->automations()->discountRules($scope);
    }
}
