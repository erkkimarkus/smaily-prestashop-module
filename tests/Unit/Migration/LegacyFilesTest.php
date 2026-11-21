<?php
declare(strict_types=1);

namespace Tests\Unit\Migration;

use PHPUnit\Framework\TestCase;
use PrestaShop\Module\SmailyForPrestaShop\Migration\LegacyFiles;

/**
 * PrestaShop unpacks 3.0.0 over the module's folder and deletes nothing. The upgrade deletes the
 * files that 2.2.0 and 1.6.2 shipped and 3.0.0 does not: only those, only regular files in the
 * module's folder, and a file that cannot be deleted is reported, never an error.
 *
 * Every test works on a folder of its own under the system's temporary folder.
 */
final class LegacyFilesTest extends TestCase
{
    private const SOURCE = __DIR__ . '/../../../src';

    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/smaily-legacy-files-' . bin2hex(random_bytes(4));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        self::removeTree($this->dir);
    }

    public function testTheListIsTheFilesOf220And162That300DoesNotShip(): void
    {
        self::assertCount(47, LegacyFiles::FILES);
        $sorted = LegacyFiles::FILES;
        sort($sorted);
        self::assertSame($sorted, LegacyFiles::FILES, 'sorted, so a path is easy to find');
        self::assertSame(array_values(array_unique(LegacyFiles::FILES)), LegacyFiles::FILES);
        foreach (LegacyFiles::FILES as $path) {
            self::assertMatchesRegularExpression('#^(?!vendor/|translations/)[A-Za-z0-9_.-]+(/[A-Za-z0-9_.-]+)*$#', $path);
            self::assertStringNotContainsString('..', $path);
        }
    }

    /**
     * 3.0.0 ships none of the listed paths: the step finds nothing in a fresh install, and it can
     * delete nothing from a checkout of the module that a development shop mounts.
     */
    public function testTheModuleItselfHoldsNoListedFileAndNoListedFolder(): void
    {
        foreach (array_merge(LegacyFiles::FILES, LegacyFiles::DIRECTORIES) as $path) {
            self::assertFileDoesNotExist(self::SOURCE . '/' . $path);
        }
    }

    public function testEveryListedFolderHoldsOnlyListedFilesAndComesAfterTheFoldersInIt(): void
    {
        foreach (LegacyFiles::DIRECTORIES as $index => $directory) {
            self::assertNotEmpty(preg_grep('#^' . preg_quote($directory, '#') . '/[^/]+$#', LegacyFiles::FILES), $directory);
            foreach (array_slice(LegacyFiles::DIRECTORIES, $index + 1) as $later) {
                self::assertStringStartsNotWith($directory . '/', $later, $later . ' lies in ' . $directory);
            }
        }
    }

    public function testDeletesEveryListedFileAndEmptiedFolderAndNothingElse(): void
    {
        $this->seed(LegacyFiles::FILES);
        $kept = [
            'smailyforprestashop.php',
            'src/Controller/RssFeedController.php',
            'src/Model/RssFeedProductsCollection.php',
            'translations/et.php',
            'upgrade/upgrade-3.0.0.php',
            'vendor/autoload.php',
            'views/js/index.php',
            'views/templates/admin/index.php',
        ];
        $this->seed($kept);

        self::assertSame([], (new LegacyFiles($this->dir))->remove());

        self::assertSame($kept, $this->files());
        foreach (LegacyFiles::DIRECTORIES as $directory) {
            self::assertDirectoryDoesNotExist($this->dir . '/' . $directory);
        }
        self::assertDirectoryExists($this->dir . '/src/Model');
    }

    public function testAListedFolderThatHoldsAnotherFileStays(): void
    {
        $this->seed(['lib/Api.php', 'lib/index.php', 'lib/MerchantsOwn.php']);

        self::assertSame([], (new LegacyFiles($this->dir))->remove());

        self::assertSame(['lib/MerchantsOwn.php'], $this->files());
    }

    public function testAModuleFolderWithoutLegacyFilesIsLeftAsItIs(): void
    {
        $this->seed(['smailyforprestashop.php', 'src/Migration/LegacyFiles.php']);

        self::assertSame([], (new LegacyFiles($this->dir))->remove());
        self::assertSame([], (new LegacyFiles($this->dir . '/no-such-folder'))->remove());

        self::assertSame(['smailyforprestashop.php', 'src/Migration/LegacyFiles.php'], $this->files());
    }

    /**
     * A listed path that is a link, or lies under a folder that is a link, leads out of the
     * module's folder: nothing is deleted there, and the link stays.
     */
    public function testNeverFollowsALinkOutOfTheModulesFolder(): void
    {
        $outside = $this->dir . '-outside';
        mkdir($outside . '/lib', 0777, true);
        file_put_contents($outside . '/lib/Api.php', "<?php\n");
        file_put_contents($outside . '/smaily-admin.js', '');
        mkdir($this->dir . '/views/js', 0777, true);
        try {
            symlink($outside . '/lib', $this->dir . '/lib');
            symlink($outside . '/smaily-admin.js', $this->dir . '/views/js/smaily-admin.js');

            self::assertSame([], (new LegacyFiles($this->dir))->remove());

            self::assertFileExists($outside . '/lib/Api.php');
            self::assertFileExists($outside . '/smaily-admin.js');
            self::assertTrue(is_link($this->dir . '/lib'));
            self::assertTrue(is_link($this->dir . '/views/js/smaily-admin.js'));
        } finally {
            self::removeTree($outside);
        }
    }

    public function testAFileThatCannotBeDeletedIsReportedAndTheOthersGo(): void
    {
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            self::markTestSkipped('root deletes from a read-only folder');
        }
        $this->seed(LegacyFiles::FILES);
        chmod($this->dir . '/lib', 0555);

        $left = (new LegacyFiles($this->dir))->remove();

        self::assertSame(['lib/Api.php', 'lib/Logger.php', 'lib/Rss.php', 'lib/index.php'], $left);
        self::assertSame($left, $this->files());
    }

    /**
     * @param list<string> $paths
     */
    private function seed(array $paths): void
    {
        foreach ($paths as $path) {
            $file = $this->dir . '/' . $path;
            if (!is_dir(dirname($file))) {
                mkdir(dirname($file), 0777, true);
            }
            file_put_contents($file, "<?php\n");
        }
    }

    /**
     * @return list<string> every file under the folder, relative to it
     */
    private function files(): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->dir, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            $files[] = substr($file->getPathname(), strlen($this->dir) + 1);
        }
        sort($files);

        return $files;
    }

    private static function removeTree(string $dir): void
    {
        if (is_link($dir) || !is_dir($dir)) {
            return;
        }
        chmod($dir, 0755);
        foreach (array_diff((array) scandir($dir), ['.', '..']) as $name) {
            $path = $dir . '/' . $name;
            if (is_dir($path) && !is_link($path)) {
                self::removeTree($path);
            } else {
                unlink($path);
            }
        }
        rmdir($dir);
    }
}
