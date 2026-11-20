<?php
declare(strict_types=1);

namespace Tests\Unit\Automation;

use PHPUnit\Framework\TestCase;
use PrestaShop\Module\SmailyForPrestaShop\Automation\AbandonedCart\AbandonedCartAutomation;
use PrestaShop\Module\SmailyForPrestaShop\Automation\FirstOrder\FirstOrderAutomation;
use PrestaShop\Module\SmailyForPrestaShop\Automation\MappingRepository;
use PrestaShop\Module\SmailyForPrestaShop\Automation\Router;
use PrestaShop\Module\SmailyForPrestaShop\Automation\TriggerSender;
use PrestaShop\Module\SmailyForPrestaShop\Automation\Welcome\WelcomeAutomation;
use PrestaShop\Module\SmailyForPrestaShop\Automation\WorkflowList;
use PrestaShop\Module\SmailyForPrestaShop\Automation\WorkflowResolverInterface;
use PrestaShop\Module\SmailyForPrestaShop\Config\AutomationSettings;
use PrestaShop\Module\SmailyForPrestaShop\Runtime\Provider\AutomationProvider;
use PrestaShop\Module\SmailyForPrestaShop\Runtime\Provider\ProviderInterface;
use PrestaShop\Module\SmailyForPrestaShop\Runtime\Provider\ScopeProvider;
use PrestaShop\Module\SmailyForPrestaShop\Runtime\Provider\SmailyProvider;
use PrestaShop\Module\SmailyForPrestaShop\Runtime\Services;
use PrestaShop\Module\SmailyForPrestaShop\Smaily\Crypto;

/**
 * The automation provider registers the routing services and lets each store automation register
 * its own, from a class of its own.
 */
final class AutomationProviderTest extends TestCase
{
    public function testCallsTheRegistrationOfEachStoreAutomation(): void
    {
        self::assertSame([WelcomeAutomation::class, FirstOrderAutomation::class, AbandonedCartAutomation::class], AutomationProvider::AUTOMATIONS);
        foreach (AutomationProvider::AUTOMATIONS as $automation) {
            self::assertTrue(is_a($automation, ProviderInterface::class, true));
        }
    }

    public function testRegistersTheRoutingServices(): void
    {
        $services = Services::fromProviders([SmailyProvider::class, ScopeProvider::class, AutomationProvider::class]);
        $services->set(Crypto::class, fn (): Crypto => new Crypto('not-a-real-key'));

        self::assertInstanceOf(AutomationSettings::class, $services->get(AutomationSettings::class));
        self::assertInstanceOf(MappingRepository::class, $services->get(MappingRepository::class));
        self::assertInstanceOf(Router::class, $services->get(Router::class));
        self::assertSame($services->get(Router::class), $services->get(WorkflowResolverInterface::class));
        self::assertInstanceOf(TriggerSender::class, $services->get(TriggerSender::class));
        self::assertInstanceOf(WorkflowList::class, $services->get(WorkflowList::class));
    }
}
