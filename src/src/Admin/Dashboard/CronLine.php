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

namespace PrestaShop\Module\SmailyForPrestaShop\Admin\Dashboard;

if (!defined('_PS_VERSION_')) {
    exit;
}

use PrestaShop\Module\SmailyForPrestaShop\Admin\Api\AbstractJsonAction;
use PrestaShop\Module\SmailyForPrestaShop\Config\TickSettings;
use PrestaShop\Module\SmailyForPrestaShop\Runtime\Services;
use Symfony\Component\HttpFoundation\Request;

/**
 * "Show the cron line" on the Dashboard's Background tasks card, for a store whose tick URL has no
 * token yet: the Dashboard itself never creates one, so the admin asks for it here. Answers
 * `{"line": "<crontab line>"}`. Needs the right to change the settings, as the token opens the tick
 * URL.
 */
final class CronLine extends AbstractJsonAction
{
    protected function handle(Request $request, int $idShop)
    {
        $token = Services::instance()->get(TickSettings::class)->token(TickSettings::TICK_TOKEN, 0);

        return ['line' => BackgroundTasks::cronLine(DashboardController::tickUrl($token))];
    }
}
