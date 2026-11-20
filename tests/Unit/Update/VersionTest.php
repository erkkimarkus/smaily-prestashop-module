<?php
declare(strict_types=1);

namespace Tests\Unit\Update;

use PHPUnit\Framework\TestCase;
use PrestaShop\Module\SmailyForPrestaShop\Update\Version;

/**
 * A release tag counts only as a plain version number, and versions compare as numbers, not as text.
 */
final class VersionTest extends TestCase
{
    /**
     * @dataProvider plainTags
     */
    public function testReadsAPlainVersionFromATag(string $tag, string $version): void
    {
        self::assertSame($version, Version::fromTag($tag));
    }

    /**
     * @return iterable<array{string, string}>
     */
    public static function plainTags(): iterable
    {
        yield 'three numbers' => ['3.1.0', '3.1.0'];
        yield 'a leading v' => ['v3.1.0', '3.1.0'];
        yield 'two-digit parts' => ['3.0.10', '3.0.10'];
        yield 'two numbers' => ['3.1', '3.1'];
    }

    /**
     * @dataProvider otherTags
     */
    public function testIgnoresATagThatIsNoPlainVersion(string $tag): void
    {
        self::assertNull(Version::fromTag($tag));
    }

    /**
     * @return iterable<array{string}>
     */
    public static function otherTags(): iterable
    {
        yield 'a release candidate' => ['3.1.0-rc1'];
        yield 'a beta' => ['v3.1.0-beta'];
        yield 'a name' => ['latest'];
        yield 'empty' => [''];
        yield 'one number' => ['3'];
        yield 'an upper-case V' => ['V3.1.0'];
        yield 'a space' => [' 3.1.0'];
        yield 'a trailing newline' => ["3.1.0\n"];
        yield 'a path' => ['3.1.0/../x'];
    }

    /**
     * @dataProvider newer
     */
    public function testComparesVersionsAsNumbers(string $candidate, string $installed, bool $isNewer): void
    {
        self::assertSame($isNewer, Version::isNewer($candidate, $installed));
    }

    /**
     * @return iterable<array{string, string, bool}>
     */
    public static function newer(): iterable
    {
        yield '3.0.10 is newer than 3.0.9' => ['3.0.10', '3.0.9', true];
        yield '3.0.9 is older than 3.0.10' => ['3.0.9', '3.0.10', false];
        yield 'a release is newer than its release candidate' => ['3.0.0', '3.0.0-rc1', true];
        yield 'a release candidate is older than its release' => ['3.0.0-rc1', '3.0.0', false];
        yield 'equal' => ['3.0.0', '3.0.0', false];
        yield 'older major' => ['2.2.0', '3.0.0', false];
        yield 'newer minor' => ['3.1.0', '3.0.0', true];
    }
}
