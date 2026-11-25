<?php
declare(strict_types=1);

namespace Tests\Unit\Privacy;

use PHPUnit\Framework\TestCase;
use PrestaShop\Module\SmailyForPrestaShop\Engine\Exception\EngineRequestException;
use PrestaShop\Module\SmailyForPrestaShop\Engine\Exception\EngineTransportException;
use PrestaShop\Module\SmailyForPrestaShop\Privacy\EngineFailure;
use PrestaShop\Module\SmailyForPrestaShop\Privacy\ExportRows;
use PrestaShop\Module\SmailyForPrestaShop\Privacy\GdprHooks;
use PrestaShop\Module\SmailyForPrestaShop\Privacy\PrivacyRequest;
use PrestaShop\Module\SmailyForPrestaShop\Queue\ContactEntity;

/**
 * The parts of a privacy request that need no shop: the rows that PrestaShop's GDPR module prints,
 * what its hooks read of their parameters, and how a failed engine call is stored.
 */
final class ExportRowsTest extends TestCase
{
    private const EXPORT = [
        'store' => [
            'queue' => [['type' => 'contact.sync', 'status' => 'sent', 'created_at' => '2026-11-20 10:00:00']],
            'carts' => [['cart' => 12, 'status' => 'mailed', 'last_activity_at' => '2026-11-19 09:00:00', 'reminded_at' => '2026-11-19 09:30:00']],
            'attribution' => [['order' => 7, 'recommendation' => 'rec-1', 'context' => 'welcome', 'visitor_token' => 'vt_1', 'created_at' => '2026-11-18 08:00:00']],
            'cart_attribution' => [['cart' => 13, 'recommendation' => 'rec-2', 'context' => 'storefront', 'visitor_token' => 'vt_1', 'updated_at' => '2026-11-20 09:00:00']],
            'codes' => [
                ['code' => 'AAAAAAAAAA', 'created_at' => '2026-11-17 08:00:00', 'expires_at' => '2026-11-24 23:59:59', 'used' => false],
                ['code' => 'BBBBBBBBBB', 'created_at' => '2026-11-16 08:00:00', 'expires_at' => '2026-11-23 23:59:59', 'used' => true],
            ],
            'preference' => ['opted_out' => true, 'changed_at' => '2026-11-15 08:00:00', 'source' => 'account'],
        ],
        'campaign_intelligence' => [
            ['connection' => 1, 'status' => 'ok', 'data' => ['customer' => ['email' => 'shopper1@example.test'], 'orders' => []]],
            ['connection' => 2, 'status' => 'not_available', 'data' => null],
            ['connection' => 3, 'status' => 'no_data', 'data' => null],
        ],
    ];

    public function testEveryRowIsFlatAndSaysWhatItIs(): void
    {
        $rows = ExportRows::of(self::EXPORT, static fn (string $text): string => $text);

        self::assertSame(
            ['Queued message', 'Abandoned cart', 'Order attribution', 'Cart attribution', 'Discount code', 'Discount code', 'Personalization preference', 'Campaign Intelligence', 'Campaign Intelligence', 'Campaign Intelligence'],
            array_column($rows, 'Data')
        );
        self::assertSame(['Data' => 'Queued message', 'Type' => 'contact.sync', 'Status' => 'sent', 'Date' => '2026-11-20 10:00:00'], $rows[0]);
        self::assertSame(['Data' => 'Cart attribution', 'Cart' => 13, 'Recommendation' => 'rec-2', 'Context' => 'storefront', 'Visitor token' => 'vt_1', 'Date' => '2026-11-20 09:00:00'], $rows[3]);
        self::assertSame(['Data' => 'Discount code', 'Code' => 'BBBBBBBBBB', 'Created' => '2026-11-16 08:00:00', 'Valid until' => '2026-11-23 23:59:59', 'Used' => 'Yes'], $rows[5]);
        self::assertSame(['Data' => 'Personalization preference', 'Personalized recommendations' => 'Opted out', 'Changed' => '2026-11-15 08:00:00', 'Source' => 'account'], $rows[6]);
        self::assertSame(['Data' => 'Campaign Intelligence', 'Connection' => 1, 'Content' => '{"customer":{"email":"shopper1@example.test"},"orders":[]}'], $rows[7]);
        self::assertSame(['Data' => 'Campaign Intelligence', 'Connection' => 2, 'Content' => 'Not available'], $rows[8], 'an engine that did not answer: one plain line');
        self::assertSame('No data', $rows[9]['Content']);
        foreach ($rows as $row) {
            foreach ($row as $value) {
                self::assertTrue(is_scalar($value));
            }
        }
    }

