<?php
declare(strict_types=1);

namespace Tests\Unit\Engine\Payload;

use PHPUnit\Framework\TestCase;
use PrestaShop\Module\SmailyForPrestaShop\Engine\Payload\Keys;

/**
 * The keys of ARCHITECTURE 8.2: the connection's prefix as it is stored, then the platform key.
 * The platform namespace is proposed and not yet agreed with the engine team (Linear INT-306), so
 * its literal lives in one constant and nowhere else in the module.
 */
final class KeysTest extends TestCase
{
    /**
     * @dataProvider keys
     */
    public function testEveryKeyCarriesTheConnectionPrefixAsItIsStored(string $prefix): void
    {
        self::assertSame($prefix . Keys::PLATFORM_NAMESPACE . '12', Keys::sku($prefix, 12));
        self::assertSame($prefix . Keys::PLATFORM_NAMESPACE . '12-34', Keys::sku($prefix, 12, 34));
        self::assertSame($prefix . '12', Keys::productId($prefix, 12));
        self::assertSame($prefix . '501', Keys::orderId($prefix, 501));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function keys(): iterable
    {
        yield 'no prefix' => [''];
        yield 'a prefix' => ['c2-'];
        yield 'the longest prefix' => ['c9999-'];
    }

    public function testTheKeysOfTheContractsForm(): void
    {
        self::assertSame('ps-', Keys::PLATFORM_NAMESPACE);
        self::assertSame('c2-ps-12-34', Keys::sku('c2-', 12, 34));
        self::assertSame('ps-12', Keys::sku('', 12));
        self::assertSame('c2-12', Keys::productId('c2-', 12));
    }

    public function testTheTraceIdCarriesNoPrefixAndNoNamespace(): void
    {
        self::assertSame('12', Keys::externalId(12));
        self::assertSame('12-34', Keys::externalId(12, 34));
    }

    public function testAKeyStaysWithinTheContractsLength(): void
    {
        // Contract "Base context": a platform key of at most 64 characters, 72 with the prefix.
        $longest = Keys::sku('c9999-', PHP_INT_MAX, PHP_INT_MAX);

        self::assertLessThanOrEqual(72, strlen($longest));
        self::assertLessThanOrEqual(64, strlen(Keys::sku('', PHP_INT_MAX, PHP_INT_MAX)));
    }

    public function testThePlatformNamespaceIsWrittenInOnePlaceOfTheModule(): void
    {
        $module = dirname(__DIR__, 4) . '/src/';
        $files = new \RecursiveIteratorIterator(new \RecursiveCallbackFilterIterator(
            new \RecursiveDirectoryIterator($module, \FilesystemIterator::SKIP_DOTS),
            static fn (\SplFileInfo $file): bool => $file->getFilename() !== 'vendor'
        ));

        $scanned = 0;
        $found = [];
        foreach ($files as $file) {
            if (!in_array($file->getExtension(), ['php', 'twig', 'tpl', 'js', 'css', 'xlf', 'xml', 'yml', 'yaml', 'json'], true)) {
                continue;
            }
            ++$scanned;
            // The literal as a word of its own: "steps-" and "shops-" are not it.
            if (preg_match('/(?<![A-Za-z0-9_])' . preg_quote(Keys::PLATFORM_NAMESPACE, '/') . '/', (string) file_get_contents($file->getPathname())) === 1) {
                $found[] = substr($file->getPathname(), strlen($module));
            }
        }

        self::assertGreaterThan(200, $scanned, 'the module\'s files were read');
        self::assertSame(['src/Engine/Payload/Keys.php'], $found, 'the namespace is not agreed with the engine team: one constant holds it');
    }
}
