<?php
declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * A test opens a private property or method through IntegrationTestCase::accessible(), which calls
 * setAccessible() only on PHP 8.0. PHP 8.5 deprecates the call, and a direct one prints a notice
 * in every run there.
 */
final class ReflectionAccessTest extends TestCase
{
    /** The one file that may call setAccessible(): the helper itself. */
    private const HELPER = 'Integration/IntegrationTestCase.php';

    public function testNoTestCallsSetAccessibleDirectly(): void
    {
        $tests = dirname(__DIR__) . '/';
        $scanned = 0;
        $calls = [];
        foreach (['Unit', 'Integration'] as $suite) {
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($tests . $suite, \FilesystemIterator::SKIP_DOTS)) as $file) {
                $path = substr($file->getPathname(), strlen($tests));
                if ($file->getExtension() !== 'php' || $path === self::HELPER) {
                    continue;
                }
                ++$scanned;
                foreach (token_get_all((string) file_get_contents($file->getPathname())) as $token) {
                    if (is_array($token) && $token[0] === T_STRING && $token[1] === 'setAccessible') {
                        $calls[] = $path . ':' . $token[2];
                    }
                }
            }
        }

        self::assertGreaterThan(150, $scanned, 'the tests were read');
        self::assertSame([], $calls, 'IntegrationTestCase::accessible() is the only code that calls setAccessible()');
    }
}
