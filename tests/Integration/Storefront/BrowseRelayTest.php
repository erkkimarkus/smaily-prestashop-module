<?php
declare(strict_types=1);

namespace Tests\Integration\Storefront;

use PrestaShop\Module\SmailyForPrestaShop\Engine\Client;
use PrestaShop\Module\SmailyForPrestaShop\Engine\Exception\EngineRequestException;
use PrestaShop\Module\SmailyForPrestaShop\Engine\Payload\Keys;
use PrestaShop\Module\SmailyForPrestaShop\Storefront\Cookies;
use PrestaShop\Module\SmailyForPrestaShop\Storefront\Relay;

require_once __DIR__ . '/StorefrontTestCase.php';

/**
 * The browse relay against the engine mock: what reaches the engine has the shapes of contract
 * §6, carries the connection's key, and names the shopper only by the visitor token of the
 * request's own cookie. The mock's two storefront routes answer as §6 and §15 state.
 */
final class BrowseRelayTest extends StorefrontTestCase
{
    private const BROWSE = '/api/v1/ingest/browse';

    private const SESSION = '0f8fad5b-d9cb-469f-a165-70867728950e';

    /** §6 "Response 200 OK". */
    private const BROWSE_ANSWER = ['ok', 'processed', 'deduplicated', 'errors', 'with_customer_match', 'anonymous', 'retroactive_bound', 'duplicates_skipped'];

    /** §15 "Response 200 OK": the fields of a slot. */
    private const SLOT = ['position', 'rec_id', 'sku', 'external_id', 'name', 'description', 'price', 'compare_price', 'in_stock', 'image_url', 'product_url', 'headline', 'cta', 'reason'];

    public function testABatchReachesTheEngineWithTheConnectionsKeyInTheContractsShape(): void
    {
        $this->connect();

        [$status, $answer] = $this->relay([
            self::event('product_view', '1', ['product_id' => '1', 'product_attribute_id' => '1']),
            self::event('search', '2', ['search_query' => 'mug']),
            self::event('cart_add', '3', ['product_id' => '6']),
        ]);

        self::assertSame(200, $status);
        self::assertSame(['ok' => true, 'accepted' => 3], $answer);
        $requests = $this->engine->requestsTo(self::BROWSE);
        self::assertCount(1, $requests);
        self::assertSame('POST', $requests[0]['method']);
        self::assertSame('Bearer ' . array_key_first($this->engine->connections()), $requests[0]['authorization']);
        self::assertSame(['events'], array_keys($requests[0]['body']));
        foreach ($requests[0]['body']['events'] as $event) {
            self::assertSame([], array_diff(array_keys($event), ['event_id', 'session_id', 'event_type', 'event_ts', 'search_query', 'sku']));
            self::assertMatchesRegularExpression('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$/', $event['event_ts']);
            self::assertSame(self::SESSION, $event['session_id']);
        }
        self::assertSame(['product_view', 'search', 'cart_add'], array_column($requests[0]['body']['events'], 'event_type'));
    }

    public function testTheBrowserNeverSeesTheKeyAndTheEngineNeverSeesAnAddress(): void
    {
        $this->connect();
        $key = (string) array_key_first($this->engine->connections());

        [, $answer] = $this->relay([self::event('search', '1', ['search_query' => 'mug'])]);

        self::assertStringNotContainsString($key, (string) json_encode($answer));
        self::assertStringNotContainsString('@', $this->engine->requestsTo(self::BROWSE)[0]['raw']);
    }

    public function testABatchThatNamesTheShopperReachesNoEngine(): void
    {
        $this->connect();

        [$status, $answer] = $this->relay([self::event('search', '1', ['customer_email' => 'shopper@example.test'])]);

        self::assertSame(400, $status);
        self::assertSame('identity_not_accepted', $answer['error']);
        self::assertSame([], $this->engine->requestsTo(self::BROWSE));
    }

