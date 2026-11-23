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
use Symfony\Component\HttpFoundation\Request;

/**
 * Connects the page's scope to Campaign Intelligence: POST {"link": <the setup URL or token>}
 * (Admin\Setup\IntelligencePanel::connect(), contract §1). Answers `{"intelligence": <the
 * panel's values>}`; the catalog, customers and orders imports are then queued. A link that is not
 * taken answers 422 `link_refused` with the module's own sentence for the reason, never the
 * engine's text. The link is not stored and not logged.
 */
final class EngineExchange extends AbstractSetupAction
{
    protected function handle(Request $request, int $idShop)
    {
        $result = $this->panels()->intelligence()->connect($this->panels()->scope($idShop), (string) ($this->body($request)['link'] ?? ''));

        return $result['error'] !== null ? $this->error('link_refused', $result['error'], 422) : ['intelligence' => $result['values']];
    }
}
