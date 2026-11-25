<?php
declare(strict_types=1);

namespace Tests\Unit\Smaily;

use PHPUnit\Framework\TestCase;
use PrestaShop\Module\SmailyForPrestaShop\Http\SymfonyTransport;
use PrestaShop\Module\SmailyForPrestaShop\Smaily\Client;
use PrestaShop\Module\SmailyForPrestaShop\Smaily\Exception\ApiException;
use PrestaShop\Module\SmailyForPrestaShop\Smaily\Exception\AuthenticationException;
use PrestaShop\Module\SmailyForPrestaShop\Smaily\Exception\InvalidSubdomainException;
use PrestaShop\Module\SmailyForPrestaShop\Smaily\Exception\PlanBlockedException;
use PrestaShop\Module\SmailyForPrestaShop\Smaily\Exception\RequestRefusedException;
use PrestaShop\Module\SmailyForPrestaShop\Smaily\Exception\SmailyClientException;
use PrestaShop\Module\SmailyForPrestaShop\Smaily\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * The Smaily API client sends each request to the account's own host as its API user, and types
 * every failure as Smaily Connect for Magento does: a 4xx other than 429 refuses the request, a 429,
 * a 5xx or a network failure may pass later, and an answer whose code is not 101 is an API error.
 */
final class ClientTest extends TestCase
{
    private const PASSWORD = 'not-a-real-password';

    /** @var list<array{method: string, url: string, headers: array<string, list<string>>, body: string}> */
    private array $requests = [];

    public function testPostsJsonToTheAccountHostAsTheApiUser(): void
    {
        $client = $this->client([new MockResponse('{"code": 101, "message": "OK"}')]);

        $answer = $client->post(Client::ENDPOINT_CONTACT, [['email' => 'contact@example.test']]);

        self::assertSame(['code' => 101, 'message' => 'OK'], $answer);
        self::assertCount(1, $this->requests);
        self::assertSame('POST https://demo.sendsmaily.net/api/contact.php', $this->requests[0]['method'] . ' ' . $this->requests[0]['url']);
        self::assertSame(['Content-Type: application/json'], $this->requests[0]['headers']['content-type']);
        self::assertSame([['email' => 'contact@example.test']], json_decode($this->requests[0]['body'], true));
        self::assertSame(
            ['Authorization: Basic ' . base64_encode('api-user:' . self::PASSWORD)],
            $this->requests[0]['headers']['authorization']
        );
    }

    public function testGetsWithTheQueryAndReturnsAListAnswer(): void
    {
        $client = $this->client([new MockResponse('[{"email": "contact@example.test"}]')]);

        $answer = $client->get(Client::ENDPOINT_CONTACT, ['list' => 2, 'limit' => 50, 'offset' => 1]);

        self::assertSame([['email' => 'contact@example.test']], $answer);
        self::assertSame(
            'GET https://demo.sendsmaily.net/api/contact.php?list=2&limit=50&offset=1',
            $this->requests[0]['method'] . ' ' . $this->requests[0]['url']
        );
    }

    /**
     * @return array<string, array{int, bool}>
     */
    public static function envelopeCodes(): array
    {
        return [
            'invalid data' => [203, true],
            'email not found' => [206, false],
            'a paid package is required' => [227, false],
        ];
    }

    /**
     * @dataProvider envelopeCodes
     */
    public function testAnAnswerWithACodeOtherThan101IsAnApiError(int $code, bool $permanent): void
    {
        $client = $this->client([new MockResponse('{"code": ' . $code . ', "message": "Contact Example could not be saved"}')]);

        try {
            $client->post(Client::ENDPOINT_CONTACT, []);
            self::fail('Expected an ApiException');
        } catch (ApiException $exception) {
            self::assertSame($code, $exception->getSmailyCode());
            self::assertSame($permanent, $exception->isPermanent());
            self::assertSame('Smaily API returned code ' . $code, $exception->getMessage());
        }
    }

    /**
     * @return array<string, array{MockResponse, class-string<SmailyClientException>, bool}>
     */
    public static function httpErrors(): array
    {
        return [
            'bad request' => [new MockResponse('', ['http_code' => 400]), RequestRefusedException::class, true],
            'not found' => [new MockResponse('Not found', ['http_code' => 404]), RequestRefusedException::class, true],
            'unprocessable' => [new MockResponse('', ['http_code' => 422]), RequestRefusedException::class, true],
            'credentials refused' => [new MockResponse('', ['http_code' => 401]), AuthenticationException::class, true],
            'forbidden' => [new MockResponse('', ['http_code' => 403]), AuthenticationException::class, true],
            'package without API access' => [
                new MockResponse('{"code": 227, "message": "A paid package is required."}', ['http_code' => 403]),
                PlanBlockedException::class,
                true,
            ],
            'slow down' => [new MockResponse('', ['http_code' => 429]), TransportException::class, false],
            'server error' => [new MockResponse('', ['http_code' => 500]), TransportException::class, false],
            'unavailable' => [new MockResponse('Unavailable', ['http_code' => 503]), TransportException::class, false],
        ];
    }

