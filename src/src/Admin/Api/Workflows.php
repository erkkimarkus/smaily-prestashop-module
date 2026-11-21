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
 * The workflows that the Automations panel's selects offer, asked of Smaily now, as Magento's
 * Api\Workflows lists them: POST {"account": "default"|<language>, "own": bool} answers
 * `{"workflows": [{"id", "name"}], "error": <sentence>|null}`. The list is the one of the stored
 * account that the rows of that account key send through (Admin\Setup\AutomationsPanel); nothing
 * is cached, so "Refresh workflows" asks again.
 *
 * One class serves the Initial setup's route and the Settings route; each names its page's menu
 * tab, whose right to read is enough: the request changes nothing. `own` is the Settings switch
 * "Use its own automations on this shop"; the Initial setup posts none.
 */
final class Workflows extends AbstractSettingsAction
{
    protected const PERMISSION = 'read';

    protected function handle(Request $request, int $idShop)
    {
        $body = $this->body($request);
        $settings = $this->settings();
        $scope = $settings->context($idShop)->automationsScope($settings->overrides(), !empty($body['own']));

        return $settings->setup()->automations()->workflows($scope, (string) ($body['account'] ?? 'default'));
    }
}
