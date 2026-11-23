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

namespace PrestaShop\Module\SmailyForPrestaShop\Admin\Api\Setup;

if (!defined('_PS_VERSION_')) {
    exit;
}

use PrestaShop\Module\SmailyForPrestaShop\Admin\Api\AbstractJsonAction;
use PrestaShop\Module\SmailyForPrestaShop\Admin\BaseController;
use PrestaShop\Module\SmailyForPrestaShop\Admin\Setup\SetupPanels;
use PrestaShop\Module\SmailyForPrestaShop\Report\SetupChange;
use PrestaShop\Module\SmailyForPrestaShop\Runtime\Services;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * The base of the Initial setup's endpoints: the panels, and the answer that names the fields at
 * fault, `{"ok": false, "error": {"code": "invalid_fields", "message": "…", "fields": [{"field",
 * "message"}]}}` with HTTP 422, so the page marks each field (Magento's two-layer error model).
 */
abstract class AbstractSetupAction extends AbstractJsonAction
{
    protected function panels(): SetupPanels
    {
        return new SetupPanels(Services::instance(), BaseController::translator());
    }

    /**
     * Runs a save of the setup or the Settings and tells the installation report about it
     * (Report\SetupChange): a save that removed a Smaily account is reported at once, and a save
     * that went through schedules a snapshot. A save that answers an error did not go through.
     *
     * @param callable(): (array<string, mixed>|JsonResponse) $save
     *
     * @return array<string, mixed>|JsonResponse what $save answered
     */
    protected function reported(callable $save)
    {
        $change = Services::instance()->get(SetupChange::class);
        $before = $change->before();
        $answer = $save();
        $change->after($before, !$answer instanceof JsonResponse);

        return $answer;
    }

    /**
     * @param list<array{field: string, message: string}> $fields
     */
    protected function fieldErrors(array $fields): JsonResponse
    {
        return $this->error('invalid_fields', $fields[0]['message'], 422, $fields);
    }
}
