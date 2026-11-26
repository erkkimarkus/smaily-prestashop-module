<?php
declare(strict_types=1);

namespace Tests\Unit\Log;

use PHPUnit\Framework\TestCase;
use PrestaShop\Module\SmailyForPrestaShop\Log\ResendGuard;

/**
 * The resend guard's refusals and sentences are the Magento connector's, and one for a contact
 * change that a newer delivered change overtook (owner, 2026-11-26).
 */
final class ResendGuardTest extends TestCase
{
    public function testEachRefusalWithASentenceHasMagentosWords(): void
    {
        self::assertSame('Only a failed event can be sent again.', ResendGuard::message(ResendGuard::REASON_NOT_FAILED));
        self::assertSame('This reminder was withdrawn because the shopper completed the purchase; it cannot be re-sent.', ResendGuard::message(ResendGuard::REASON_WITHDRAWN));
        self::assertSame('A later message of this kind already reached this contact; sending again would deliver it twice.', ResendGuard::message(ResendGuard::REASON_SUPERSEDED));
        self::assertSame('This contact\'s data was erased; the event cannot be re-sent.', ResendGuard::message(ResendGuard::REASON_ERASED));
        self::assertSame('A newer change of this contact was already sent to Smaily; sending this older one again would undo it.', ResendGuard::message(ResendGuard::REASON_NEWER_CHANGE));
        self::assertSame('', ResendGuard::message('stale_subscription'));
        self::assertSame('', ResendGuard::message('queued'));
    }
}
