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

namespace PrestaShop\Module\SmailyForPrestaShop\Admin;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * trans() of the admin pages and their JSON endpoints: the parent controller asks its container
 * for "translator", which PrestaShop 8.2.8 does not hold (BaseController::translator()).
 */
trait ContextTranslation
{
    /**
     * @param string $key
     * @param string $domain
     * @param array<string, mixed> $parameters
     *
     * @return string
     */
    protected function trans($key, $domain, array $parameters = [])
    {
        return BaseController::translator()->trans($key, $parameters, $domain);
    }
}