    public function testAnEngineLinksTokenTravelsFromTheCookieAndTheEngineMatchesTheCustomer(): void
    {
        $this->connect();
        $this->engine->set('visitor_tokens', ['vt_8f3k2a9xq4' => '1042']);

        $this->relay([self::event('search', '1')], ['smaily_rec_uid' => 'vt_8f3k2a9xq4']);

        self::assertSame('vt_8f3k2a9xq4', $this->engine->requestsTo(self::BROWSE)[0]['body']['events'][0]['smaily_visitor_token']);
    }

    public function testAStoreCreatedTokenTravelsOnlyWithTheConsentSettingAndTheCustomersOwnConsent(): void
    {
        $this->connect();
        $cookies = ['smaily_rec_uid' => self::STORE_TOKEN];
        $subscriber = new \Customer($this->fixture->customer('relay-subscriber@example.test', false, true));
        $other = new \Customer($this->fixture->customer('relay-other@example.test', false, false));

        $this->relay([self::event('search', '1')], $cookies);
        $this->confirmConsent();
        $this->relay([self::event('search', '2')], $cookies);
        $this->relay([self::event('search', '3')], $cookies, $subscriber);
        $this->relay([self::event('search', '4')], $cookies, $other);

        $sent = array_map(
            static fn (array $request): ?string => $request['body']['events'][0]['smaily_visitor_token'] ?? null,
            $this->engine->requestsTo(self::BROWSE)
        );
        self::assertSame([null, self::STORE_TOKEN, self::STORE_TOKEN, null], $sent);
    }

    public function testAnEventNamesItsProductByTheKeyOfTheCatalogRow(): void
    {
        $this->engine->set('next_prefix', 'c7-');
        $connection = $this->connect();
        self::assertSame('c7-', $connection->prefix());

        $this->relay([
            self::event('product_view', '1', ['product_id' => '1', 'product_attribute_id' => '2']),
            self::event('product_view', '2', ['product_id' => '6']),
            self::event('search', '3'),
        ]);

        $events = $this->engine->requestsTo(self::BROWSE)[0]['body']['events'];
        self::assertSame(
            [Keys::sku('c7-', 1, 2), Keys::sku('c7-', 6)],
            [$events[0]['sku'], $events[1]['sku']],
            'the key that the catalog row of the combination, and of a product without one, carries'
        );
        self::assertSame(['c7-ps-1-2', 'c7-ps-6'], [$events[0]['sku'], $events[1]['sku']]);
        self::assertArrayNotHasKey('sku', $events[2], 'an event without a product has no key');
    }

    public function testAShopWithoutAConnectionOrWithARefusedOneRelaysNothing(): void
    {
        self::assertSame(404, $this->relay([self::event('search', '1')])[0]);

        $connection = $this->connect();
        $this->connections()->saveSettings($connection, ['refused_at' => '2026-11-21T09:00:00Z'] + $connection->settings());

        self::assertSame(404, $this->relay([self::event('search', '2')])[0]);
        self::assertSame([], $this->engine->requestsTo(self::BROWSE));
    }

    public function testAnEngineThatIsAwayCostsTheEventsNotTheRequest(): void
    {
        $this->connect();
        $this->engine->fail('browse', 503);

        self::assertSame([200, ['ok' => true, 'accepted' => 1]], $this->relay([self::event('search', '1')]));
        self::assertCount(1, $this->engine->requestsTo(self::BROWSE), 'one attempt');
    }

    public function testTheMockValidatesEachEventOnItsOwnAndCountsAnEventOnce(): void
    {
        $connection = $this->connect();
        $client = $this->services->get(Client::class);
        $event = ['event_id' => '7d8f3a2b-4e1c-4d8a-9b2f-000000000001', 'session_id' => self::SESSION, 'event_type' => 'search', 'event_ts' => '2026-11-21T10:00:00Z'];

        $answer = $client->relayBrowse($connection, [
            $event,
            ['event_type' => 'page_view'] + $event,
            ['event_id' => 'none'] + $event,
        ]);
        self::assertSame(self::BROWSE_ANSWER, array_keys($answer));
        self::assertSame(1, $answer['processed']);
        self::assertSame(['event_type', 'event_id'], array_column($answer['errors'], 'field'));
        self::assertSame([1, 2], array_column($answer['errors'], 'index'));
        self::assertSame(1, $answer['anonymous']);

        $repeat = $client->relayBrowse($connection, [$event]);
        self::assertSame([0, 1, 1, true], [$repeat['processed'], $repeat['deduplicated'], $repeat['duplicates_skipped'], $repeat['deduplicated_all']]);
    }

