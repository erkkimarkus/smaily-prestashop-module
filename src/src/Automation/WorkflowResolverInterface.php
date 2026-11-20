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

namespace PrestaShop\Module\SmailyForPrestaShop\Automation;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Resolves the Smaily workflow, and the account that sends it, for a (trigger, shop, language).
 */
interface WorkflowResolverInterface
{
    /**
     * @param string $trigger welcome, first_order or abandoned_cart
     * @param string $language the contact's two-letter ISO code, '' when unknown
     *
     * @return WorkflowMatch|null null when no workflow is mapped: a terminal skip
     */
    public function resolve(string $trigger, int $idShop, string $language): ?WorkflowMatch;
}
