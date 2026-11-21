<?php
declare(strict_types=1);

namespace Tests\Unit\Database;

use PHPUnit\Framework\TestCase;

/**
 * Every database read and write of the module goes through its gateway (Database\Sql), which throws
 * on a failed query. PrestaShop's own Db answers `false` for a failed query on a live 9.2.0 store
 * (facts file 6.11), and code that calls it directly reads that as "no row" sooner or later.
 */
final class NoDirectDatabaseCallTest extends TestCase
{
    /** The one file that may name PrestaShop's Db: the gateway itself. */
    private const GATEWAY = 'src/Database/Sql.php';

    /**
     * Files that hand PrestaShop's Db instance to a core API that takes one, by path under the
     * module folder, each with its reason. None does today.
     *
     * @var array<string, string>
     */
    private const ALLOWED = [];

    public function testNoFileOfTheModuleCallsPrestaShopsDbDirectly(): void
    {
        $module = dirname(__DIR__, 3) . '/src/';
        $files = new \RecursiveIteratorIterator(new \RecursiveCallbackFilterIterator(
            new \RecursiveDirectoryIterator($module, \FilesystemIterator::SKIP_DOTS),
            static fn (\SplFileInfo $file): bool => $file->getFilename() !== 'vendor'
        ));

        $scanned = 0;
        $calls = [];
        foreach ($files as $file) {
            $path = substr($file->getPathname(), strlen($module));
            if ($file->getExtension() !== 'php' || $path === self::GATEWAY || isset(self::ALLOWED[$path])) {
                continue;
            }
            ++$scanned;
            foreach (token_get_all((string) file_get_contents($file->getPathname())) as $token) {
                // The class name as code, with or without its leading backslash; a comment that
                // names it is no call.
                if (is_array($token) && in_array($token[0], [T_STRING, T_NAME_FULLY_QUALIFIED], true) && ltrim($token[1], '\\') === 'Db') {
                    $calls[] = $path . ':' . $token[2];
                }
            }
        }

        self::assertGreaterThan(150, $scanned, 'the module\'s files were read');
        self::assertSame([], $calls, 'Database\\Sql is the only code that calls PrestaShop\'s Db');
        foreach (array_keys(self::ALLOWED) as $path) {
            self::assertFileExists($module . $path);
        }
    }
}
