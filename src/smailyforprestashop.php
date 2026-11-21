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

if (!defined('_PS_VERSION_')) {
    exit;
}

require_once __DIR__ . '/vendor/autoload.php';

use PrestaShop\Module\SmailyForPrestaShop\Hook\Dispatcher;
use PrestaShop\Module\SmailyForPrestaShop\Install\Installer;
use PrestaShop\Module\SmailyForPrestaShop\Install\Uninstaller;
use PrestaShop\Module\SmailyForPrestaShop\Migration\LegacyOverride;
use PrestaShop\Module\SmailyForPrestaShop\Runtime\Services;

class SmailyForPrestaShop extends Module
{
    /**
     * The admin menu: "Smaily Connect" under Customers, with its four pages. PrestaShop installs
     * these on install and removes them on uninstall; Migration\Upgrade300 adds them on an upgrade.
     *
     * @var list<array<string, mixed>>
     */
    public $tabs = [
        [
            'name' => 'Smaily Connect',
            'class_name' => 'AdminSmailyConnect',
            'route_name' => 'smailyforprestashop_index',
            'parent_class_name' => 'AdminParentCustomer',
            'wording' => 'Smaily Connect',
            'wording_domain' => 'Modules.Smailyforprestashop.Admin',
        ],
        [
            'name' => 'Dashboard',
            'class_name' => 'AdminSmailyConnectDashboard',
            'route_name' => 'smailyforprestashop_dashboard',
            'parent_class_name' => 'AdminSmailyConnect',
            'wording' => 'Dashboard',
            'wording_domain' => 'Modules.Smailyforprestashop.Admin',
        ],
        [
            'name' => 'Initial setup',
            'class_name' => 'AdminSmailyConnectSetup',
            'route_name' => 'smailyforprestashop_setup',
            'parent_class_name' => 'AdminSmailyConnect',
            'wording' => 'Initial setup',
            'wording_domain' => 'Modules.Smailyforprestashop.Admin',
        ],
        [
            'name' => 'Settings',
            'class_name' => 'AdminSmailyConnectSettings',
            'route_name' => 'smailyforprestashop_settings',
            'parent_class_name' => 'AdminSmailyConnect',
            'wording' => 'Settings',
            'wording_domain' => 'Modules.Smailyforprestashop.Admin',
        ],
        [
            'name' => 'Log',
            'class_name' => 'AdminSmailyConnectLog',
            'route_name' => 'smailyforprestashop_log',
            'parent_class_name' => 'AdminSmailyConnect',
            'wording' => 'Log',
            'wording_domain' => 'Modules.Smailyforprestashop.Admin',
        ],
    ];

    public function __construct()
    {
        $this->name = 'smailyforprestashop';
        $this->tab = 'advertising_marketing';
        $this->module_key = 'bcea90ce4da2594c0d0179852db9a1e3';
        $this->version = '3.0.0';
        $this->author = 'Smaily';
        $this->need_instance = 0;
        $this->ps_versions_compliancy = [
            'min' => '8.0.0',
            'max' => '9.99.99',
        ];
        $this->bootstrap = true;

        parent::__construct();

        $this->displayName = $this->trans('Smaily Connect', [], 'Modules.Smailyforprestashop.Admin');
        $this->description = $this->trans('Smaily email marketing and automation module for PrestaShop.', [], 'Modules.Smailyforprestashop.Admin');
        $this->confirmUninstall = $this->trans('Are you sure you want to uninstall?', [], 'Modules.Smailyforprestashop.Admin');

        if (version_compare(PHP_VERSION, '8.0.0', '<')) {
            $this->warning = $this->trans('Smaily for PrestaShop requires PHP 8.0 or newer.', [], 'Modules.Smailyforprestashop.Admin');
        }
    }

    public function install(): bool
    {
        if (version_compare(PHP_VERSION, '8.0.0', '<')) {
            $this->_errors[] = $this->trans('Smaily for PrestaShop requires PHP 8.0 or newer.', [], 'Modules.Smailyforprestashop.Admin');

            return false;
        }

        if (!parent::install()) {
            return false;
        }

        // Check if multistore is enabled
        if (Shop::isFeatureActive()) {
            Shop::setContext(Shop::CONTEXT_ALL);
        }

        $installer = new Installer();

        return $installer->install($this);
    }

    public function uninstall(): bool
    {
        $uninstaller = new Uninstaller(Services::instance(), new LegacyOverride(_PS_OVERRIDE_DIR_));

        return $uninstaller->uninstall() && parent::uninstall();
    }

    public function getContent(): void
    {
        Tools::redirectAdmin($this->get('router')->generate('smailyforprestashop_index'));
    }

    /**
     * Enable using new translation system for translating module.
     */
    public function isUsingNewTranslationSystem()
    {
        return true;
    }

