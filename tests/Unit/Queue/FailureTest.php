<?php
declare(strict_types=1);

namespace Tests\Unit\Queue;

use PHPUnit\Framework\TestCase;
use PrestaShop\Module\SmailyForPrestaShop\Queue\Failure;

/**
 * A refusal that a retry cannot change parks the row on its first attempt, with the reason the Log
 * of every Smaily connector shows; any other failure takes the retry ladder.
 */
final class FailureTest extends TestCase
{
    /**
     * @dataProvider permanentHttpStatuses
     */
    public function testParksA4xxOtherThan429(int $status): void
    {
        $failure = Failure::http($status, sprintf('Smaily answered HTTP %d', $status));

        self::assertTrue($failure->isPermanent());
        self::assertSame(sprintf('permanent_http_%1$d: Smaily answered HTTP %1$d', $status), $failure->reason());
    }

    /**
     * @return array<string, array{int}>
     */
    public function permanentHttpStatuses(): array
    {
        return ['400' => [400], '401' => [401], '403' => [403], '404' => [404], '422' => [422], '499' => [499]];
    }

    public function testRetriesA429AfterTheDelayItAskedFor(): void
    {
        $failure = Failure::http(429, 'Smaily answered HTTP 429', 120);

        self::assertFalse($failure->isPermanent());
        self::assertSame('Smaily answered HTTP 429', $failure->reason());
        self::assertSame(120, $failure->retryAfter());
    }

    public function testRetriesA5xx(): void
    {
        $failure = Failure::http(503, 'Smaily answered HTTP 503');

        self::assertFalse($failure->isPermanent());
        self::assertNull($failure->retryAfter());
    }

    public function testParksSmailyCode203(): void
    {
        $failure = Failure::envelope(203, 'Smaily API returned code 203');

        self::assertTrue($failure->isPermanent());
        self::assertSame('permanent_envelope_203: Smaily API returned code 203', $failure->reason());
    }

    public function testRetriesAnyOtherSmailyCode(): void
    {
        $failure = Failure::envelope(225, 'Smaily API returned code 225');

        self::assertFalse($failure->isPermanent());
        self::assertSame('Smaily API returned code 225', $failure->reason());
    }

    public function testParksAHandlersOwnVerdict(): void
    {
        $failure = Failure::permanent('The payload is not valid JSON');

        self::assertTrue($failure->isPermanent());
        self::assertSame('The payload is not valid JSON', $failure->reason());
    }

    public function testKeepsAThrownErrorByItsTypeAndPlaceNeverItsText(): void
    {
        $error = new \RuntimeException('shopper@example.test was refused', 7);
        $line = __LINE__ - 1;

        $failure = Failure::of($error);

        self::assertFalse($failure->isPermanent());
        self::assertSame(sprintf('RuntimeException at FailureTest.php:%d (code 7)', $line), $failure->reason());
    }
}
