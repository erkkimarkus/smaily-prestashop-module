<?php
declare(strict_types=1);

namespace Tests\Integration\Hook;

use PrestaShop\Module\SmailyForPrestaShop\Install\HookList;
use PrestaShop\Module\SmailyForPrestaShop\Install\Schema;
use PrestaShop\Module\SmailyForPrestaShop\Runtime\Services;
use Tests\Integration\IntegrationTestCase;

/**
 * Every registered hook reaches the listeners only while the stored schema version is current.
 * Without it (files copied before PrestaShop ran the upgrade) a hook does nothing and raises no
 * error.
 */
final class SchemaGuardTest extends IntegrationTestCase
{
    /** @var array<string, int> */
    private static array $calls = [];

    private static bool $listening = false;

    protected function setUp(): void
    {
        parent::setUp();
        if (!self::$listening) {
            foreach (HookList::HOOKS as $hook) {
                Services::instance()->listen($hook, static function () use ($hook): void {
                    self::$calls[$hook] = (self::$calls[$hook] ?? 0) + 1;
                });
            }
            self::$listening = true;
        }
        self::$calls = [];
    }

    protected function tearDown(): void
    {
        if (defined('_PS_ROOT_DIR_')) {
            Schema::markCurrent();
        }
        parent::tearDown();
    }

    public function testEveryHookDoesNothingWhileTheSchemaVersionIsUnset(): void
    {
        \Configuration::deleteByName(Schema::VERSION_KEY);

        foreach (HookList::HOOKS as $hook) {
            $this->execute($hook);
        }

        self::assertSame([], self::$calls);
    }

    public function testEveryHookReachesItsListenersWhileTheSchemaIsCurrent(): void
    {
        Schema::markCurrent();

        foreach (HookList::HOOKS as $hook) {
            $this->execute($hook);
        }

        self::assertSame(array_fill_keys(HookList::HOOKS, 1), self::$calls);
    }

    /**
     * Calls the hook the way PrestaShop does, for this module only, and fails on any notice or
     * warning that the call raises (deprecations of the PHP version are PrestaShop's own).
     */
    private function execute(string $hook): void
    {
        set_error_handler(static function (int $level, string $message, string $file, int $line) use ($hook): bool {
            self::fail(sprintf('%s raised "%s" at %s:%d', $hook, $message, $file, $line));
        }, E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);
        try {
            \Hook::exec($hook, [], (int) self::module()->id, false, false);
        } finally {
            restore_error_handler();
        }
    }
}
