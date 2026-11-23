<?php
declare(strict_types=1);

namespace Tests\Unit\Storefront;

use PHPUnit\Framework\TestCase;
use PrestaShop\Module\SmailyForPrestaShop\Scheduler\Deadline;

require_once __DIR__ . '/StorefrontFixture.php';

/**
 * The storefront's cache of short-lived values, and the per-address limit that counts in it.
 */
final class StoreTest extends TestCase
{
    private StorefrontFixture $fixture;

    protected function setUp(): void
    {
        $this->fixture = new StorefrontFixture();
    }

    public function testAValueReadsBackUntilItExpires(): void
    {
        $store = $this->fixture->store();

        self::assertNull($store->get('a'));
        $store->set('a', 'one', 60);
        self::assertSame('one', $store->get('a'));

        $this->fixture->now += 59;
        self::assertSame('one', $store->get('a'));
        $this->fixture->now += 1;
        self::assertNull($store->get('a'), 'expired at its time');
    }

    public function testANewValueReplacesTheOldOneAndItsLifetime(): void
    {
        $store = $this->fixture->store();
        $store->set('a', 'one', 60);
        $store->set('a', 'two', 600);

        $this->fixture->now += 300;
        self::assertSame('two', $store->get('a'));
    }

    public function testAFileIsNamedByAHashAndHoldsNoKey(): void
    {
        $this->fixture->store()->set('limit:relay:192.0.2.10', '1', 60);

        $files = array_map('basename', glob($this->fixture->directory() . '/*') ?: []);
        self::assertSame([hash('sha256', 'limit:relay:192.0.2.10')], $files);
        self::assertSame('1', file_get_contents($this->fixture->directory() . '/' . $files[0]));
    }

    public function testPruneDeletesTheExpiredFilesOnly(): void
    {
        $store = $this->fixture->store();
        $store->set('short', '1', 60);
        $store->set('long', '1', 3600);
        $this->fixture->now += 120;

        self::assertSame(1, $store->prune(new Deadline(microtime(true) + 5, static fn (): float => microtime(true))));
        self::assertNull($store->get('short'));
        self::assertSame('1', $store->get('long'));
        self::assertCount(1, glob($this->fixture->directory() . '/*') ?: []);
    }

    public function testPruneStopsAtItsDeadlineAndAMissingFolderIsEmpty(): void
    {
        $store = $this->fixture->store();
        self::assertSame(0, $store->prune(new Deadline(microtime(true) + 5, static fn (): float => microtime(true))));

        $store->set('short', '1', 60);
        $this->fixture->now += 120;
        self::assertSame(0, $store->prune(new Deadline(0.0, static fn (): float => 1.0)), 'the deadline has passed');
    }

    public function testClearDeletesEveryFileAndTheFolder(): void
    {
        $store = $this->fixture->store();
        $store->set('expired', '1', 60);
        $store->set('valid', '1', 3600);
        $this->fixture->now += 120;

        $store->clear();

        self::assertDirectoryDoesNotExist($this->fixture->directory());
        self::assertNull($store->get('valid'));
        $store->clear();
        self::assertDirectoryDoesNotExist($this->fixture->directory(), 'a missing folder is nothing to clear');

        $store->set('again', '1', 60);
        self::assertSame('1', $store->get('again'), 'the folder is made again when a value is stored');
    }

    public function testAFolderThatCannotBeMadeReadsAsEmptyAndNothingFails(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'smaily-not-a-folder-');
        $store = new \PrestaShop\Module\SmailyForPrestaShop\Storefront\Store($file . '/below-a-file', static fn (): int => StorefrontFixture::NOW);

        $store->set('a', 'one', 60);

        self::assertNull($store->get('a'));
        unlink((string) $file);
    }

    public function testAnAddressIsAllowedItsLimitInAWindowAndAgainInTheNext(): void
    {
        $limiter = $this->fixture->limiter();

        for ($i = 0; $i < 3; ++$i) {
            self::assertTrue($limiter->allow('relay', 3), 'request ' . ($i + 1));
        }
        self::assertFalse($limiter->allow('relay', 3));
        self::assertFalse($limiter->allow('relay', 3), 'a refused request is not counted, and stays refused');

        $this->fixture->now += 60;
        self::assertTrue($limiter->allow('relay', 3), 'the next minute starts a new count');
    }

    public function testEachAddressAndEachEndpointHasItsOwnCount(): void
    {
        $limiter = $this->fixture->limiter();
        self::assertTrue($limiter->allow('relay', 1));
        self::assertFalse($limiter->allow('relay', 1));

        self::assertTrue($limiter->allow('land', 1), 'another endpoint');
        $this->fixture->address = '192.0.2.11';
        self::assertTrue($limiter->allow('relay', 1), 'another address');
    }

    public function testNoFileNameOrContentHoldsTheAddress(): void
    {
        $this->fixture->limiter()->allow('relay', 5);

        foreach (glob($this->fixture->directory() . '/*') ?: [] as $file) {
            self::assertStringNotContainsString('192.0.2.10', basename($file));
            self::assertStringNotContainsString('192.0.2.10', (string) file_get_contents($file));
        }
    }
}
