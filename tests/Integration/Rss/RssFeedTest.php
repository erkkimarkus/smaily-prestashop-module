<?php
declare(strict_types=1);

namespace Tests\Integration\Rss;

use Tests\Integration\IntegrationTestCase;

/**
 * The product feed that merchants' Smaily templates embed, fetched over HTTP from the shop's web
 * server as Smaily fetches it: its 2.x URL and parameters (`category_id`, `limit` 1–250 default
 * 50, `sort_by`, `sort_order`) answer with the products that 2.2.0's query returns, in its order,
 * and every item carries the 2.2.0 fields — title, link, guid, pubDate, description, the cover
 * enclosure, smly:price, and smly:old_price with smly:discount for a discounted product.
 *
 * The request goes to the shop's own web server inside its container (127.0.0.1:80) with the
 * shop's domain as its Host, so it runs the front controller exactly as an outside request does.
 */
final class RssFeedTest extends IntegrationTestCase
{
    private const FEED = '/index.php?fc=module&module=smailyforprestashop&controller=SmailyRssFeed';

    private const ITEM_FIELDS = ['title', 'link', 'guid', 'pubDate', 'description', 'enclosure', 'smly:price'];

    public function testTheFeedWithoutParametersAnswersTheNewest50UpdatedProducts(): void
    {
        [$status, $xml] = $this->fetch('');

        self::assertSame(200, $status);
        self::assertSame(['version' => '2.0'], ['version' => (string) $xml['version']]);
        self::assertSame($this->expectedTitles(false, 50, 'date_upd', 'desc'), $this->titles($xml));
    }

    public function testTheLegacyParametersFilterSortAndLimitAs2Point2Point0Did(): void
    {
        [, $byDate] = $this->fetch('&limit=5&sort_by=date_add&sort_order=asc');
        self::assertSame($this->expectedTitles(false, 5, 'date_add', 'asc'), $this->titles($byDate));

        [, $byId] = $this->fetch('&limit=250&sort_by=id_product&sort_order=asc');
        self::assertSame($this->expectedTitles(false, 250, 'id_product', 'asc'), $this->titles($byId));

        $category = (int) self::db()->getValue(
            'SELECT cp.`id_category` FROM `' . _DB_PREFIX_ . 'category_product` cp'
            . ' INNER JOIN `' . _DB_PREFIX_ . 'product` p ON p.`id_product` = cp.`id_product` AND p.`active` = 1'
            . ' WHERE cp.`id_category` > 2 GROUP BY cp.`id_category` ORDER BY COUNT(*) DESC',
            false
        );
        self::assertGreaterThan(0, $category, 'the catalog has a category with products');
        [, $inCategory] = $this->fetch('&category_id=' . $category . '&sort_by=name&sort_order=asc');
        self::assertSame($this->expectedTitles($category, 50, 'name', 'asc'), $this->titles($inCategory));
    }

    public function testAValueOutsideTheRangeFallsBackToTheFeedsDefault(): void
    {
        [$status, $xml] = $this->fetch('&limit=999&sort_by=reference&sort_order=up&category_id=-4');

        self::assertSame(200, $status);
        self::assertSame($this->expectedTitles(false, 50, 'date_upd', 'desc'), $this->titles($xml));
    }

    public function testEveryItemCarriesThe2Point2Point0FieldsAndADiscountedOneItsOldPrice(): void
    {
        [, $xml] = $this->fetch('&limit=250&sort_by=id_product&sort_order=asc');
        $items = $xml->channel->item;
        self::assertGreaterThan(0, count($items));

        $discounted = 0;
        foreach ($items as $item) {
            $names = [];
            foreach ($item->children() as $child) {
                $names[] = $child->getName();
            }
            foreach ($item->children('smly', true) as $child) {
                $names[] = 'smly:' . $child->getName();
            }
            self::assertSame([], array_values(array_diff(self::ITEM_FIELDS, $names)), 'item ' . $item->title);
            self::assertSame('true', (string) $item->guid['isPermaLink']);
            self::assertSame((string) $item->link, (string) $item->guid);
            self::assertMatchesRegularExpression('~^https?://.+/img/p/.+\.\w+$~', (string) $item->enclosure['url']);
            self::assertMatchesRegularExpression('~^image/\w+$~', (string) $item->enclosure['type']);
            $smly = $item->children('smly', true);
            self::assertMatchesRegularExpression('~^\d+\.\d{2}\D+$~u', (string) $smly->price, 'price with the currency sign, as 2.x');
            self::assertSame(isset($smly->old_price), isset($smly->discount), 'old price and discount come together');
            if (isset($smly->discount)) {
                ++$discounted;
                self::assertMatchesRegularExpression('~^\d+%$~', (string) $smly->discount);
                self::assertGreaterThan((float) $smly->price, (float) $smly->old_price);
            }
        }
        $reductions = (int) self::db()->getValue(
            'SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'specific_price` sp INNER JOIN `' . _DB_PREFIX_ . 'product` p'
            . ' ON p.`id_product` = sp.`id_product` AND p.`active` = 1 WHERE sp.`reduction` > 0 AND sp.`id_customer` = 0'
            . ' AND sp.`id_group` = 0 AND sp.`from_quantity` <= 1',
            false
        );
        if ($reductions > 0) {
            self::assertGreaterThan(0, $discounted, 'a discounted product carries smly:old_price and smly:discount');
        }
    }

    /**
     * @return array{0: int, 1: \SimpleXMLElement}
     */
    private function fetch(string $query): array
    {
        $context = stream_context_create(['http' => [
            'header' => 'Host: ' . \Configuration::get('PS_SHOP_DOMAIN'),
            'ignore_errors' => true,
            'follow_location' => 0,
            'timeout' => 30,
        ]]);
        $body = @file_get_contents('http://127.0.0.1' . self::FEED . $query, false, $context);
        if ($body === false) {
            self::markTestSkipped('The shop\'s web server does not answer on 127.0.0.1:80 here.');
        }
        $status = (int) (explode(' ', (string) ($http_response_header[0] ?? ''))[1] ?? 0);
        $xml = simplexml_load_string((string) $body, \SimpleXMLElement::class, LIBXML_NOCDATA);
        self::assertInstanceOf(\SimpleXMLElement::class, $xml, 'the feed is an XML document');
        self::assertSame('rss', $xml->getName());

        return [$status, $xml];
    }

    /**
     * The titles that 2.2.0's query returns (Model\RssFeedProductsCollection, unchanged since).
     *
     * @param int|false $category
     *
     * @return list<string>
     */
    private function expectedTitles($category, int $limit, string $sortBy, string $sortOrder): array
    {
        // The feed runs in a front controller, which hides products not visible in the catalog.
        $context = \Context::getContext()->cloneContext();
        $context->controller = new \stdClass();
        $context->controller->controller_type = 'modulefront';
        $products = \Product::getProducts((int) \Configuration::get('PS_LANG_DEFAULT'), 0, $limit, $sortBy, $sortOrder, $category, true, $context);

        return array_map(static fn (array $product): string => (string) $product['name'], $products);
    }

    /**
     * @return list<string>
     */
    private function titles(\SimpleXMLElement $xml): array
    {
        $titles = [];
        foreach ($xml->channel->item as $item) {
            $titles[] = (string) $item->title;
        }

        return $titles;
    }
}