    // One method per hook of Install\HookList. Each hands the call to the listeners that the
    // providers register; while the schema is not current, none runs.

    public function hookActionCustomerAccountAdd(array $params)
    {
        return Dispatcher::dispatch('actionCustomerAccountAdd', $params);
    }

    public function hookActionObjectCustomerUpdateBefore(array $params)
    {
        return Dispatcher::dispatch('actionObjectCustomerUpdateBefore', $params);
    }

    public function hookActionObjectCustomerUpdateAfter(array $params)
    {
        return Dispatcher::dispatch('actionObjectCustomerUpdateAfter', $params);
    }

    public function hookActionCustomerAccountUpdate(array $params)
    {
        return Dispatcher::dispatch('actionCustomerAccountUpdate', $params);
    }

    public function hookActionNewsletterRegistrationBefore(array $params)
    {
        return Dispatcher::dispatch('actionNewsletterRegistrationBefore', $params);
    }

    public function hookActionNewsletterRegistrationAfter(array $params)
    {
        return Dispatcher::dispatch('actionNewsletterRegistrationAfter', $params);
    }

    public function hookActionAuthentication(array $params)
    {
        return Dispatcher::dispatch('actionAuthentication', $params);
    }

    public function hookAdditionalCustomerFormFields(array $params)
    {
        return Dispatcher::dispatch('additionalCustomerFormFields', $params);
    }

    public function hookActionValidateOrder(array $params)
    {
        return Dispatcher::dispatch('actionValidateOrder', $params);
    }

    public function hookActionOrderStatusPostUpdate(array $params)
    {
        return Dispatcher::dispatch('actionOrderStatusPostUpdate', $params);
    }

    public function hookActionOrderEdited(array $params)
    {
        return Dispatcher::dispatch('actionOrderEdited', $params);
    }

    public function hookActionOrderSlipAdd(array $params)
    {
        return Dispatcher::dispatch('actionOrderSlipAdd', $params);
    }

    public function hookActionObjectProductAddAfter(array $params)
    {
        return Dispatcher::dispatch('actionObjectProductAddAfter', $params);
    }

    public function hookActionObjectProductUpdateAfter(array $params)
    {
        return Dispatcher::dispatch('actionObjectProductUpdateAfter', $params);
    }

    public function hookActionUpdateQuantity(array $params)
    {
        return Dispatcher::dispatch('actionUpdateQuantity', $params);
    }

    public function hookActionObjectCombinationUpdateAfter(array $params)
    {
        return Dispatcher::dispatch('actionObjectCombinationUpdateAfter', $params);
    }

    public function hookActionObjectSpecificPriceAddAfter(array $params)
    {
        return Dispatcher::dispatch('actionObjectSpecificPriceAddAfter', $params);
    }

    public function hookActionObjectSpecificPriceUpdateAfter(array $params)
    {
        return Dispatcher::dispatch('actionObjectSpecificPriceUpdateAfter', $params);
    }

    public function hookActionObjectSpecificPriceDeleteAfter(array $params)
    {
        return Dispatcher::dispatch('actionObjectSpecificPriceDeleteAfter', $params);
    }

    public function hookActionObjectProductDeleteAfter(array $params)
    {
        return Dispatcher::dispatch('actionObjectProductDeleteAfter', $params);
    }

    public function hookActionObjectCombinationDeleteAfter(array $params)
    {
        return Dispatcher::dispatch('actionObjectCombinationDeleteAfter', $params);
    }

    public function hookActionObjectShopDeleteAfter(array $params)
    {
        return Dispatcher::dispatch('actionObjectShopDeleteAfter', $params);
    }

    public function hookActionFrontControllerSetMedia(array $params)
    {
        return Dispatcher::dispatch('actionFrontControllerSetMedia', $params);
    }

    public function hookActionAdminControllerSetMedia(array $params)
    {
        return Dispatcher::dispatch('actionAdminControllerSetMedia', $params);
    }

    public function hookDisplayBackOfficeHeader(array $params)
    {
        return Dispatcher::dispatch('displayBackOfficeHeader', $params);
    }

    public function hookDisplayCustomerAccount(array $params)
    {
        return Dispatcher::dispatch('displayCustomerAccount', $params);
    }

    public function hookModuleRoutes(array $params)
    {
        return Dispatcher::dispatch('moduleRoutes', $params);
    }

    public function hookActionExportGDPRData(array $params)
    {
        return Dispatcher::dispatch('actionExportGDPRData', $params);
    }

    public function hookActionDeleteGDPRCustomer(array $params)
    {
        return Dispatcher::dispatch('actionDeleteGDPRCustomer', $params);
    }

    public function hookRegisterGDPRConsent(array $params)
    {
        return Dispatcher::dispatch('registerGDPRConsent', $params);
    }
}
