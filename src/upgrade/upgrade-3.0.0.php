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
if (!defined('_PS_VERSION_')) {
    exit;
}

use PrestaShop\Module\SmailyForPrestaShop\Migration\Upgrade300;
use PrestaShop\Module\SmailyForPrestaShop\Runtime\Log;
use PrestaShop\Module\SmailyForPrestaShop\Runtime\Services;

/**
 * Upgrades 2.x or 1.6.x to 3.0.0 in place.
 *
 * Always returns true. On a false result PrestaShop would still store version 3.0.0, disable the
 * module and never run this script again; an exception would disable it on a zip upload. A migration
 * that does not complete stays pending instead: the runtime stays inert, the legacy settings stay,
 * and Upgrade300::runPending() finishes it later.
 *
 * @param Module $module
 *
 * @return bool
 */
function upgrade_module_3_0_0($module)
{
    try {
        Services::instance()->get(Upgrade300::class)->runPending();
    } catch (Throwable $error) {
        Log::failure('the start of the 3.0.0 upgrade', $error);
    }

    return true;
}
