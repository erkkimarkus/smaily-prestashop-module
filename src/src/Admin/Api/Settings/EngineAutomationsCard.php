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
 * The card "Campaign Intelligence Automations" of Settings > Automations, asked of Campaign
 * Intelligence now: the answer is `{"html": "<the card>"}`
 * (views/templates/admin/settings/engine-automations-card.html.twig). The page asks once the
 * Automations tab shows, so a slow engine never holds the Settings page, and every load lists what
 * the engine offers at that moment. Reads only.
 *
 * The connection is the one that serves the shop the page saves for: the installation's in one
 * store, whichever shop is selected; the selected shop's in separate stores.
 */
final class EngineAutomationsCard extends AbstractSettingsAction
{
    public const TEMPLATE = '@Modules/smailyforprestashop/views/templates/admin/settings/engine-automations-card.html.twig';

    protected const PERMISSION = 'read';

    protected function handle(Request $request, int $idShop)
    {
        $settings = $this->settings();
        $target = $settings->context($idShop)->shared()->targetShop();
        if ($target === null) {
            return $this->error('no_shop', $this->trans('These shops are set up as separate stores. Reload the page and set each shop up on its own.', BaseController::DOMAIN));
        }

        return ['html' => $this->renderView(self::TEMPLATE, self::view(
            $settings->engineAutomations()->card($target, (string) \Context::getContext()->language->iso_code)
        ))];
    }

    /**
     * What the card's template takes: the `card`, and the employee's own address, which "Send test
     * email" offers first (`admin_email`).
     *
     * @param array<string, mixed> $card Admin\Settings\EngineAutomationsPanel::card()
     *
     * @return array{card: array<string, mixed>, admin_email: string}
     */
    public static function view(array $card): array
    {
        $employee = \Context::getContext()->employee;

        return ['card' => $card, 'admin_email' => $employee !== null ? (string) $employee->email : ''];
    }
}
