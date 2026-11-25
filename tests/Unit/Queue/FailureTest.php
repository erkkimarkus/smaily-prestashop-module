<?php
declare(strict_types=1);

namespace Tests\Unit\Queue;

use PHPUnit\Framework\TestCase;
use PrestaShop\Module\SmailyForPrestaShop\Queue\Failure;
use PrestaShop\Module\SmailyForPrestaShop\Smaily\Exception\ApiException;
use PrestaShop\Module\SmailyForPrestaShop\Smaily\Exception\AuthenticationException;
use PrestaShop\Module\SmailyForPrestaShop\Smaily\Exception\InvalidSubdomainException;
use PrestaShop\Module\SmailyForPrestaShop\Smaily\Exception\PlanBlockedException;
use PrestaShop\Module\SmailyForPrestaShop\Smaily\Exception\RequestRefusedException;
use PrestaShop\Module\SmailyForPrestaShop\Smaily\Exception\SmailyClientException;
use PrestaShop\Module\SmailyForPrestaShop\Smaily\Exception\TransportException;

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

    /**
     * The Smaily client decides which answers are permanent (Smaily\Exception); the queue reads its
     * verdict from the error's type.
     */
    public function testParksSmailyCode203AsTheClientTypesIt(): void
    {
        $failure = Failure::of(new ApiException(203));

        self::assertTrue($failure->isPermanent());
        self::assertSame('permanent_envelope_203: Smaily API returned code 203', $failure->reason());
    }

    public function testRetriesAnyOtherSmailyCode(): void
    {
        $failure = Failure::of(new ApiException(225));

        self::assertFalse($failure->isPermanent());
        self::assertSame('Smaily API returned code 225', $failure->reason());
    }

    public function testParksARequestTheClientTypesAsRefused(): void
    {
        $failure = Failure::of(new AuthenticationException('Smaily API credentials were rejected', 401));

        self::assertTrue($failure->isPermanent());
        self::assertSame('permanent_http_401: Smaily API credentials were rejected', $failure->reason());
    }

    public function testRetriesATransportFailureAfterTheDelayItAskedFor(): void
    {
        $failure = Failure::of(new TransportException('Smaily API request failed with HTTP 429', 429, 120));

        self::assertFalse($failure->isPermanent());
        self::assertSame('Smaily API request failed with HTTP 429', $failure->reason());
        self::assertSame(120, $failure->retryAfter());
    }

    /**
     * Each type of the Smaily client's errors keeps its verdict, its reason and its delay.
     *
     * @dataProvider smailyClientErrors
     */
    public function testEachSmailyClientErrorKeepsItsVerdict(\Throwable $error, bool $permanent, string $reason, ?int $retryAfter): void
    {
        $failure = Failure::of($error);

        self::assertSame($permanent, $failure->isPermanent());
        self::assertSame($reason, $failure->reason());
        self::assertSame($retryAfter, $failure->retryAfter());
    }

    /**
     * @return iterable<string, array{\Throwable, bool, string, int|null}>
     */
    public static function smailyClientErrors(): iterable
    {
        yield 'refused' => [new RequestRefusedException('Smaily API request failed with HTTP 404', 404), true, 'permanent_http_404: Smaily API request failed with HTTP 404', null];
        yield 'credentials' => [new AuthenticationException('Smaily API credentials were rejected', 403), true, 'permanent_http_403: Smaily API credentials were rejected', null];
        yield 'plan blocked' => [new PlanBlockedException('Smaily refused the request', 402), true, 'permanent_http_402: Smaily refused the request', null];
        yield 'envelope 203' => [new ApiException(203), true, 'permanent_envelope_203: Smaily API returned code 203', null];
        yield 'envelope 225' => [new ApiException(225), false, 'Smaily API returned code 225', null];
        yield 'transport 429' => [new TransportException('Smaily API request failed with HTTP 429', 429, 60), false, 'Smaily API request failed with HTTP 429', 60];
        yield 'transport 503' => [new TransportException('Smaily API request failed with HTTP 503', 503), false, 'Smaily API request failed with HTTP 503', null];
        yield 'subdomain' => [new InvalidSubdomainException(), false, 'The subdomain must be a plain Smaily subdomain such as "demo": letters, digits and hyphens only.', null];
        yield 'base' => [new SmailyClientException('Smaily API failed'), false, 'Smaily API failed', null];
    }

    /**
     * As Magento keeps it: the row takes the retry ladder with the client's message, which the Log
     * shows translated.
     */
    public function testRetriesASubdomainThatIsNotOnePlainLabelWithItsMessage(): void
    {
        $failure = Failure::of(new InvalidSubdomainException());

        self::assertFalse($failure->isPermanent());
        self::assertSame('The subdomain must be a plain Smaily subdomain such as "demo": letters, digits and hyphens only.', $failure->reason());
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
