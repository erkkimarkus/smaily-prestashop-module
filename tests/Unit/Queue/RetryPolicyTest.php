<?php
declare(strict_types=1);

namespace Tests\Unit\Queue;

use PHPUnit\Framework\TestCase;
use PrestaShop\Module\SmailyForPrestaShop\Queue\Failure;
use PrestaShop\Module\SmailyForPrestaShop\Queue\RetryPolicy;

/**
 * A failed row waits 60 s, 5 min, 15 min and 1 h before its next attempts, and is parked after its
 * fifth, as on the sibling connectors.
 */
final class RetryPolicyTest extends TestCase
{
    public function testSpacesTheAttemptsOnTheLadder(): void
    {
        $delays = [];
        for ($attempts = 1; $attempts <= 4; ++$attempts) {
            $delays[] = RetryPolicy::delay($attempts, null);
        }

        self::assertSame([60, 300, 900, 3600], $delays);
    }

    public function testParksATemporaryFailureAfterTheFifthAttempt(): void
    {
        $failure = Failure::temporary('Smaily answered HTTP 503');

        self::assertFalse(RetryPolicy::parks(4, $failure));
        self::assertTrue(RetryPolicy::parks(5, $failure));
    }

    public function testParksAPermanentFailureOnTheFirstAttempt(): void
    {
        self::assertTrue(RetryPolicy::parks(1, Failure::permanent('No handler')));
    }

    public function testWaitsTheRetryAfterThatSmailyAskedFor(): void
    {
        self::assertSame(120, RetryPolicy::delay(1, 120));
    }

    public function testCapsTheRetryAfterAtSixHours(): void
    {
        self::assertSame(21600, RetryPolicy::delay(1, 86400));
    }

    public function testFallsBackToTheLadderWithoutAUsableRetryAfter(): void
    {
        self::assertSame(300, RetryPolicy::delay(2, 0));
    }
}
