<?php
declare(strict_types=1);

namespace Tests\Unit\Admin\Dashboard;

use PHPUnit\Framework\TestCase;
use PrestaShop\Module\SmailyForPrestaShop\Admin\Dashboard\EntityLabel;

/**
 * The Dashboard shows a queue row's entity as stored, a contact's address included, as Magento
 * does. Only the keyed hash that stands in for a long address is cut to its first 12 characters.
 */
final class EntityLabelTest extends TestCase
{
    public function testAnAddressShowsAsStored(): void
    {
        self::assertSame('shopper1@example.test', EntityLabel::forDisplay('shopper1@example.test'));
        self::assertSame('Shopper1@Example.TEST', EntityLabel::forDisplay('Shopper1@Example.TEST'));
    }

    public function testAStoredHashShowsItsFirstTwelveCharacters(): void
    {
        $hash = str_repeat('ab12', 16);

        self::assertSame('ab12ab12ab12…', EntityLabel::forDisplay($hash));
    }

    public function testARowWithoutAnEntityShowsADash(): void
    {
        self::assertSame('—', EntityLabel::forDisplay(null));
        self::assertSame('—', EntityLabel::forDisplay(''));
    }

    public function testAnEntityThatIsNoHashShowsAsStored(): void
    {
        self::assertSame('1042', EntityLabel::forDisplay('1042'));
        self::assertSame(str_repeat('ab12', 15), EntityLabel::forDisplay(str_repeat('ab12', 15)), '60 hex characters are no hash');
    }
}
