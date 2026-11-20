<?php
declare(strict_types=1);

namespace Tests\Unit\Install;

use PHPUnit\Framework\TestCase;
use PrestaShop\Module\SmailyForPrestaShop\Install\Uninstaller;
use PrestaShop\Module\SmailyForPrestaShop\Migration\LegacyState;

/**
 * Uninstall removes every configuration key of the module. The list is explicit (the legacy prefix
 * SMAILY_ also matches the 3.0.0 prefix), so a key that the code names and the list misses would
 * survive an uninstall.
 */
final class UninstallerTest extends TestCase
{
    public function testListsEveryKeyThatTheModuleCodeNames(): void
    {
        $named = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(__DIR__ . '/../../../src/src'));
        foreach ($files as $file) {
            if ($file->getExtension() === 'php') {
                preg_match_all('/SMAILY_CONNECT_[A-Z_]*[A-Z]/', (string) file_get_contents($file->getPathname()), $matches);
                $named = array_merge($named, $matches[0]);
            }
        }
        $named = array_unique($named);
        sort($named);

        self::assertNotEmpty($named);
        self::assertSame([], array_values(array_diff($named, Uninstaller::KEYS)));
    }

    public function testListsEveryLegacyKey(): void
    {
        self::assertSame([], array_values(array_diff(LegacyState::KEYS, Uninstaller::KEYS)));
    }
}
