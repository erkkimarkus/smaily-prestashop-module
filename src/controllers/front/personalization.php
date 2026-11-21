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

use PrestaShop\Module\SmailyForPrestaShop\Privacy\AccountLink;
use PrestaShop\Module\SmailyForPrestaShop\Privacy\ProfilingConsent;
use PrestaShop\Module\SmailyForPrestaShop\Runtime\Log;
use PrestaShop\Module\SmailyForPrestaShop\Runtime\Services;

/**
 * The page "Personalization" of the customer's account: a signed-in customer allows or stops
 * personalized recommendations (Privacy\ProfilingConsent), with the Magento connector's wording.
 *
 * The page exists only in a shop that has a Campaign Intelligence connection: elsewhere its
 * address answers as a page that the store does not have. A visitor who is not signed in goes to
 * the sign-in page first. A save needs the customer's own token, writes the store's record, queues
 * the choice for the engine and returns to the page with its confirmation.
 */
class SmailyforprestashopPersonalizationModuleFrontController extends ModuleFrontController
{
    private const DOMAIN = 'Modules.Smailyforprestashop.Shop';

    /** @var bool */
    public $auth = true;

    /** @var bool */
    public $guestAllowed = false;

    /** @var bool */
    public $ssl = true;

    public function init()
    {
        try {
            $available = Services::instance()->get(AccountLink::class)->available((int) $this->context->shop->id);
        } catch (Throwable $error) {
            Log::failure('the personalization page', $error);
            $available = false;
        }
        if (!$available) {
            Tools::redirect($this->context->link->getPageLink('pagenotfound'));
        }

        parent::init();
    }

    public function postProcess()
    {
        if (!Tools::isSubmit('submitPersonalization')) {
            return;
        }
        $page = AccountLink::url($this->context);
        if (!hash_equals(Tools::getToken(false), (string) Tools::getValue('token'))) {
            Tools::redirect($page);
        }

        Services::instance()->get(ProfilingConsent::class)->setAllowed(
            (string) $this->context->customer->email,
            (bool) Tools::getValue('profiling_allowed'),
            (int) $this->context->shop->id
        );
        $this->success[] = $this->trans('Your personalization preference has been saved.', [], self::DOMAIN);
        $this->redirectWithNotifications($page);
    }

    public function initContent()
    {
        parent::initContent();

        $this->context->smarty->assign('smaily_personalization', [
            'action' => AccountLink::url($this->context),
            'token' => Tools::getToken(false),
            'allowed' => Services::instance()->get(ProfilingConsent::class)->isAllowed((string) $this->context->customer->email),
            'account_menu' => AccountLink::themeHasAccountMenu(),
        ]);
        $this->setTemplate('module:smailyforprestashop/views/templates/front/personalization.tpl');
    }

    public function getTemplateVarPage()
    {
        $page = parent::getTemplateVarPage();
        $page['meta']['title'] = $this->trans('Personalization', [], self::DOMAIN);

        return $page;
    }

    public function getBreadcrumbLinks()
    {
        $breadcrumb = parent::getBreadcrumbLinks();
        $breadcrumb['links'][] = $this->addMyAccountToBreadcrumb();
        $breadcrumb['links'][] = [
            'title' => $this->trans('Personalization', [], self::DOMAIN),
            'url' => AccountLink::url($this->context),
        ];

        return $breadcrumb;
    }
}
