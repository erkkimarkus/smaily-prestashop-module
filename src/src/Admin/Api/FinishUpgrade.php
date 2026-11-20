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

use PrestaShop\Module\SmailyForPrestaShop\Admin\BaseController;
use PrestaShop\Module\SmailyForPrestaShop\Migration\Upgrade300;
use PrestaShop\Module\SmailyForPrestaShop\Runtime\Services;
use Symfony\Component\HttpFoundation\Request;

/**
 * "Finish upgrade": runs the pending 3.0.0 migration again (Upgrade300::runPending()). It answers
 * `{"current": true}` once the schema is current, and an error while the migration stays pending.
 */
final class FinishUpgrade extends AbstractJsonAction
{
    protected function handle(Request $request, int $idShop)
    {
        if (Services::instance()->get(Upgrade300::class)->runPending()) {
            return ['current' => true];
        }

        return $this->error(
            'upgrade_not_finished',
            $this->trans('The upgrade did not finish. Your previous settings are kept. Try again in a few minutes; if it keeps stopping, see the PrestaShop logs.', BaseController::DOMAIN),
            409
        );
    }
}
