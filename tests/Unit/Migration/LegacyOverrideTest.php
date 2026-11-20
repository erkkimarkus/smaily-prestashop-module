<?php
declare(strict_types=1);

namespace Tests\Unit\Migration;

use PHPUnit\Framework\TestCase;
use PrestaShop\Module\SmailyForPrestaShop\Migration\LegacyOverride;

/**
 * 2.x copied its override of ps_emailsubscription into the shop's override folder, and its uninstall
 * deleted that path whoever owned it. 3.0.0 removes the file only when it is a copy that a 2.x
 * release shipped.
 */
final class LegacyOverrideTest extends TestCase
{
    private const SHIPPED_COPY = __DIR__ . '/../../fixtures/legacy/override-2.x.ps_emailsubscription.php.txt';

    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/smaily-override-' . bin2hex(random_bytes(4));
        mkdir($this->dir . '/modules/ps_emailsubscription', 0777, true);
    }

    protected function tearDown(): void
    {
        @unlink($this->path());
        @rmdir($this->dir . '/modules/ps_emailsubscription');
        @rmdir($this->dir . '/modules');
        @rmdir($this->dir);
    }

    public function testRemovesTheCopyThatA2xReleaseShipped(): void
    {
        copy(self::SHIPPED_COPY, $this->path());

        self::assertSame(LegacyOverride::REMOVED, (new LegacyOverride($this->dir))->removeIfShipped());
        self::assertFileDoesNotExist($this->path());
        self::assertDirectoryDoesNotExist($this->dir . '/modules/ps_emailsubscription', 'the emptied folder 2.x created goes too');
        self::assertDirectoryExists($this->dir . '/modules');
    }

    public function testLeavesAFileWithOtherContentInPlace(): void
    {
        file_put_contents($this->path(), file_get_contents(self::SHIPPED_COPY) . "\n// changed by another module\n");

        self::assertSame(LegacyOverride::FOREIGN, (new LegacyOverride($this->dir))->removeIfShipped());
        self::assertFileExists($this->path());
    }

    public function testKeepsAFolderThatHoldsOtherFiles(): void
    {
        copy(self::SHIPPED_COPY, $this->path());
        touch($this->dir . '/modules/ps_emailsubscription/index.php');

        (new LegacyOverride($this->dir))->removeIfShipped();

        self::assertFileExists($this->dir . '/modules/ps_emailsubscription/index.php');
        unlink($this->dir . '/modules/ps_emailsubscription/index.php');
    }

    public function testReportsAMissingFile(): void
    {
        self::assertSame(LegacyOverride::ABSENT, (new LegacyOverride($this->dir))->removeIfShipped());
    }

    private function path(): string
    {
        return $this->dir . '/modules/ps_emailsubscription/ps_emailsubscription.php';
    }
}
