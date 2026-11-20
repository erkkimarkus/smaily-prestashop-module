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

namespace PrestaShop\Module\SmailyForPrestaShop\Runtime\Provider;

if (!defined('_PS_VERSION_')) {
    exit;
}

use PrestaShop\Module\SmailyForPrestaShop\Automation\AbandonedCart\AbandonedCartAutomation;
use PrestaShop\Module\SmailyForPrestaShop\Automation\FirstOrder\FirstOrderAutomation;
use PrestaShop\Module\SmailyForPrestaShop\Automation\MappingRepository;
use PrestaShop\Module\SmailyForPrestaShop\Automation\Router;
use PrestaShop\Module\SmailyForPrestaShop\Automation\TriggerSender;
use PrestaShop\Module\SmailyForPrestaShop\Automation\Welcome\WelcomeAutomation;
use PrestaShop\Module\SmailyForPrestaShop\Automation\WorkflowList;
use PrestaShop\Module\SmailyForPrestaShop\Automation\WorkflowResolverInterface;
use PrestaShop\Module\SmailyForPrestaShop\Config\AutomationSettings;
use PrestaShop\Module\SmailyForPrestaShop\Http\Transport;
use PrestaShop\Module\SmailyForPrestaShop\Runtime\Services;
use PrestaShop\Module\SmailyForPrestaShop\Scope\StoreLayout;
use PrestaShop\Module\SmailyForPrestaShop\Smaily\AccountStore;

/**
 * Registers the services of the store automations' routing: the Automations settings, the mapping
 * rows, the router, the trigger sender and the workflow list. Each store automation then registers
 * its own services and listeners from a class of its own, listed in AUTOMATIONS.
 */
final class AutomationProvider implements ProviderInterface
{
    /**
     * @var list<class-string<ProviderInterface>>
     */
    public const AUTOMATIONS = [
        WelcomeAutomation::class,
        FirstOrderAutomation::class,
        AbandonedCartAutomation::class,
    ];

    public function register(Services $services): void
    {
        $services->set(AutomationSettings::class, fn (): AutomationSettings => new AutomationSettings());
        $services->set(MappingRepository::class, fn (): MappingRepository => new MappingRepository());
        $services->set(Router::class, fn (Services $services): Router => new Router(
            $services->get(AutomationSettings::class),
            $services->get(StoreLayout::class),
            $services->get(MappingRepository::class),
            [$services->get(AccountStore::class), 'find']
        ));
        $services->set(WorkflowResolverInterface::class, fn (Services $services): Router => $services->get(Router::class));
        $services->set(TriggerSender::class, fn (Services $services): TriggerSender => new TriggerSender(
            $services->get(Router::class),
            $services->get(Transport::class)
        ));
        $services->set(WorkflowList::class, fn (Services $services): WorkflowList => new WorkflowList($services->get(Transport::class)));

        foreach (self::AUTOMATIONS as $automation) {
            (new $automation())->register($services);
        }
    }
}
