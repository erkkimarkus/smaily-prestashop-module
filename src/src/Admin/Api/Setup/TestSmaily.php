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

use Symfony\Component\HttpFoundation\Request;

/**
 * "Test connection", as Magento's Api\TestSmaily: asks Smaily once with the typed credentials and
 * saves nothing. POST {subdomain, username, password, language} answers `{"connected": bool,
 * "error": "…"|null}`; an empty password keeps the stored password of `language`'s account when the
 * subdomain and username are the stored ones. POST {"saved": true, "language": "…"} asks about the
 * stored account (the Overview's connection state), answering `{"connected", "error", "subdomain"}`,
 * or `{"connected": null}` when none is stored. Empty fields and a refused subdomain ask nothing and
 * answer with the fields at fault.
 */
final class TestSmaily extends AbstractSetupAction
{
    protected function handle(Request $request, int $idShop)
    {
        $data = $this->body($request);
        $panels = $this->panels();
        $scope = $panels->scope($idShop);

        if (!empty($data['saved'])) {
            return $panels->connection()->checkStored($scope, (string) ($data['language'] ?? '')) ?? ['connected' => null];
        }
        $result = $panels->connection()->test($scope, $data);
        if ($result['errors'] !== []) {
            return $this->fieldErrors($result['errors']);
        }

        return ['connected' => $result['connected'], 'error' => $result['error']];
    }
}