    public function testTheMockRefusesAnEmptyBatchAndASkuOutsideThePrefix(): void
    {
        $this->engine->set('next_prefix', 'c2-');
        $connection = $this->connect();
        $client = $this->services->get(Client::class);
        $event = ['event_id' => '7d8f3a2b-4e1c-4d8a-9b2f-000000000001', 'session_id' => self::SESSION, 'event_type' => 'product_view', 'event_ts' => '2026-11-21T10:00:00Z'];

        $answer = $client->relayBrowse($connection, [['sku' => 'other-1'] + $event, ['sku' => 'c2-1', 'event_id' => '7d8f3a2b-4e1c-4d8a-9b2f-000000000002'] + $event]);
        self::assertSame([['index' => 0, 'field' => 'sku', 'message' => 'Invalid']], $answer['errors']);
        self::assertSame(1, $answer['processed']);

        try {
            $client->relayBrowse($connection, []);
            self::fail('An empty batch was taken.');
        } catch (EngineRequestException $refusal) {
            self::assertSame([400, 'validation_failed'], [$refusal->httpStatus(), $refusal->errorCode()]);
        }
    }

    public function testTheMockAnswersAShoppersRecommendationsAsTheContractStates(): void
    {
        $connection = $this->connect();
        $client = $this->services->get(Client::class);
        $this->engine->set('recommendations', [
            'customer:1042' => [['external_id' => '1'], ['external_id' => '2-9'], ['external_id' => '3']],
            'token:vt_8f3k2a9xq4' => [['external_id' => '6']],
        ]);

        $answer = $client->recommendations($connection, ['customer_external_id' => '1042'], 2);
        self::assertSame(['slots'], array_keys($answer));
        self::assertCount(2, $answer['slots'], 'up to the limit');
        self::assertSame(self::SLOT, array_keys($answer['slots'][0]));
        self::assertSame([1, 2], array_column($answer['slots'], 'position'));
        self::assertStringEndsWith('?smaily_rec=' . $answer['slots'][0]['rec_id'] . '&smaily_ctx=storefront', $answer['slots'][0]['product_url']);

        self::assertSame('6', $client->recommendations($connection, ['smaily_visitor_token' => 'vt_8f3k2a9xq4'], 4)['slots'][0]['external_id']);
        self::assertSame(['slots' => []], $client->recommendations($connection, ['customer_external_id' => '7'], 4), 'an unknown shopper');
        self::assertSame(['slots' => []], $client->recommendations($connection, ['smaily_visitor_token' => 'vt_unknown01'], 4));

        try {
            $client->recommendations($connection, [], 4);
            self::fail('A request without an identifier was taken.');
        } catch (EngineRequestException $refusal) {
            self::assertSame([400, 'validation_failed'], [$refusal->httpStatus(), $refusal->errorCode()]);
        }
        $last = $this->engine->requestsTo('/api/v1/recommendations/customer');
        self::assertSame(['customer_external_id' => '1042', 'limit' => 2], $last[0]['body']);
    }

    /**
     * @param array<string, mixed> $fields
     *
     * @return array<string, mixed>
     */
    private static function event(string $type, string $number, array $fields = []): array
    {
        return $fields + ['event_id' => sprintf('7d8f3a2b-4e1c-4d8a-9b2f-%012d', (int) $number), 'session_id' => self::SESSION, 'event_type' => $type];
    }

    /**
     * @param list<array<string, mixed>> $events
     * @param array<string, string> $cookies
     *
     * @return array{int, array<string, mixed>}
     */
    private function relay(array $events, array $cookies = [], ?\Customer $customer = null): array
    {
        return $this->services->get(Relay::class)->handle(
            1,
            'POST',
            (string) json_encode(['events' => $events]),
            new Cookies($cookies, '/', false, static fn (): bool => true),
            $customer
        );
    }
}
