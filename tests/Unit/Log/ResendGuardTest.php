<?php
declare(strict_types=1);

namespace Tests\Unit\Log;

use PHPUnit\Framework\TestCase;
use PrestaShop\Module\SmailyForPrestaShop\Log\ResendGuard;

/**
 * The resend guard's sentences are the Magento connector's; a stale contact sync, which Magento
 * does not have, has its own.
 */
final class ResendGuardTest extends TestCase
{
    public function testEachRefusalWithASentenceHasMagentosWords(): void
    {
        self::assertSame('Only a failed event can be sent again.', ResendGuard::message(ResendGuard::REASON_NOT_FAILED));
        self::assertSame('This reminder was withdrawn because the shopper completed the purchase; it cannot be re-sent.', ResendGuard::message(ResendGuard::REASON_WITHDRAWN));
        self::assertSame('A later message of this kind already reached this contact; sending again would deliver it twice.', ResendGuard::message(ResendGuard::REASON_SUPERSEDED));
        self::assertSame('This contact\'s data was erased; the event cannot be re-sent.', ResendGuard::message(ResendGuard::REASON_ERASED));
        self::assertSame(
            'A later change of this contact\'s newsletter subscription already reached Smaily; sending this one again would undo it.',
            ResendGuard::message(ResendGuard::REASON_STALE)
        );
        self::assertSame('', ResendGuard::message(ResendGuard::REASON_QUEUED));
    }
}
