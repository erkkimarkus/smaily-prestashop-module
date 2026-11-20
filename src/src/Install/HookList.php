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

namespace PrestaShop\Module\SmailyForPrestaShop\Install;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Every hook the module registers. The main class has one method per hook, and each method hands
 * the call to Hook\Dispatcher.
 */
final class HookList
{
    public const HOOKS = [
        // Customers and newsletter consent
        'actionCustomerAccountAdd',
        'actionObjectCustomerUpdateBefore',
        'actionObjectCustomerUpdateAfter',
        'actionCustomerAccountUpdate',
        'actionObjectCustomerDeleteAfter',
        'actionNewsletterRegistrationAfter',
        'actionAuthentication',
        'additionalCustomerFormFields',
        // Carts and orders
        'actionCartSave',
        'actionValidateOrder',
        'actionOrderStatusPostUpdate',
        'actionOrderEdited',
        'actionOrderSlipAdd',
        // Catalog
        'actionObjectProductAddAfter',
        'actionObjectProductUpdateAfter',
        'actionUpdateQuantity',
        'actionObjectCombinationUpdateAfter',
        'actionObjectSpecificPriceAddAfter',
        'actionObjectSpecificPriceUpdateAfter',
        'actionObjectSpecificPriceDeleteAfter',
        'actionObjectProductDeleteAfter',
        'actionObjectCombinationDeleteAfter',
        // Shops
        'actionObjectShopDeleteAfter',
        // Pages, background tasks and notices
        'actionFrontControllerSetMedia',
        'actionAdminControllerSetMedia',
        'displayBackOfficeHeader',
        'displayCustomerAccount',
        'moduleRoutes',
        // Privacy (psgdpr)
        'actionExportGDPRData',
        'actionDeleteGDPRCustomer',
        'registerGDPRConsent',
    ];
}
