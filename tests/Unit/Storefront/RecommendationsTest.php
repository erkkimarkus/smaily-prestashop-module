<?php
declare(strict_types=1);

namespace Tests\Unit\Storefront;

use PHPUnit\Framework\TestCase;
use PrestaShop\Module\SmailyForPrestaShop\Http\NetworkException;
use PrestaShop\Module\SmailyForPrestaShop\Http\Response;
use PrestaShop\Module\SmailyForPrestaShop\Storefront\Recommendations;
use PrestaShop\Module\SmailyForPrestaShop\Storefront\RecommendedProducts;

require_once __DIR__ . '/StorefrontFixture.php';

/**
 * A shopper's recommendations from the engine (contract §15): the request, the cache, and what a
 * failure costs.
 */
final class RecommendationsTest extends TestCase
{
    private const REC = '3fa85f64-5717-4562-b3fc-00000000000';

    private StorefrontFixture $fixture;

    protected function setUp(): void
    {
        $this->fixture = new StorefrontFixture();
        $this->fixture->answer = self::answer(['1', '2-9', '3', '4']);
    }

    public function testTheRequestNamesTheShopperByTheCustomerIdAndAsksForFour(): void
    {
        $slots = $this->recommendations()->slots($this->fixture->connection, 1, Recommendations::BY_CUSTOMER, '1042');

        [$method, $url, $options] = $this->fixture->requests[0];
        self::assertSame('POST', $method);
        self::assertSame('https://intelligence.smaily.com/api/v1/recommendations/customer', $url);
        self::assertSame('Bearer ' . StorefrontFixture::KEY, $options['headers']['Authorization']);
        self::assertSame(['customer_external_id' => '1042', 'limit' => 4], $options['json']);
        self::assertSame(10, $options['timeout']);
        self::assertSame([
            ['rec_id' => self::REC . '1', 'external_id' => '1'],
            ['rec_id' => self::REC . '2', 'external_id' => '2-9'],
            ['rec_id' => self::REC . '3', 'external_id' => '3'],
            ['rec_id' => self::REC . '4', 'external_id' => '4'],
        ], $slots);
    }

    public function testAGuestIsNamedByTheVisitorTokenAndNothingElse(): void
    {
        $this->recommendations()->slots($this->fixture->connection, 1, Recommendations::BY_VISITOR_TOKEN, 'vt_8f3k2a9xq4');

        self::assertSame(['smaily_visitor_token' => 'vt_8f3k2a9xq4', 'limit' => 4], $this->fixture->requests[0][2]['json']);
    }

    public function testAnAnswerIsKeptForAnHourPerShopperAndShop(): void
    {
        $recommendations = $this->recommendations();
        $recommendations->slots($this->fixture->connection, 1, Recommendations::BY_CUSTOMER, '1042');
        $this->fixture->answer = self::answer(['9']);

        $this->fixture->now += 3599;
        self::assertCount(4, $recommendations->slots($this->fixture->connection, 1, Recommendations::BY_CUSTOMER, '1042'));
        self::assertCount(1, $this->fixture->requests, 'served from the cache');

        self::assertCount(1, $recommendations->slots($this->fixture->connection, 1, Recommendations::BY_CUSTOMER, '1043'), 'another shopper');
        self::assertCount(1, $recommendations->slots($this->fixture->connection, 2, Recommendations::BY_CUSTOMER, '1042'), 'another shop');
        self::assertCount(1, $recommendations->slots($this->fixture->connection, 1, Recommendations::BY_VISITOR_TOKEN, '1042'), 'a token that equals an id');
        self::assertCount(4, $this->fixture->requests);

        $this->fixture->now += 1;
        self::assertCount(1, $recommendations->slots($this->fixture->connection, 1, Recommendations::BY_CUSTOMER, '1042'), 'asked again after an hour');
    }

    public function testAnEmptyAnswerIsCachedTooAndNotAskedAgain(): void
    {
        $this->fixture->answer = new Response(200, '{"slots": []}');
        $recommendations = $this->recommendations();

        self::assertSame([], $recommendations->slots($this->fixture->connection, 1, Recommendations::BY_CUSTOMER, '1042'));
        self::assertSame([], $recommendations->slots($this->fixture->connection, 1, Recommendations::BY_CUSTOMER, '1042'));

        self::assertCount(1, $this->fixture->requests);
    }

    public function testNoFileOfTheCacheHoldsTheIdentifier(): void
    {
        $this->recommendations()->slots($this->fixture->connection, 1, Recommendations::BY_VISITOR_TOKEN, 'vt_8f3k2a9xq4');

        foreach (glob($this->fixture->directory() . '/*') ?: [] as $file) {
            self::assertStringNotContainsString('vt_8f3k2a9xq4', basename($file) . file_get_contents($file));
        }
    }

