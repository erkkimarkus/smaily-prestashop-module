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

use PrestaShop\Module\SmailyForPrestaShop\Admin\Api\Setup\AbstractSetupAction;
use PrestaShop\Module\SmailyForPrestaShop\Admin\BaseController;
use Symfony\Component\HttpFoundation\Request;

/**
 * Starts or cancels an import of the page's connection: POST {"entity": "catalog" | "customers" |
 * "orders", "action": "start" | "cancel"}. A start begins a new import from the beginning; a
 * cancel also ends the imports that wait behind it, so "Hold back the import" is a cancel of the
 * catalog import. Answers `{"intelligence": <the panel's values>}`.
 */
final class BackfillAction extends AbstractSetupAction
{
    protected function handle(Request $request, int $idShop)
    {
        $body = $this->body($request);
        $values = $this->panels()->intelligence()->import($this->panels()->scope($idShop), (string) ($body['entity'] ?? ''), (string) ($body['action'] ?? ''));

        return $values === null ? $this->error('unknown_action', $this->trans('Saving failed.', BaseController::DOMAIN)) : ['intelligence' => $values];
    }
}
