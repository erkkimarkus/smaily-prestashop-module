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
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Saves the card "Campaign Intelligence Automations" of Settings > Automations, as Magento's
 * Automations\Save: POST {"triggers": {<catalogue key>: {"enabled", "test_mode", "workflow_id",
 * "cooldown_days", "daily_cap", "test_emails", "language_mode", "original_map"}}} with every row
 * of the card.
 *
 * A save that went through answers `{"message", "html"}`: `html` is the card drawn again from what
 * Campaign Intelligence holds now, not from the request (null when that could not be read). A save
 * that did not go through answers HTTP 422 with the error's `code` (`invalid_fields`;
 * `card_changed`: Campaign Intelligence does not take the account, or the connection is gone, and
 * the page asks for the card again; or `not_saved`), its `message` and the `fields` at fault
 * (`<catalogue key>.<control>` and the module's sentence).
 */
final class SaveEngineAutomations extends AbstractSettingsAction
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
        $settings = $this->settings();
        $target = $settings->context($idShop)->shared()->targetShop();
        if ($target === null) {
            return $this->error('no_shop', $this->trans('These shops are set up as separate stores. Reload the page and set each shop up on its own.', BaseController::DOMAIN));
        }
        $triggers = $this->body($request)['triggers'] ?? null;
        $result = $settings->engineAutomations()->save($target, is_array($triggers) ? $triggers : [], (string) \Context::getContext()->language->iso_code);
        if ($result['saved']) {
            return [
                'message' => $result['message'],
                'html' => $result['card'] === null ? null : $this->renderView(EngineAutomationsCard::TEMPLATE, ['card' => $result['card']]),
            ];
        }

        return $this->error($result['code'], $result['message'], 422, $result['fields']);
    }
}
