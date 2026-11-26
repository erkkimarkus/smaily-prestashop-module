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
 * "Send test email" of a Campaign Intelligence email on Settings > Automations (contract §19; as
 * Magento's Automations\TestSend): POST {"key": <catalogue key>, "email": <the typed address>}.
 * The test goes in the shop's default language.
 *
 * Answers `{"done", "message", "reasons", "state"}` (Admin\Settings\EngineAutomationsPanel::
 * testSend()): a refusal of the engine is an answer too, in the admin's words, and `state` is the
 * email's state as Campaign Intelligence reads it after the call. HTTP 422 with the code
 * `card_changed` when Campaign Intelligence does not take the account or the connection is gone;
 * the card says which once it is asked again. The address is passed on and kept nowhere.
 */
final class EngineTestSend extends AbstractSettingsAction
{
    protected function handle(Request $request, int $idShop)
    {
        $settings = $this->settings();
        $target = $settings->context($idShop)->shared()->targetShop();
        if ($target === null) {
            return $this->error('no_shop', $this->trans('These shops are set up as separate stores. Reload the page and set each shop up on its own.', BaseController::DOMAIN));
        }
        $body = $this->body($request);
        $language = (string) \Language::getIsoById((int) \Configuration::get('PS_LANG_DEFAULT', null, null, $target > 0 ? $target : null));
        $result = $settings->engineAutomations()->testSend(
            $target,
            is_string($body['key'] ?? null) ? $body['key'] : '',
            is_string($body['email'] ?? null) ? $body['email'] : '',
            strtolower($language)
        );
        if ($result['code'] !== '') {
            return $this->error($result['code'], $result['message'], 422);
        }

        return ['done' => $result['done'], 'message' => $result['message'], 'reasons' => $result['reasons'], 'state' => $result['state']];
    }
}
