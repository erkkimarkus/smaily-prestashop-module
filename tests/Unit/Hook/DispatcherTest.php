<?php
declare(strict_types=1);

namespace Tests\Unit\Hook;

use PHPUnit\Framework\TestCase;
use PrestaShop\Module\SmailyForPrestaShop\Hook\Dispatcher;
use PrestaShop\Module\SmailyForPrestaShop\Runtime\Services;

/**
 * The dispatcher hands a hook call to the listeners that the providers registered, and only while
 * the stored schema version is the one the code needs.
 */
final class DispatcherTest extends TestCase
{
    /** @var list<array{string, \Throwable}> */
    private array $failures = [];

    public function testCallsNoListenerWhileTheSchemaIsNotCurrent(): void
    {
        $services = new Services();
        $ran = false;
        $services->listen('actionCartSave', function () use (&$ran): string {
            $ran = true;

            return 'ran';
        });

        $result = $this->dispatcher(false, $services)->run('actionCartSave', ['cart' => 1]);

        self::assertFalse($ran);
        self::assertNull($result);
    }

    public function testDoesNotBuildTheServicesWhileTheSchemaIsNotCurrent(): void
    {
        $dispatcher = new Dispatcher(
            fn (): bool => false,
            function (): Services {
                self::fail('The services were built while the schema was not current.');
            },
            fn (): bool => true
        );

        self::assertNull($dispatcher->run('displayBackOfficeHeader', []));
    }

    public function testPassesTheHookParametersToEachListener(): void
    {
        $services = new Services();
        $seen = [];
        $services->listen('actionCartSave', function (array $params) use (&$seen): void {
            $seen[] = $params;
        });
        $services->listen('actionCartSave', function (array $params) use (&$seen): void {
            $seen[] = $params;
        });

        $this->dispatcher(true, $services)->run('actionCartSave', ['cart' => 7]);

        self::assertSame([['cart' => 7], ['cart' => 7]], $seen);
    }

    public function testJoinsTheOutputOfDisplayListeners(): void
    {
        $services = new Services();
        $services->listen('displayBackOfficeHeader', fn (): string => '<a>');
        $services->listen('displayBackOfficeHeader', fn (): string => '<b>');

        self::assertSame('<a><b>', $this->dispatcher(true, $services)->run('displayBackOfficeHeader', []));
    }

    public function testMergesTheArraysOfListeners(): void
    {
        $services = new Services();
        $services->listen('moduleRoutes', fn (): array => ['relay' => ['controller' => 'relay']]);
        $services->listen('moduleRoutes', fn (): array => ['land' => ['controller' => 'land']]);
        $services->listen('moduleRoutes', fn () => null);

        self::assertSame(
            ['relay' => ['controller' => 'relay'], 'land' => ['controller' => 'land']],
            $this->dispatcher(true, $services)->run('moduleRoutes', [])
        );
    }

    public function testReturnsNullForAHookWithoutListeners(): void
    {
        self::assertNull($this->dispatcher(true, new Services())->run('actionValidateOrder', []));
    }

    public function testAListenerThatThrowsFailsAloneAndIsReported(): void
    {
        $services = new Services();
        $error = new \RuntimeException('broken');
        $services->listen('displayCustomerAccount', function () use ($error): string {
            throw $error;
        });
        $services->listen('displayCustomerAccount', fn (): string => '<li>');

        $result = $this->dispatcher(true, $services)->run('displayCustomerAccount', []);

        self::assertSame('<li>', $result);
        self::assertSame([['displayCustomerAccount', $error]], $this->failures);
    }

    private function dispatcher(bool $schemaIsCurrent, Services $services): Dispatcher
    {
        return new Dispatcher(
            fn (): bool => $schemaIsCurrent,
            fn (): Services => $services,
            function (string $hook, \Throwable $error): void {
                $this->failures[] = [$hook, $error];
            }
        );
    }
}
