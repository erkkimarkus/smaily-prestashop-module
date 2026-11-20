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

use PrestaShop\Module\SmailyForPrestaShop\Admin\Api\Setup\TestSmaily;
use PrestaShop\Module\SmailyForPrestaShop\Admin\BaseController;
use PrestaShop\Module\SmailyForPrestaShop\Admin\Settings\SettingsPanels;
use PrestaShop\Module\SmailyForPrestaShop\Admin\Setup\SetupScope;
use PrestaShop\Module\SmailyForPrestaShop\Runtime\Services;

/**
 * "Test connection" and the connection's state on Settings > Connection, through the Initial
 * setup's Connection panel, as Api\Setup\TestSmaily answers them: POST {subdomain, username,
 * password, language} asks Smaily with the typed values and saves nothing; POST {"saved": true,
 * "language": …} asks about the stored account, or answers `{"connected": null}` when none is
 * stored.
 *
 * It reads the account the tab shows: a selected shop's own account where it holds one, else the
 * shared one. Like the setup's test, it takes the right to change Settings: it sends typed
 * credentials to Smaily.
 */
final class TestConnection extends TestSmaily
{
    protected function scope(int $idShop): SetupScope
    {
        $settings = new SettingsPanels(Services::instance(), BaseController::translator());

        return $settings->context($idShop)->connectionScope($settings->overrides());
    }
}
