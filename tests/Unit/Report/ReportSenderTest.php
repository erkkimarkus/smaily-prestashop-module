<?php
declare(strict_types=1);

namespace Tests\Unit\Report;

use PHPUnit\Framework\TestCase;
use PrestaShop\Module\SmailyForPrestaShop\Http\NetworkException;
use PrestaShop\Module\SmailyForPrestaShop\Http\Response;
use PrestaShop\Module\SmailyForPrestaShop\Report\Credentials;
use PrestaShop\Module\SmailyForPrestaShop\Report\Report;
use PrestaShop\Module\SmailyForPrestaShop\Report\ReportSender;

require_once __DIR__ . '/ReportFixture.php';

/**
 * The sender posts one report to the subscription service with the main account's credentials in
 * the Authorization header only, follows no redirect, and answers the HTTP status and the
 * service's error code: never its message.
 */
final class ReportSenderTest extends TestCase
{
    private const PASSWORD = 'not-a-real-password';

    public function testPostsTheBodyAsJsonWithTheCredentialsInTheHeaderOnly(): void
    {
        $transport = new ReportTransport();
        $body = ['product' => 'connect_prestashop', 'installation_url' => 'https://example-store.test'];

        $result = (new ReportSender($transport, '3.0.0'))->send(new Report($body, new Credentials('example', 'api-user', self::PASSWORD)));

        self::assertTrue($result->isAccepted());
        self::assertSame('http=200 error=-', $result->summary());
        self::assertSame([[
            'POST',
            'https://subscription.smaily.com/v1/reports',
            [
                'body' => '{"product":"connect_prestashop","installation_url":"https://example-store.test"}',
                'headers' => [
                    'Authorization' => 'Basic ' . base64_encode('api-user:' . self::PASSWORD),
                    'Content-Type' => 'application/json; charset=utf-8',
                    'Accept' => 'application/json',
                    'User-Agent' => 'SmailyConnect-PrestaShopModule/3.0.0',
                ],
                'timeout' => 3,
                'max_duration' => 5,
                'max_redirects' => 0,
            ],
        ]], $transport->requests);
        self::assertStringNotContainsString(self::PASSWORD, (string) $transport->requests[0][2]['body']);
        self::assertStringNotContainsString('api-user', (string) $transport->requests[0][2]['body']);
    }

    /**
     * @return array<string, array{Response|\Throwable, string, bool, int}>
     */
    public function refusals(): array
    {
        return [
            'a body that fails validation' => [new Response(400, '{"error": "validation_failed", "message": "One or more fields failed validation."}'), 'http=400 error=validation_failed', false, 0],
            'refused credentials' => [new Response(401, '{"error": "invalid_smaily_credentials", "message": "x"}'), 'http=401 error=invalid_smaily_credentials', false, 0],
            'a body that is too large' => [new Response(413, '{"error": "body_too_large"}'), 'http=413 error=body_too_large', false, 0],
            'a redirect' => [new Response(308, '', ['location' => ['https://elsewhere.example.test/']]), 'http=308 error=redirect', false, 0],
            'too many requests' => [new Response(429, '{"error": "rate_limited"}', ['retry-after' => ['120']]), 'http=429 error=rate_limited', true, 120],
            'a failure of the service' => [new Response(500, '{"error": "internal_error"}'), 'http=500 error=internal_error', true, 0],
            'Smaily does not answer the service' => [new Response(503, '{"error": "smaily_unreachable"}', ['retry-after' => ['7200']]), 'http=503 error=smaily_unreachable', true, 7200],
            'a gateway in front of the service' => [new Response(502, '<html>Bad Gateway</html>'), 'http=502 error=-', true, 0],
            'a Retry-After that is a date' => [new Response(503, '{"error": "unavailable"}', ['retry-after' => ['Wed, 21 Oct 2026 07:28:00 GMT']]), 'http=503 error=unavailable', true, 0],
            'no answer' => [new NetworkException('Idle timeout reached for "https://subscription.smaily.com/v1/reports".'), 'http=0 error=network_error', true, 0],
        ];
    }

    /**
     * @dataProvider refusals
     *
     * @param Response|\Throwable $answer
     */
    public function testAnswersTheStatusTheCodeAndWhetherTheReportMayPassLater($answer, string $summary, bool $retry, int $retryAfter): void
    {
        $result = (new ReportSender(new ReportTransport($answer), '3.0.0'))->send(new Report(['product' => 'connect_prestashop'], new Credentials('example', 'api-user', self::PASSWORD)));

        self::assertFalse($result->isAccepted());
        self::assertSame($summary, $result->summary());
        self::assertSame($retry, $result->mayRetry());
        self::assertSame($retryAfter, $result->retryAfter());
    }

    public function testTheServicesTextNeverReachesTheSummary(): void
    {
        $answer = new Response(400, '{"error": "Field shopper@example.test is wrong", "message": "shopper@example.test"}');

        $result = (new ReportSender(new ReportTransport($answer), '3.0.0'))->send(new Report([], new Credentials('example', 'api-user', self::PASSWORD)));

        self::assertSame('http=400 error=-', $result->summary());
    }

    public function testABodyOverTheLimitIsNotSent(): void
    {
        $transport = new ReportTransport();

        $result = (new ReportSender($transport, '3.0.0'))->send(new Report(['filler' => str_repeat('x', ReportSender::MAX_BODY_BYTES)], new Credentials('example', 'api-user', self::PASSWORD)));

        self::assertSame('http=0 error=body_too_large', $result->summary());
        self::assertFalse($result->mayRetry());
        self::assertSame([], $transport->requests);
    }
}
