<?php
declare(strict_types=1);

namespace Tests\Unit\Queue;

use PHPUnit\Framework\TestCase;
use PrestaShop\Module\SmailyForPrestaShop\Queue\ContactEntity;

/**
 * The entity of a contact's queue row, as the Magento connector's Model\Queue\ContactEntity: an
 * address that fits the entity column is the entity; a longer one is a keyed hash of 64 hex
 * characters, the same for every spelling of the address.
 */
final class ContactEntityTest extends TestCase
{
    /** 70 characters. */
    private const LONG = 'shopper.having.a.very.long.invented.name@a-long-subdomain.example.test';

    public static function setUpBeforeClass(): void
    {
        // The shop defines its key; on the host the test stands in for it.
        defined('_NEW_COOKIE_KEY_') || define('_NEW_COOKIE_KEY_', 'not-a-real-key');
    }

    public function testAnAddressThatFitsTheColumnIsTheEntityAsGiven(): void
    {
        self::assertSame('shopper1@example.test', ContactEntity::of('shopper1@example.test'));
        self::assertSame('Shopper1@Example.TEST', ContactEntity::of('Shopper1@Example.TEST'));
        $widest = str_repeat('a', 51) . '@example.test';
        self::assertSame(ContactEntity::MAX_LENGTH, strlen($widest));
        self::assertSame($widest, ContactEntity::of($widest));
    }

    public function testALongerAddressIsAKeyedHashOf64HexCharacters(): void
    {
        self::assertSame(70, strlen(self::LONG));
        $entity = ContactEntity::of(self::LONG);

        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $entity);
        self::assertSame(hash_hmac('sha256', 'smaily-connect-address|' . self::LONG, _NEW_COOKIE_KEY_), $entity);
        self::assertNotSame(hash('sha256', self::LONG), $entity, 'the hash is keyed');
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', ContactEntity::of('a' . str_repeat('a', 51) . '@example.test'), '65 characters');
    }

    public function testEverySpellingOfALongAddressHasTheSameEntity(): void
    {
        self::assertSame(ContactEntity::of(self::LONG), ContactEntity::of(' ' . strtoupper(self::LONG) . ' '));
        self::assertNotSame(ContactEntity::of(self::LONG), ContactEntity::of('another.' . self::LONG));
    }
}
