<?php
declare(strict_types=1);

namespace Tests\Unit\Runtime;

use PHPUnit\Framework\TestCase;
use PrestaShop\Module\SmailyForPrestaShop\Runtime\Provider\ProviderInterface;
use PrestaShop\Module\SmailyForPrestaShop\Runtime\Services;

/**
 * The composition root builds each runtime service once, on first use, from the factory a
 * provider registered.
 */
final class ServicesTest extends TestCase
{
    public function testBuildsAServiceOnFirstUseAndSharesIt(): void
    {
        $services = new Services();
        $built = 0;
        $services->set('clock', function () use (&$built): \stdClass {
            ++$built;

            return new \stdClass();
        });

        self::assertSame(0, $built);
        self::assertSame($services->get('clock'), $services->get('clock'));
        self::assertSame(1, $built);
    }

    public function testPassesItselfToAFactory(): void
    {
        $services = new Services();
        $services->set('a', fn (): \ArrayObject => new \ArrayObject());
        $services->set('b', fn (Services $s): \ArrayObject => new \ArrayObject([$s->get('a')]));

        self::assertSame($services->get('a'), $services->get('b')[0]);
    }

    public function testRefusesAnUnknownService(): void
    {
        $this->expectException(\OutOfBoundsException::class);

        (new Services())->get('missing');
    }

    public function testListsTheListenersOfAHookInRegistrationOrder(): void
    {
        $services = new Services();
        $first = fn (): string => 'first';
        $second = fn (): string => 'second';
        $services->listen('actionCartSave', $first);
        $services->listen('actionCartSave', $second);

        self::assertSame([$first, $second], $services->listeners('actionCartSave'));
        self::assertSame([], $services->listeners('actionValidateOrder'));
    }

    public function testAListenerCanGoAheadOfTheOnesRegisteredBeforeIt(): void
    {
        $services = new Services();
        $first = fn (): string => 'first';
        $second = fn (): string => 'second';
        $ahead = fn (): string => 'ahead';
        $services->listenFirst('smailyConnectBeforeUninstall', $first);
        $services->listen('smailyConnectBeforeUninstall', $second);
        $services->listenFirst('smailyConnectBeforeUninstall', $ahead);

        self::assertSame([$ahead, $first, $second], $services->listeners('smailyConnectBeforeUninstall'));
    }

    public function testAListenerWaitsForTheUpgradeUnlessItIsRegisteredToRunBeforeIt(): void
    {
        $services = new Services();
        $inert = fn (): string => 'inert';
        $early = fn (): string => 'early';
        $services->listen('actionFrontControllerSetMedia', $inert);
        $services->listen('actionFrontControllerSetMedia', $early, true);

        self::assertSame([$inert, $early], $services->listeners('actionFrontControllerSetMedia'));
        self::assertSame([$early], $services->listenersBeforeUpgrade('actionFrontControllerSetMedia'));
        self::assertSame([], $services->listenersBeforeUpgrade('actionCartSave'));
    }

    public function testLetsEveryProviderRegister(): void
    {
        $services = Services::fromProviders([RegistersAService::class]);

        self::assertSame('registered', $services->get('probe')->value);
    }

    public function testEveryAreaProviderLoadsAndRegisters(): void
    {
        $services = Services::fromProviders(Services::PROVIDERS);

        self::assertInstanceOf(Services::class, $services);
        foreach (Services::PROVIDERS as $provider) {
            self::assertTrue(is_subclass_of($provider, ProviderInterface::class), $provider);
        }
    }
}

final class RegistersAService implements ProviderInterface
{
    public function register(Services $services): void
    {
        $services->set('probe', function (): \stdClass {
            $probe = new \stdClass();
            $probe->value = 'registered';

            return $probe;
        });
    }
}
