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
 * "Disconnect": ends the Campaign Intelligence connection of the page's scope. The engine is told
 * once (contract §16) and the stored connection is removed whatever it answered; the page asks
 * the merchant before it calls this. Answers `{"intelligence": <the panel's values>}`.
 */
final class EngineDisconnect extends AbstractSetupAction
{
    protected function handle(Request $request, int $idShop)
    {
        return ['intelligence' => $this->panels()->intelligence()->disconnect($this->panels()->scope($idShop))];
    }
}
