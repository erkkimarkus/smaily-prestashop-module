<?php
declare(strict_types=1);

namespace Tests\Unit\Storefront;

use PHPUnit\Framework\TestCase;
use PrestaShop\Module\SmailyForPrestaShop\Engine\Connection;
use PrestaShop\Module\SmailyForPrestaShop\Http\NetworkException;
use PrestaShop\Module\SmailyForPrestaShop\Http\Response;
use PrestaShop\Module\SmailyForPrestaShop\Storefront\BrowseEvents;
use PrestaShop\Module\SmailyForPrestaShop\Storefront\Relay;

require_once __DIR__ . '/StorefrontFixture.php';

/**
 * The browse relay: what it refuses, and what leaves the store for the engine (contract §6).
 */
final class RelayTest extends TestCase
{
    private const SESSION = '0f8fad5b-d9cb-469f-a165-70867728950e';

    private StorefrontFixture $fixture;

    /** @var callable(Connection, int, int): ?string */
    private $productKey;

    protected function setUp(): void
    {
        $this->fixture = new StorefrontFixture();
        $this->productKey = static fn (): ?string => null;
    }

    public function testAValidBatchIsForwardedWithTheServerSideKeyInTheContractsShape(): void
    {
        [$status, $answer] = $this->handle([self::event('product_view'), self::event('search', ['search_query' => 'mug'])]);

        self::assertSame(200, $status);
        self::assertSame(['ok' => true, 'accepted' => 2], $answer);
        self::assertCount(1, $this->fixture->requests);
        [$method, $url, $options] = $this->fixture->requests[0];
        self::assertSame('POST', $method);
        self::assertSame('https://intelligence.smaily.com/api/v1/ingest/browse', $url);
        self::assertSame('Bearer ' . StorefrontFixture::KEY, $options['headers']['Authorization']);
        self::assertSame(3, $options['timeout']);
        self::assertSame(3, $options['max_duration']);
        self::assertSame(['events'], array_keys($options['json']));
        self::assertSame(['event_id', 'session_id', 'event_type', 'event_ts'], array_keys($options['json']['events'][0]));
        self::assertSame(gmdate('Y-m-d\TH:i:s\Z', StorefrontFixture::NOW), $options['json']['events'][0]['event_ts']);
        self::assertSame('mug', $options['json']['events'][1]['search_query']);
    }

