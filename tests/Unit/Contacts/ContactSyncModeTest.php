<?php
declare(strict_types=1);

namespace Tests\Unit\Contacts;

use PHPUnit\Framework\TestCase;
use PrestaShop\Module\SmailyForPrestaShop\Contacts\ContactSyncMode;

/**
 * The three consent modes of the sibling connectors, and the guests switch.
 */
final class ContactSyncModeTest extends TestCase
{
    public function testAnUnknownOrMissingModeIsSubscribersOnly(): void
    {
        foreach (['', 'legitimate_interest', 'consent', 'SUBSCRIBERS'] as $stored) {
            self::assertSame(ContactSyncMode::SUBSCRIBERS, (new ContactSyncMode($stored, false))->mode());
        }
    }

    public function testKeepsEachKnownMode(): void
    {
        foreach ([ContactSyncMode::SUBSCRIBERS, ContactSyncMode::ALL_CUSTOMERS, ContactSyncMode::CHECKOUT_OPTIN] as $mode) {
            self::assertSame($mode, (new ContactSyncMode($mode, false))->mode());
        }
    }

    public function testIncludeGuestsFollowsTheSwitchExceptInCheckoutOptIn(): void
    {
        self::assertTrue((new ContactSyncMode(ContactSyncMode::SUBSCRIBERS, true))->includesGuests());
        self::assertFalse((new ContactSyncMode(ContactSyncMode::SUBSCRIBERS, false))->includesGuests());
        self::assertTrue((new ContactSyncMode(ContactSyncMode::ALL_CUSTOMERS, true))->includesGuests());
        self::assertFalse((new ContactSyncMode(ContactSyncMode::CHECKOUT_OPTIN, true))->includesGuests());
    }
}