    /**
     * @dataProvider httpErrors
     *
     * @param class-string<SmailyClientException> $type
     */
    public function testAnHttpErrorIsTypedWhenItIsThrown(MockResponse $response, string $type, bool $refusal): void
    {
        $client = $this->client([$response]);

        try {
            $client->post(Client::ENDPOINT_CONTACT, []);
            self::fail('Expected a SmailyClientException');
        } catch (SmailyClientException $exception) {
            self::assertInstanceOf($type, $exception);
            self::assertSame($refusal, $exception instanceof RequestRefusedException);
            self::assertSame(!$refusal, $exception instanceof TransportException);
        }
    }

    public function testAnErrorNamesTheStatusNeverTheAnswer(): void
    {
        $client = $this->client([new MockResponse('Contact Example, contact@example.test failed', ['http_code' => 503])]);

        try {
            $client->post(Client::ENDPOINT_CONTACT, []);
            self::fail('Expected a TransportException');
        } catch (TransportException $exception) {
            self::assertSame('Smaily API request failed with HTTP 503', $exception->getMessage());
            self::assertSame(503, $exception->getHttpStatus());
        }
    }

    public function testA429CarriesTheRequestedRetryDelay(): void
    {
        $client = $this->client([new MockResponse('', ['http_code' => 429, 'response_headers' => ['Retry-After: 90']])]);

        try {
            $client->post(Client::ENDPOINT_CONTACT, []);
            self::fail('Expected a TransportException');
        } catch (TransportException $exception) {
            self::assertSame(429, $exception->getHttpStatus());
            self::assertSame(90, $exception->getRetryAfter());
        }
    }

    public function testAnHttpDateRetryAfterIsNotMisread(): void
    {
        $client = $this->client([
            new MockResponse('', ['http_code' => 429, 'response_headers' => ['Retry-After: Wed, 21 Oct 2026 07:28:00 GMT']]),
        ]);

        try {
            $client->post(Client::ENDPOINT_CONTACT, []);
            self::fail('Expected a TransportException');
        } catch (TransportException $exception) {
            self::assertNull($exception->getRetryAfter());
        }
    }

    public function testAMalformedAnswerMayPassLater(): void
    {
        $client = $this->client([new MockResponse('<html>Bad gateway</html>')]);

        $this->expectException(TransportException::class);
        $this->expectExceptionMessage('Smaily API returned a malformed response body');

        $client->get(Client::ENDPOINT_AUTORESPONDER);
    }

    public function testANetworkFailureMayPassLaterAndNamesNoQueryValue(): void
    {
        $client = $this->client([new MockResponse('', [
            'error' => 'Could not resolve host for "https://demo.sendsmaily.net/api/contact.php?email=contact%40example.test".',
        ])]);

        try {
            $client->get(Client::ENDPOINT_CONTACT, ['email' => 'contact@example.test']);
            self::fail('Expected a TransportException');
        } catch (TransportException $exception) {
            self::assertSame(0, $exception->getHttpStatus());
            self::assertSame('Smaily API request failed: could not connect to Smaily', $exception->getMessage(), 'never the HTTP client\'s own text');
            self::assertNull($exception->getPrevious());
        }
    }

    public function testATimeoutNamesTheSecondsItWaited(): void
    {
        $client = $this->client([new MockResponse((static function (): \Generator {
            yield '';
        })())]);

        try {
            $client->get(Client::ENDPOINT_CONTACT, ['email' => 'contact@example.test']);
            self::fail('Expected a TransportException');
        } catch (TransportException $exception) {
            self::assertSame(0, $exception->getHttpStatus());
            self::assertSame('Smaily API request failed: no answer within 30 seconds', $exception->getMessage());
        }
    }

    public function testASubdomainThatIsNotOnePlainLabelSendsNoRequest(): void
    {
        $client = new Client(new SymfonyTransport($this->recorder([])), 'evil.example.test/x', 'api-user', self::PASSWORD);

        try {
            $client->get(Client::ENDPOINT_AUTORESPONDER);
            self::fail('Expected an InvalidSubdomainException');
        } catch (InvalidSubdomainException $exception) {
            self::assertSame([], $this->requests);
        }
    }