    public function testTheWordsAreTranslatedAndTheDataIsNot(): void
    {
        $rows = ExportRows::of(self::EXPORT, static fn (string $text): string => 'ET ' . $text);

        self::assertSame(['ET Data' => 'ET Queued message', 'ET Type' => 'contact.sync', 'ET Status' => 'sent', 'ET Date' => '2026-11-20 10:00:00'], $rows[0]);
        self::assertSame('ET Not available', $rows[8]['ET Content']);
    }

    public function testTheGdprHooksReadAnAddressAnAccountOrNobody(): void
    {
        $request = new class() extends PrivacyRequest {
            /** @var list<array<int, mixed>> */
            public array $calls = [];

            public function __construct()
            {
            }

            public function export(string $email, int $idCustomer = 0): array
            {
                $this->calls[] = ['export', $email, $idCustomer];

                return ['store' => ['queue' => [], 'carts' => [], 'attribution' => [], 'cart_attribution' => [], 'codes' => [], 'preference' => null], 'campaign_intelligence' => []];
            }

            public function erase(string $email, int $idCustomer, int $idShop): array
            {
                $this->calls[] = ['erase', $email, $idCustomer, $idShop];

                return [];
            }
        };
        $hooks = new GdprHooks($request, static fn (string $text): string => $text);

        self::assertSame('[]', $hooks->export(['id' => '12', 'email' => ' shopper1@example.test ', "\0*\0table" => 'customer']));
        self::assertSame('[]', $hooks->export(['email' => 'guest1@example.test']));
        self::assertSame('[]', $hooks->export(['phone' => '5550100']));
        self::assertSame('true', $hooks->erase(['id' => 12, 'email' => 'shopper1@example.test'], 3));
        self::assertSame('true', $hooks->erase(['phone' => '5550100'], 3));
        self::assertSame('true', $hooks->erase(['email' => ['not', 'a', 'string']], 3));

        self::assertSame([
            ['export', 'shopper1@example.test', 12],
            ['export', 'guest1@example.test', 0],
            ['erase', 'shopper1@example.test', 12, 3],
        ], $request->calls, 'a request without an address reaches nothing');
    }

    public function testAFailedEngineCallIsStoredByItsStatusAndCode(): void
    {
        $refused = EngineFailure::of(new EngineRequestException('tenant_inactive', 403));
        self::assertSame([true, 'permanent_http_403: Campaign Intelligence answered HTTP 403 (tenant_inactive).'], [$refused->isPermanent(), $refused->reason()]);

        // A 3xx parks the row too, unlike a Smaily answer's (Queue\Failure::http()).
        $moved = EngineFailure::of(new EngineRequestException('http_302', 302));
        self::assertSame([true, 'permanent_http_302: Campaign Intelligence answered HTTP 302 (http_302).'], [$moved->isPermanent(), $moved->reason()]);

        $unreadable = EngineFailure::of(new EngineRequestException('key_unreadable'));
        self::assertSame([true, 'The Campaign Intelligence request failed (key_unreadable).'], [$unreadable->isPermanent(), $unreadable->reason()]);

        $down = EngineFailure::of(new EngineTransportException('http_503', 503));
        self::assertSame([false, 'Campaign Intelligence answered HTTP 503 (http_503).'], [$down->isPermanent(), $down->reason()]);

        $silent = EngineFailure::of(new EngineTransportException('network_error'));
        self::assertSame([false, 'The Campaign Intelligence request failed (network_error).'], [$silent->isPermanent(), $silent->reason()]);
    }

    public function testTheKeyedHashOfAnAddressIsTheEntityOfALongOne(): void
    {
        defined('_NEW_COOKIE_KEY_') || define('_NEW_COOKIE_KEY_', 'not-a-real-key');
        $long = 'shopper.having.a.very.long.invented.name@a-long-subdomain.example.test';

        self::assertSame(ContactEntity::of($long), ContactEntity::key($long));
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', ContactEntity::key('shopper1@example.test'), 'a short address has a key too');
        self::assertSame(ContactEntity::key('shopper1@example.test'), ContactEntity::key(' Shopper1@Example.TEST '));
        self::assertNotSame(ContactEntity::key('shopper1@example.test'), ContactEntity::key('shopper2@example.test'));
    }
}