    /**
     * @dataProvider identityFields
     */
    public function testABatchThatNamesTheShopperIsRefusedAndNothingOfItIsForwarded(string $field): void
    {
        [$status, $answer] = $this->handle([self::event('product_view'), self::event('search', [$field => 'shopper@example.test'])]);

        self::assertSame(400, $status);
        self::assertSame(['ok' => false, 'error' => 'identity_not_accepted'], $answer);
        self::assertSame([], $this->fixture->requests);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function identityFields(): iterable
    {
        foreach (BrowseEvents::IDENTITY_FIELDS as $field) {
            yield $field => [$field];
        }
    }

    public function testTheVisitorTokenComesFromTheRequestsCookieOnly(): void
    {
        $this->handle([self::event('product_view')], ['smaily_rec_uid' => 'vt_8f3k2a9xq4']);

        self::assertSame('vt_8f3k2a9xq4', $this->fixture->requests[0][2]['json']['events'][0]['smaily_visitor_token']);
    }

    public function testAStoreCreatedTokenIsForwardedOnlyAfterTheMerchantConfirmedTheBanner(): void
    {
        $cookies = ['smaily_rec_uid' => 'vs_0123456789ABCDEFGHIJKL'];

        $this->handle([self::event('product_view')], $cookies);
        self::assertArrayNotHasKey('smaily_visitor_token', $this->fixture->requests[0][2]['json']['events'][0]);

        $this->fixture->consentConfirmed = true;
        $this->handle([self::event('product_view')], $cookies);
        self::assertSame('vs_0123456789ABCDEFGHIJKL', $this->fixture->requests[1][2]['json']['events'][0]['smaily_visitor_token']);
    }

    public function testWithoutACookieTheEventsAreAnonymous(): void
    {
        $this->handle([self::event('cart_add')]);

        self::assertArrayNotHasKey('smaily_visitor_token', $this->fixture->requests[0][2]['json']['events'][0]);
    }

    public function testTheProductBecomesTheEnginesKeyWhereTheStoreHasOne(): void
    {
        $asked = [];
        $this->productKey = static function (Connection $connection, int $idProduct, int $idAttribute) use (&$asked): ?string {
            $asked[] = [$connection->id(), $idProduct, $idAttribute];

            return $idProduct === 12 ? 'key-of-12-' . $idAttribute : null;
        };

        $this->handle([
            self::event('product_view', ['product_id' => '12', 'product_attribute_id' => '34']),
            self::event('cart_add', ['product_id' => '13']),
            self::event('search'),
        ]);

        $events = $this->fixture->requests[0][2]['json']['events'];
        self::assertSame([[7, 12, 34], [7, 13, 0]], $asked);
        self::assertSame('key-of-12-34', $events[0]['sku']);
        self::assertArrayNotHasKey('sku', $events[1]);
        self::assertArrayNotHasKey('product_id', $events[0]);
        self::assertArrayNotHasKey('product_attribute_id', $events[0]);
    }

    public function testInvalidEventsAreDroppedAndABatchWithoutAValidOneIsRefused(): void
    {
        [$status, $answer] = $this->handle([self::event('page_view'), 'text', self::event('search')]);
        self::assertSame(200, $status);
        self::assertSame(1, $answer['accepted']);

        [$status, $answer] = $this->handle([self::event('page_view')]);
        self::assertSame(400, $status);
        self::assertSame('no_valid_events', $answer['error']);
        self::assertCount(1, $this->fixture->requests);
    }

    public function testAtMost100EventsOfARequestAreForwarded(): void
    {
        $events = [];
        for ($i = 0; $i < 120; ++$i) {
            $events[] = self::event('search', ['event_id' => sprintf('7d8f3a2b-4e1c-4d8a-9b2f-%012d', $i)]);
        }

        [, $answer] = $this->handle($events);

        self::assertSame(100, $answer['accepted']);
        self::assertCount(100, $this->fixture->requests[0][2]['json']['events']);
    }

    /**
     * @dataProvider badBodies
     */
    public function testABodyWithoutAnEventsListIsRefused(string $body): void
    {
        [$status, $answer] = $this->relay()->handle(1, 'POST', $body, $this->fixture->cookies(), null);

        self::assertSame(400, $status);
        self::assertSame('events_required', $answer['error']);
        self::assertSame([], $this->fixture->requests);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function badBodies(): iterable
    {
        yield 'empty' => [''];
        yield 'not JSON' => ['events=1'];
        yield 'no events' => ['{}'];
        yield 'an empty list' => ['{"events": []}'];
        yield 'events that are text' => ['{"events": "all"}'];
    }

    public function testOtherMethodsAndAShopWithoutAUsableConnectionAreRefused(): void
    {
        self::assertSame(405, $this->relay()->handle(1, 'GET', '', $this->fixture->cookies(), null)[0]);

        $this->fixture->connection = StorefrontFixture::connection(['refused_at' => '2026-11-21T09:00:00Z']);
        self::assertSame(404, $this->handle([self::event('search')])[0], 'the engine refuses the connection');

        $this->fixture->connection = new Connection(7, 0, '', 'tenant-1', '', []);
        self::assertSame(404, $this->handle([self::event('search')])[0], 'the stored key does not decrypt');

        $this->fixture->connection = null;
        self::assertSame(404, $this->handle([self::event('search')])[0], 'no connection');
        self::assertSame([], $this->fixture->requests);
    }

    public function testAnAddressIsLimitedPerMinute(): void
    {
        $relay = $this->relay();
        $body = (string) json_encode(['events' => [self::event('search')]]);
        for ($i = 0; $i < Relay::LIMIT_PER_MINUTE; ++$i) {
            self::assertSame(200, $relay->handle(1, 'POST', $body, $this->fixture->cookies(), null)[0]);
        }

        [$status, $answer] = $relay->handle(1, 'POST', $body, $this->fixture->cookies(), null);

        self::assertSame(429, $status);
        self::assertSame('rate_limited', $answer['error']);
        self::assertCount(Relay::LIMIT_PER_MINUTE, $this->fixture->requests);

        $this->fixture->now += 60;
        self::assertSame(200, $relay->handle(1, 'POST', $body, $this->fixture->cookies(), null)[0]);
    }

    /**
     * @dataProvider engineFailures
     *
     * @param Response|\Throwable $failure
     */
    public function testAnEngineThatFailsLosesTheEventsAndTheBrowserStillGets200($failure): void
    {
        $this->fixture->answer = $failure;

        [$status, $answer] = $this->handle([self::event('search')]);

        self::assertSame(200, $status);
        self::assertSame(['ok' => true, 'accepted' => 1], $answer);
        self::assertCount(1, $this->fixture->requests, 'one attempt, no retry');
    }

    /**
     * @return iterable<string, array{Response|\Throwable}>
     */
    public static function engineFailures(): iterable
    {
        yield 'no answer' => [NetworkException::of(new \RuntimeException('timeout'))];
        yield 'a 500' => [new Response(500, '{"error": "internal_error"}')];
        yield 'a 401' => [new Response(401, '{"error": "unauthorised"}')];
        yield 'a 429' => [new Response(429, '{"error": "rate_limited"}')];
    }

    /**
     * @param array<string, mixed> $fields
     *
     * @return array<string, mixed>
     */
    private static function event(string $type, array $fields = []): array
    {
        return $fields + ['event_id' => '7d8f3a2b-4e1c-4d8a-9b2f-7e3d1a6c8b9e', 'session_id' => self::SESSION, 'event_type' => $type];
    }

    /**
     * @param array<mixed> $events
     * @param array<string, string> $cookies
     *
     * @return array{int, array<string, mixed>}
     */
    private function handle(array $events, array $cookies = []): array
    {
        return $this->relay()->handle(1, 'POST', (string) json_encode(['events' => $events]), $this->fixture->cookies($cookies), null);
    }

    private function relay(): Relay
    {
        return new Relay(
            $this->fixture->connections(),
            $this->fixture->limiter(),
            $this->fixture->attribution(),
            $this->fixture->client(),
            $this->productKey,
            fn (): int => $this->fixture->now
        );
    }
}