    /**
     * @return array<string, array{MockResponse}>
     */
    public static function failures(): array
    {
        $echo = '{"code": 203, "message": "api-user:' . self::PASSWORD . '"}';

        return [
            'api error' => [new MockResponse($echo)],
            'refusal' => [new MockResponse($echo, ['http_code' => 400])],
            'credentials refused' => [new MockResponse($echo, ['http_code' => 401])],
            'package' => [new MockResponse('{"code": 227, "message": "' . self::PASSWORD . '"}', ['http_code' => 403])],
            'server error' => [new MockResponse($echo, ['http_code' => 500])],
            'malformed' => [new MockResponse(self::PASSWORD)],
            'network' => [new MockResponse('', [
                'error' => 'Failed for "https://api-user:' . self::PASSWORD . '@demo.sendsmaily.net/api/contact.php".',
            ])],
        ];
    }

    /**
     * @dataProvider failures
     */
    public function testNoFailureNamesThePassword(MockResponse $response): void
    {
        $client = $this->client([$response]);

        try {
            $client->post(Client::ENDPOINT_CONTACT, []);
            self::fail('Expected a SmailyClientException');
        } catch (SmailyClientException $exception) {
            for ($failure = $exception; $failure !== null; $failure = $failure->getPrevious()) {
                self::assertStringNotContainsString(self::PASSWORD, $failure->getMessage());
                self::assertStringNotContainsString(base64_encode('api-user:' . self::PASSWORD), $failure->getMessage());
            }
        }
    }

    /**
     * @param list<MockResponse> $responses
     */
    private function client(array $responses): Client
    {
        return new Client(new SymfonyTransport($this->recorder($responses)), 'demo', 'api-user', self::PASSWORD);
    }

    public function testTheLastExchangeHoldsTheRequestBodyAndTheAnswerWithoutItsMessage(): void
    {
        $client = $this->client([
            new MockResponse('{"code": 101, "message": "OK"}'),
            new MockResponse('{"code": 203, "message": "contact@example.test is invalid", "errors": [{"field": "email", "message": "bad"}]}'),
        ]);
        self::assertNull($client->lastExchange());

        $client->post(Client::ENDPOINT_CONTACT, [['email' => 'contact@example.test']]);
        self::assertSame(
            ['request' => [['email' => 'contact@example.test']], 'response' => ['http_status' => 200, 'body' => ['code' => 101]]],
            $client->lastExchange()
        );

        try {
            $client->post(Client::ENDPOINT_CONTACT, [['email' => 'contact@example.test']]);
            self::fail('code 203 is an API error');
        } catch (ApiException $error) {
            self::assertSame(['http_status' => 200, 'body' => ['code' => 203, 'errors' => [['field' => 'email']]]], $client->lastExchange()['response']);
        }
    }

    public function testTheLastExchangeOfARefusalKeepsTheStatusAndOfNoAnswerNothing(): void
    {
        $client = $this->client([
            new MockResponse('<html>Unauthorized</html>', ['http_code' => 401]),
            new MockResponse('', ['error' => 'Could not resolve host']),
        ]);

        try {
            $client->get(Client::ENDPOINT_CONTACT, ['email' => 'contact@example.test']);
            self::fail('401 refuses the request');
        } catch (AuthenticationException $error) {
            self::assertSame(
                ['request' => ['email' => 'contact@example.test'], 'response' => ['http_status' => 401, 'body' => null]],
                $client->lastExchange()
            );
        }

        try {
            $client->post(Client::ENDPOINT_CONTACT, [['email' => 'contact@example.test']]);
            self::fail('no answer is a transport failure');
        } catch (TransportException $error) {
            self::assertSame(['request' => [['email' => 'contact@example.test']], 'response' => null], $client->lastExchange());
        }
        self::assertStringNotContainsString(self::PASSWORD, (string) json_encode($client->lastExchange()));
    }

    /**
     * @param list<MockResponse> $responses
     */
    private function recorder(array $responses): MockHttpClient
    {
        return new MockHttpClient(function (string $method, string $url, array $options) use (&$responses): MockResponse {
            $this->requests[] = [
                'method' => $method,
                'url' => $url,
                'headers' => $options['normalized_headers'],
                'body' => (string) ($options['body'] ?? ''),
            ];

            return array_shift($responses);
        });
    }
}
