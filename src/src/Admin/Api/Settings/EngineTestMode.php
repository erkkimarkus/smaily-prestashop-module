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
 * The "Test mode" switch of a Campaign Intelligence email on Settings > Automations (contract §20;
 * as Magento's Automations\TestMode): POST {"key": <catalogue key>, "test_mode": false,
 * "confirmed": true} turns test mode off, which switches real sends on, once the employee confirmed
 * the page's dialog; {"key", "test_mode": true} turns it on again. The employee's id goes to
 * Campaign Intelligence as `switched_by`.
 *
 * Answers as Admin\Api\Settings\EngineTestSend. Turning test mode off without `confirmed: true`
 * answers HTTP 400 `not_confirmed` and asks nothing.
 */
final class EngineTestMode extends AbstractSettingsAction
{
    protected function handle(Request $request, int $idShop)
    {
        $settings = $this->settings();
        $target = $settings->context($idShop)->shared()->targetShop();
        if ($target === null) {
            return $this->error('no_shop', $this->trans('These shops are set up as separate stores. Reload the page and set each shop up on its own.', BaseController::DOMAIN));
        }
        $body = $this->body($request);
        $testMode = ($body['test_mode'] ?? null) !== false;
        if (!$testMode && ($body['confirmed'] ?? null) !== true) {
            return $this->error('not_confirmed', $this->trans('Campaign Intelligence did not take the request. Try again in a moment.', BaseController::DOMAIN));
        }
        $employee = \Context::getContext()->employee;
        $result = $settings->engineAutomations()->switchTestMode(
            $target,
            is_string($body['key'] ?? null) ? $body['key'] : '',
            $testMode,
            $employee !== null && (int) $employee->id > 0 ? (string) (int) $employee->id : ''
        );
        if ($result['code'] !== '') {
            return $this->error($result['code'], $result['message'], 422);
        }

        return ['done' => $result['done'], 'message' => $result['message'], 'reasons' => $result['reasons'], 'state' => $result['state']];
    }
}
