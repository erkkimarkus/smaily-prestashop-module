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

namespace PrestaShop\Module\SmailyForPrestaShop\Admin\Setup;

if (!defined('_PS_VERSION_')) {
    exit;
}

use PrestaShop\Module\SmailyForPrestaShop\Automation\MappingRepository;
use PrestaShop\Module\SmailyForPrestaShop\Automation\Router;
use PrestaShop\Module\SmailyForPrestaShop\Automation\WorkflowList;
use PrestaShop\Module\SmailyForPrestaShop\Config\AdminSettings;
use PrestaShop\Module\SmailyForPrestaShop\Config\AutomationSettings;
use PrestaShop\Module\SmailyForPrestaShop\Config\ContactsSettings;
use PrestaShop\Module\SmailyForPrestaShop\Http\Transport;
use PrestaShop\Module\SmailyForPrestaShop\Runtime\Services;
use PrestaShop\Module\SmailyForPrestaShop\Scope\StoreLayout;
use PrestaShop\Module\SmailyForPrestaShop\Smaily\AccountStore;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Builds the panels of the Initial setup (and later of Settings) from the runtime's services, with
 * the admin's translator. The Smaily calls go through the runtime's Http\Transport, which a test
 * replaces in Services.
 */
final class SetupPanels
{
    private Services $services;

    private TranslatorInterface $translator;

    public function __construct(Services $services, TranslatorInterface $translator)
    {
        $this->services = $services;
        $this->translator = $translator;
    }

    public function scope(int $selectedShop): SetupScope
    {
        return SetupScope::current($this->layout(), $selectedShop);
    }

    public function connection(): ConnectionPanel
    {
        return new ConnectionPanel(
            $this->accounts(),
            $this->layout(),
            new SmailyCheck($this->transport(), $this->translator),
            $this->translator
        );
    }

    public function shape(): StoreShapePanel
    {
        return new StoreShapePanel($this->layout(), $this->translator);
    }

    public function contacts(): ContactsPanel
    {
        return new ContactsPanel(
            $this->contactsSettings(),
            $this->translator,
            static fn (): bool => (bool) \Module::isEnabled('ps_emailsubscription')
        );
    }

    public function automations(): AutomationsPanel
    {
        /** @var AutomationSettings $settings */
        $settings = $this->services->get(AutomationSettings::class);
        /** @var MappingRepository $mappings */
        $mappings = $this->services->get(MappingRepository::class);
        /** @var Router $router */
        $router = $this->services->get(Router::class);
        /** @var WorkflowList $workflows */
        $workflows = $this->services->get(WorkflowList::class);

        return new AutomationsPanel($settings, $mappings, $this->layout(), $router, $workflows, $this->translator);
    }

    public function progress(): SetupProgress
    {
        return new SetupProgress(new AdminSettings(), $this->accounts(), $this->contactsSettings());
    }

    private function layout(): StoreLayout
    {
        /** @var StoreLayout $layout */
        $layout = $this->services->get(StoreLayout::class);

        return $layout;
    }

    private function accounts(): AccountStore
    {
        /** @var AccountStore $accounts */
        $accounts = $this->services->get(AccountStore::class);

        return $accounts;
    }

    private function contactsSettings(): ContactsSettings
    {
        /** @var ContactsSettings $settings */
        $settings = $this->services->get(ContactsSettings::class);

        return $settings;
    }

    private function transport(): Transport
    {
        /** @var Transport $transport */
        $transport = $this->services->get(Transport::class);

        return $transport;
    }
}
