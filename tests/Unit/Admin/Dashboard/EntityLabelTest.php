<?php
declare(strict_types=1);

namespace Tests\Unit\Admin\Dashboard;

use PHPUnit\Framework\TestCase;
use PrestaShop\Module\SmailyForPrestaShop\Admin\Dashboard\EntityLabel;

/**
 * The Dashboard never shows a queue row's address: a row whose entity is an address, or the keyed
 * hash that stands in for a long one, shows the first 12 characters of a keyed hash, as Magento shows
 * a hashed entity. One address shows the same label on every row, whatever its case and spaces.
 */
final class EntityLabelTest extends TestCase
{
    private const KEY = 'test-key';

    public function testAnAddressShowsAsTwelveCharactersOfItsKeyedHash(): void
    {
        $label = EntityLabel::forDisplay('shopper1@example.test', self::KEY);

        self::assertMatchesRegularExpression('/^[0-9a-f]{12}…$/u', $label);
        self::assertStringNotContainsString('shopper1', $label);
        self::assertStringNotContainsString('example', $label);
        self::assertSame(substr(hash_hmac('sha256', 'shopper1@example.test', self::KEY), 0, 12) . '…', $label);
    }

    public function testOneAddressShowsOneLabel(): void
    {
        self::assertSame(
            EntityLabel::forDisplay('shopper1@example.test', self::KEY),
            EntityLabel::forDisplay(' Shopper1@Example.TEST ', self::KEY)
        );
        self::assertNotSame(
            EntityLabel::forDisplay('shopper1@example.test', self::KEY),
            EntityLabel::forDisplay('shopper2@example.test', self::KEY)
        );
    }

    public function testAStoredHashShowsItsFirstTwelveCharacters(): void
    {
        $hash = str_repeat('ab12', 16);

        self::assertSame('ab12ab12ab12…', EntityLabel::forDisplay($hash, self::KEY));
    }

    public function testARowWithoutAnEntityShowsADash(): void
    {
        self::assertSame('—', EntityLabel::forDisplay(null, self::KEY));
        self::assertSame('—', EntityLabel::forDisplay('', self::KEY));
    }

    public function testAnEntityThatIsNoAddressShowsAsStored(): void
    {
        self::assertSame('1042', EntityLabel::forDisplay('1042', self::KEY));
    }
}
