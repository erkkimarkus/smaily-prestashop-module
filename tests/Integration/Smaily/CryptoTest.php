<?php
declare(strict_types=1);

namespace Tests\Integration\Smaily;

use PrestaShop\Module\SmailyForPrestaShop\Smaily\Crypto;
use Tests\Integration\IntegrationTestCase;

/**
 * Secrets are encrypted with the encryption PrestaShop ships, keyed by the shop's own key.
 */
final class CryptoTest extends IntegrationTestCase
{
    public function testAValueRoundTrips(): void
    {
        $crypto = new Crypto(_NEW_COOKIE_KEY_);

        $ciphertext = $crypto->encrypt('not-a-real-password');

        self::assertStringNotContainsString('not-a-real-password', $ciphertext);
        self::assertSame('not-a-real-password', $crypto->decrypt($ciphertext));
    }

    public function testAValueOfAnotherKeyDoesNotDecrypt(): void
    {
        $ciphertext = (new Crypto(\PhpEncryption::createNewRandomKey()))->encrypt('not-a-real-password');

        self::assertNull((new Crypto(_NEW_COOKIE_KEY_))->decrypt($ciphertext));
    }

    public function testAnotherKeyLeavesTheShopsOwnEncryptionAlone(): void
    {
        $shop = new \PhpEncryption(_NEW_COOKIE_KEY_);
        $ciphertext = $shop->encrypt('a cookie value');

        (new Crypto(\PhpEncryption::createNewRandomKey()))->encrypt('anything');

        self::assertSame('a cookie value', $shop->decrypt($ciphertext));
    }
}
