<?php
declare(strict_types=1);

namespace Tests\Unit\Storefront;

use PHPUnit\Framework\TestCase;
use PrestaShop\Module\SmailyForPrestaShop\Storefront\BrowseEvents;

/**
 * What the relay keeps of an event that a browser posted (contract §6).
 */
final class BrowseEventsTest extends TestCase
{
    private const NOW = '2026-11-21T10:00:00Z';

    private const EVENT = [
        'event_id' => '7D8F3A2B-4E1C-4D8A-9B2F-7E3D1A6C8B9E',
        'session_id' => '0f8fad5b-d9cb-469f-a165-70867728950e',
        'event_type' => 'product_view',
    ];

    public function testAValidEventKeepsItsIdSessionAndTypeAndGetsTheServersTime(): void
    {
        $clean = BrowseEvents::clean(self::EVENT + ['event_ts' => '1999-01-01T00:00:00Z', 'source' => 'made-up'], self::NOW);

        self::assertSame([
            'event' => [
                'event_id' => '7d8f3a2b-4e1c-4d8a-9b2f-7e3d1a6c8b9e',
                'session_id' => '0f8fad5b-d9cb-469f-a165-70867728950e',
                'event_type' => 'product_view',
                'event_ts' => self::NOW,
            ],
            'product' => null,
        ], $clean);
    }

    public function testTheProductComesAsTheStoresOwnIdsNeverAsAKey(): void
    {
        $clean = BrowseEvents::clean(self::EVENT + ['product_id' => '12', 'product_attribute_id' => 34, 'sku' => 'made-up-key'], self::NOW);

        self::assertSame([12, 34], $clean['product']);
        self::assertArrayNotHasKey('sku', $clean['event']);
        self::assertArrayNotHasKey('product_id', $clean['event']);

        self::assertSame([12, 0], BrowseEvents::clean(self::EVENT + ['product_id' => 12], self::NOW)['product']);
        self::assertNull(BrowseEvents::clean(self::EVENT + ['product_id' => '12 OR 1=1'], self::NOW)['product']);
        self::assertNull(BrowseEvents::clean(self::EVENT + ['product_id' => ['12']], self::NOW)['product']);
    }

    public function testThePagesOwnFieldsAreKeptAndEveryOtherKeyIsDropped(): void
    {
        $clean = BrowseEvents::clean([
            'event_type' => 'search',
            'search_query' => '  mug ',
            'category_path' => 'home/mugs',
            'dwell_seconds' => '45',
            'smaily_rec_id' => 'not-a-uuid',
            'smaily_ctx' => 'welcome',
            'anything' => 'else',
        ] + self::EVENT, self::NOW)['event'];

        self::assertSame(['event_id', 'session_id', 'event_type', 'event_ts', 'category_path', 'search_query', 'dwell_seconds'], array_keys($clean));
        self::assertSame('mug', $clean['search_query']);
        self::assertSame(45, $clean['dwell_seconds']);
    }

    public function testATextThatIsTooLongOrNoTextIsLeftOut(): void
    {
        $clean = BrowseEvents::clean(self::EVENT + ['search_query' => str_repeat('a', 256), 'category_path' => ['a']], self::NOW)['event'];

        self::assertArrayNotHasKey('search_query', $clean);
        self::assertArrayNotHasKey('category_path', $clean);
    }

    /**
     * @dataProvider droppedEvents
     *
     * @param array<string, mixed> $change
     */
    public function testAnEventWithoutAUuidASessionOrAKnownTypeIsDropped(array $change): void
    {
        self::assertNull(BrowseEvents::clean($change + self::EVENT, self::NOW));
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function droppedEvents(): iterable
    {
        yield 'no event id' => [['event_id' => '']];
        yield 'an id that is no UUID v4' => [['event_id' => '7d8f3a2b-4e1c-1d8a-9b2f-7e3d1a6c8b9e']];
        yield 'an id that is a list' => [['event_id' => ['7d8f3a2b-4e1c-4d8a-9b2f-7e3d1a6c8b9e']]];
        yield 'no session' => [['session_id' => null]];
        yield 'a session that is no plain token' => [['session_id' => 'a session; DROP']];
        yield 'a session that is too long' => [['session_id' => str_repeat('a', 65)]];
        yield 'an unknown type' => [['event_type' => 'page_view']];
    }

    public function testEveryEventTypeOfTheContractIsKept(): void
    {
        foreach (['product_view', 'category_view', 'search', 'cart_add', 'cart_remove', 'wishlist_add', 'wishlist_remove', 'checkout_start', 'checkout_complete'] as $type) {
            self::assertNotNull(BrowseEvents::clean(['event_type' => $type] + self::EVENT, self::NOW), $type);
        }
    }

    /**
     * @dataProvider identityFields
     */
    public function testABatchInWhichAnEventNamesTheShopperIsFound(string $field): void
    {
        self::assertTrue(BrowseEvents::nameIdentity([self::EVENT, self::EVENT + [$field => 'x']]));
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

    public function testTheModulesOwnEventsNameNobody(): void
    {
        self::assertFalse(BrowseEvents::nameIdentity([
            self::EVENT + ['product_id' => '1', 'product_attribute_id' => '2'],
            ['event_type' => 'search', 'search_query' => 'mug'] + self::EVENT,
            'not an event',
        ]));
        self::assertContains('customer_email', BrowseEvents::IDENTITY_FIELDS);
        self::assertContains('external_id', BrowseEvents::IDENTITY_FIELDS);
        self::assertContains('smaily_visitor_token', BrowseEvents::IDENTITY_FIELDS);
    }
}
