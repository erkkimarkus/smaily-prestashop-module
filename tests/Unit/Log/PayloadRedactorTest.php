<?php
declare(strict_types=1);

namespace Tests\Unit\Log;

use PHPUnit\Framework\TestCase;
use PrestaShop\Module\SmailyForPrestaShop\Log\PayloadRedactor;

/**
 * Nothing under a key that looks like a secret is stored or shown; a contact's data is, in full, as
 * in the Magento connector's Log.
 */
final class PayloadRedactorTest extends TestCase
{
    public function testAValueUnderASecretLookingKeyIsRedactedAtAnyDepth(): void
    {
        $redacted = PayloadRedactor::redact([
            'email' => 'shopper1@example.test',
            'password' => 'not-a-real-password',
            'API-Key' => 'not-a-real-key',
            'nested' => ['access_token' => 'not-a-real-token', 'first_name' => 'Testfirst', 'deeper' => [['Authorization' => 'Basic abc']]],
            'credentials' => ['username' => 'api-user', 'secret' => 'x'],
            ['signature' => 'x'],
        ]);

        self::assertSame([
            'email' => 'shopper1@example.test',
            'password' => '[redacted]',
            'API-Key' => '[redacted]',
            'nested' => ['access_token' => '[redacted]', 'first_name' => 'Testfirst', 'deeper' => [['Authorization' => '[redacted]']]],
            'credentials' => '[redacted]',
            ['signature' => '[redacted]'],
        ], $redacted);
    }

    public function testAContactsDataStaysInFull(): void
    {
        $contact = [['email' => 'shopper1@example.test', 'first_name' => 'Testfirst', 'last_name' => 'Testlast', 'is_unsubscribed' => 0]];

        self::assertSame($contact, PayloadRedactor::redact($contact));
    }

    public function testStoredJsonShowsRedactedOnLinesAndOtherTextAsStored(): void
    {
        self::assertSame(
            "{\n    \"email\": \"shopper1@example.test\",\n    \"token\": \"[redacted]\",\n    \"url\": \"https://shop.example.test/õ\"\n}",
            PayloadRedactor::forDisplay('{"email":"shopper1@example.test","token":"abc","url":"https://shop.example.test/õ"}')
        );
        self::assertSame('<html>Bad gateway</html>', PayloadRedactor::forDisplay('<html>Bad gateway</html>'));
        self::assertSame('', PayloadRedactor::forDisplay(null));
        self::assertSame('', PayloadRedactor::forDisplay("  \n"));
    }
}
