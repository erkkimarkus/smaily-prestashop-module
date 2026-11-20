<?php
declare(strict_types=1);

namespace Tests\Unit\Install;

use PHPUnit\Framework\TestCase;
use PrestaShop\Module\SmailyForPrestaShop\Install\HookList;

/**
 * The main class has one method per registered hook, and each method hands its own hook to the
 * dispatcher. The main class extends PrestaShop's Module, so the test reads its source.
 */
final class HookListTest extends TestCase
{
    public function testEveryHookIsListedOnce(): void
    {
        self::assertSame(array_values(array_unique(HookList::HOOKS)), HookList::HOOKS);
    }

    public function testTheMainClassDelegatesEachRegisteredHookToTheDispatcher(): void
    {
        $source = (string) file_get_contents(__DIR__ . '/../../../src/smailyforprestashop.php');
        preg_match_all(
            '/public function hook(\w+)\(array \$params\)\s*\{\s*return Dispatcher::dispatch\(\'(\w+)\', \$params\);\s*\}/',
            $source,
            $methods,
            PREG_SET_ORDER
        );

        $delegated = [];
        foreach ($methods as [, $method, $hook]) {
            self::assertSame(ucfirst($hook), $method, 'hook' . $method . ' dispatches ' . $hook);
            $delegated[] = $hook;
        }
        self::assertSame(HookList::HOOKS, $delegated);
        self::assertSame(count(HookList::HOOKS), preg_match_all('/function hook\w+\(/', $source));
    }
}
