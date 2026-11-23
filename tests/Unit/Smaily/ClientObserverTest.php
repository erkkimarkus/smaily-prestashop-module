<?php
declare(strict_types=1);

namespace Tests\Unit\Smaily;

use PHPUnit\Framework\TestCase;
use PrestaShop\Module\SmailyForPrestaShop\Http\SymfonyTransport;
use PrestaShop\Module\SmailyForPrestaShop\Smaily\Client;
use PrestaShop\Module\SmailyForPrestaShop\Smaily\Exception\SmailyClientException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Every answer of every Smaily client is told to the class's observer, for the installation
 * report's health of the account: the subdomain, '' or the report's error code, and whether the
 * request wrote data. Never Smaily's text.
 */
final class ClientObserverTest extends TestCase
{
    /** @var list<array{string, string, bool}> */
    private array $told = [];

    /** @var callable|null the observer of the module's own services, put back after each test */
    private $before;

    protected function setUp(): void
    {
        $this->before = Client::observe(function (string $subdomain, string $errorCode, bool $wrote): void {
            $this->told[] = [$subdomain, $errorCode, $wrote];
        });
    }

    protected function tearDown(): void
    {
        Client::observe($this->before);
    }

    /**
     * @return array<string, array{string, MockResponse, string, bool}>
     */
    public function answers(): array
    {
        return [
            'a write that Smaily took' => ['POST', new MockResponse('{"code": 101, "message": "OK"}'), '', true],
            'a read' => ['GET', new MockResponse('[{"id": 11}]'), '', false],
            'a read whose code says "no such contact"' => ['GET', new MockResponse('{"code": 206, "message": "Contact shopper@example.test not found"}'), '', false],
            'a write refused for its data' => ['POST', new MockResponse('{"code": 203, "message": "shopper@example.test is invalid"}'), 'permanent_envelope_203', false],
            'a write refused by the package, HTTP 200' => ['POST', new MockResponse('{"code": 227, "message": "A paid package is required"}'), 'plan_blocked', false],
            'a write refused with another code' => ['POST', new MockResponse('{"code": 201, "message": "x"}'), 'unreachable', false],
            'the package has no API' => ['GET', new MockResponse('{"code": 227, "message": "A paid package is required"}', ['http_code' => 403]), 'plan_blocked', false],
            'refused credentials' => ['GET', new MockResponse('<html>Unauthorized</html>', ['http_code' => 401]), 'credentials_rejected', false],
            'a wrong subdomain' => ['POST', new MockResponse('Not Found', ['http_code' => 404]), 'credentials_rejected', false],
            'too many requests' => ['POST', new MockResponse('', ['http_code' => 429]), 'unreachable', false],
            'a failure of Smaily' => ['POST', new MockResponse('', ['http_code' => 503]), 'unreachable', false],
            'an answer that is not JSON' => ['POST', new MockResponse('<html>OK</html>'), 'unreachable', false],
            'no answer' => ['POST', new MockResponse('', ['error' => 'Could not resolve host']), 'unreachable', false],
        ];
    }

    /**
     * @dataProvider answers
     */
    public function testTheObserverIsToldHowTheRequestWent(string $method, MockResponse $response, string $errorCode, bool $wrote): void
    {
        $client = new Client(new SymfonyTransport(new MockHttpClient($response)), 'demo', 'api-user', 'not-a-real-password');

        try {
            $method === 'POST' ? $client->post(Client::ENDPOINT_CONTACT, [['email' => 'shopper@example.test']]) : $client->get(Client::ENDPOINT_CONTACT, ['email' => 'shopper@example.test']);
        } catch (SmailyClientException $failure) {
            // The failure is the caller's; the observer was told before it.
        }

        self::assertSame([['demo', $errorCode, $wrote]], $this->told);
    }

    public function testARequestThatIsNeverSentTellsNothingAndNoObserverIsNeeded(): void
    {
        try {
            (new Client(new SymfonyTransport(new MockHttpClient([])), 'evil.example.test/x', 'api-user', 'not-a-real-password'))->get(Client::ENDPOINT_CONTACT);
        } catch (SmailyClientException $failure) {
        }
        self::assertSame([], $this->told);

        Client::observe(null);
        (new Client(new SymfonyTransport(new MockHttpClient(new MockResponse('{"code": 101}'))), 'demo', 'api-user', 'not-a-real-password'))->post(Client::ENDPOINT_CONTACT, []);
        self::assertSame([], $this->told);
    }
}
