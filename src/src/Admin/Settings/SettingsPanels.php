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

namespace PrestaShop\Module\SmailyForPrestaShop\Admin\Settings;

if (!defined('_PS_VERSION_')) {
    exit;
}

use PrestaShop\Module\SmailyForPrestaShop\Admin\Setup\SetupPanels;
use PrestaShop\Module\SmailyForPrestaShop\Automation\MappingRepository;
use PrestaShop\Module\SmailyForPrestaShop\Automation\WorkflowList;
use PrestaShop\Module\SmailyForPrestaShop\Config\EngineSettings;
use PrestaShop\Module\SmailyForPrestaShop\Config\RssSettings;
use PrestaShop\Module\SmailyForPrestaShop\Engine\Automations\EngineAutomations;
use PrestaShop\Module\SmailyForPrestaShop\Engine\Client;
use PrestaShop\Module\SmailyForPrestaShop\Engine\Connections;
use PrestaShop\Module\SmailyForPrestaShop\Engine\Disconnector;
use PrestaShop\Module\SmailyForPrestaShop\Engine\HealthCheck;
use PrestaShop\Module\SmailyForPrestaShop\Engine\History\HistoryJobs;
use PrestaShop\Module\SmailyForPrestaShop\Engine\LayoutConnections;
use PrestaShop\Module\SmailyForPrestaShop\Runtime\Services;
use PrestaShop\Module\SmailyForPrestaShop\Scope\StoreLayout;
use PrestaShop\Module\SmailyForPrestaShop\Smaily\AccountStore;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Builds what the Settings page and its endpoints use from the runtime's services, with the
 * admin's translator: the Initial setup's panels (one writer for both pages), the page's scope,
 * the shops' own values, the change of the shop layout and the RSS panel.
 */
final class SettingsPanels
{
    private Services $services;

    private TranslatorInterface $translator;

    public function __construct(Services $services, TranslatorInterface $translator)
    {
        $this->services = $services;
        $this->translator = $translator;
    }

    public function setup(): SetupPanels
    {
        return new SetupPanels($this->services, $this->translator);
    }

    /**
     * @param int $selectedShop the shop of PrestaShop's shop selector, 0 for "All shops"
     */
    public function context(int $selectedShop): SettingsScope
    {
        return SettingsScope::current($this->layout(), $selectedShop);
    }

    public function overrides(): ShopOverrides
    {
        /** @var AccountStore $accounts */
        $accounts = $this->services->get(AccountStore::class);
        /** @var MappingRepository $mappings */
        $mappings = $this->services->get(MappingRepository::class);

        return new ShopOverrides($accounts, $mappings);
    }

    public function saver(): TabSaver
    {
        return new TabSaver($this->setup(), $this->overrides());
    }

    public function layoutChange(): LayoutChange
    {
        return new LayoutChange(
            $this->layout(),
            $this->overrides(),
            static fn (): array => array_values(array_map('intval', \Shop::getShops(false, null, true))),
            $this->layoutConnections()
        );
    }

    public function layoutConnections(): LayoutConnections
    {
        return new LayoutConnections(
            $this->services->get(Connections::class),
            $this->services->get(Disconnector::class),
            $this->services->get(EngineSettings::class),
            $this->services->get(HistoryJobs::class)
        );
    }

    public function rss(): RssPanel
    {
        return new RssPanel(new RssSettings(), $this->translator);
    }

    /**
     * The card "Campaign Intelligence Automations" of the Automations tab.
     */
    public function engineAutomations(): EngineAutomationsPanel
    {
        return new EngineAutomationsPanel(
            new EngineAutomations(
                $this->services->get(Client::class),
                $this->services->get(Connections::class),
                $this->services->get(HealthCheck::class),
                $this->services->get(AccountStore::class),
                $this->services->get(WorkflowList::class)
            ),
            $this->layout(),
            fn (string $reason): string => $this->setup()->automations()->listError($reason),
            $this->translator
        );
    }

    private function layout(): StoreLayout
    {
        /** @var StoreLayout $layout */
        $layout = $this->services->get(StoreLayout::class);

        return $layout;
    }
}