    public function testARefusalIsKeptAsEmptyForTenMinutesAndPausesNobodyElse(): void
    {
        $this->fixture->answer = new Response(401, '{"error": "unauthorised"}');
        $recommendations = $this->recommendations();

        self::assertSame([], $recommendations->slots($this->fixture->connection, 1, Recommendations::BY_CUSTOMER, '1042'));
        $this->fixture->answer = self::answer(['1']);
        self::assertSame([], $recommendations->slots($this->fixture->connection, 1, Recommendations::BY_CUSTOMER, '1042'));
        self::assertCount(1, $this->fixture->requests);

        self::assertCount(1, $recommendations->slots($this->fixture->connection, 1, Recommendations::BY_CUSTOMER, '1043'), 'another shopper is asked about');

        $this->fixture->now += 600;
        self::assertCount(1, $recommendations->slots($this->fixture->connection, 1, Recommendations::BY_CUSTOMER, '1042'));
    }

    /**
     * @dataProvider outages
     *
     * @param Response|\Throwable $outage
     */
    public function testAnEngineThatDoesNotAnswerPausesEveryShoppersRequestForTwoMinutes($outage): void
    {
        $this->fixture->answer = $outage;
        $recommendations = $this->recommendations();

        self::assertSame([], $recommendations->slots($this->fixture->connection, 1, Recommendations::BY_CUSTOMER, '1042'));
        $this->fixture->answer = self::answer(['1']);
        self::assertSame([], $recommendations->slots($this->fixture->connection, 1, Recommendations::BY_CUSTOMER, '1043'));
        self::assertCount(1, $this->fixture->requests, 'the second shopper cost no call');

        $this->fixture->now += 120;
        self::assertCount(1, $recommendations->slots($this->fixture->connection, 1, Recommendations::BY_CUSTOMER, '1043'));
    }

    /**
     * @return iterable<string, array{Response|\Throwable}>
     */
    public static function outages(): iterable
    {
        yield 'no answer' => [NetworkException::of(new \RuntimeException('timeout'))];
        yield 'a 503' => [new Response(503, '{"error": "unavailable"}')];
    }

    public function testASlotWithoutAWellFormedIdOrAProductIsLeftOut(): void
    {
        $this->fixture->answer = new Response(200, (string) json_encode(['slots' => [
            ['rec_id' => 'rec_abc123', 'external_id' => '1'],
            ['rec_id' => self::REC . '2', 'external_id' => null, 'sku' => 'some-key'],
            'text',
            ['rec_id' => self::REC . '4', 'external_id' => 4],
        ]]));

        self::assertSame(
            [['rec_id' => self::REC . '4', 'external_id' => '4']],
            $this->recommendations()->slots($this->fixture->connection, 1, Recommendations::BY_CUSTOMER, '1042')
        );
    }

    public function testNoMoreThanFourSlotsAreKept(): void
    {
        $this->fixture->answer = self::answer(['1', '2', '3', '4', '5', '6']);

        self::assertCount(4, $this->recommendations()->slots($this->fixture->connection, 1, Recommendations::BY_CUSTOMER, '1042'));
    }

    public function testOnAConnectionWithAPrefixOnlyASlotUnderThePrefixIsThisStores(): void
    {
        $connection = StorefrontFixture::connection(['config' => ['connection' => ['prefix' => 'c2-']]]);
        $this->fixture->answer = new Response(200, (string) json_encode(['slots' => [
            ['rec_id' => self::REC . '1', 'external_id' => '1', 'sku' => 'c2-one'],
            ['rec_id' => self::REC . '2', 'external_id' => '2', 'sku' => 'c3-two'],
            ['rec_id' => self::REC . '3', 'external_id' => '3', 'sku' => 'three'],
        ]]));

        self::assertSame(
            [['rec_id' => self::REC . '1', 'external_id' => '1']],
            $this->recommendations()->slots($connection, 1, Recommendations::BY_CUSTOMER, '1042')
        );
    }

    public function testALandingLinkCarriesItsParametersBeforeTheFragment(): void
    {
        $parameters = ['smaily_rec' => self::REC . '1', 'smaily_ctx' => RecommendedProducts::CONTEXT];
        $query = 'smaily_rec=' . self::REC . '1&smaily_ctx=storefront';

        self::assertSame('https://shop.example.test/p/1.html?' . $query, RecommendedProducts::landing('https://shop.example.test/p/1.html', $parameters));
        self::assertSame('https://shop.example.test/p/1.html?' . $query . '#/1-size-s', RecommendedProducts::landing('https://shop.example.test/p/1.html#/1-size-s', $parameters));
        self::assertSame('https://shop.example.test/index.php?id_product=1&' . $query, RecommendedProducts::landing('https://shop.example.test/index.php?id_product=1', $parameters));
    }

    /**
     * @param list<string> $externalIds
     */
    private static function answer(array $externalIds): Response
    {
        $slots = [];
        foreach ($externalIds as $index => $externalId) {
            $slots[] = ['position' => $index + 1, 'rec_id' => self::REC . ($index + 1), 'sku' => 'key-' . $externalId, 'external_id' => $externalId];
        }

        return new Response(200, (string) json_encode(['slots' => $slots]));
    }

    private function recommendations(): Recommendations
    {
        return new Recommendations($this->fixture->client(), $this->fixture->store(), 'test-secret');
    }
}
