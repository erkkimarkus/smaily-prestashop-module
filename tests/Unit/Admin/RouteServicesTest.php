<?php
declare(strict_types=1);

namespace Tests\Unit\Admin;

use PHPUnit\Framework\TestCase;

/**
 * PrestaShop 9 builds a module's admin controller only as a service: a route whose controller is
 * not listed in config/admin/services/ answers HTTP 500 there ("has no container set"), while
 * PrestaShop 8 serves it. Every controller that a route names is therefore a listed service.
 */
final class RouteServicesTest extends TestCase
{
    private const CONFIG = __DIR__ . '/../../../src/config';

    public function testEveryControllerOfARouteIsAListedService(): void
    {
        $controllers = [];
        foreach (array_merge([self::CONFIG . '/routes.yml'], (array) glob(self::CONFIG . '/routes/*.yml')) as $file) {
            preg_match_all('/^\s+_controller:\s*(\S+?)::\w+\s*$/m', (string) file_get_contents($file), $matches);
            $controllers = array_merge($controllers, $matches[1]);
        }
        $services = [];
        foreach ((array) glob(self::CONFIG . '/admin/services/*.yml') as $file) {
            preg_match_all('/^  (PrestaShop\\\\Module\\\\\S+):\s*$/m', (string) file_get_contents($file), $matches);
            $services = array_merge($services, $matches[1]);
        }

        self::assertGreaterThan(20, count($controllers), 'the routes were read');
        self::assertSame([], array_values(array_diff(array_unique($controllers), $services)), 'a route\'s controller that is no service');
        foreach (array_unique($controllers) as $class) {
            self::assertFileExists(__DIR__ . '/../../../src/src/' . str_replace('\\', '/', substr($class, strlen('PrestaShop\\Module\\SmailyForPrestaShop\\'))) . '.php');
        }
    }
}
