<?php
declare(strict_types=1);

namespace Tests\Integration\Storefront;

use PrestaShop\Module\SmailyForPrestaShop\Scheduler\Deadline;
use PrestaShop\Module\SmailyForPrestaShop\Scheduler\QueueJanitor;
use PrestaShop\Module\SmailyForPrestaShop\Storefront\ActiveConnection;
use PrestaShop\Module\SmailyForPrestaShop\Storefront\Attribution;
use PrestaShop\Module\SmailyForPrestaShop\Storefront\CartAttribution;
use PrestaShop\Module\SmailyForPrestaShop\Storefront\Cookies;

require_once __DIR__ . '/StorefrontTestCase.php';

/**
 * A shopper's own storefront pages keep the click attribution and the visitor token with the
 * cart, and an order that a request without the shopper's cookies creates (a payment provider's
 * server call) takes them from there.
 */
final class CartAttributionTest extends StorefrontTestCase
{
    private const START = 1893456000; // 2030-01-01 00:00:00 UTC

    private const LANDING = ['smaily_rec_id' => self::REC_ID, 'smaily_rec_ctx' => 'storefront', 'smaily_rec_uid' => 'vt_8f3k2a9xq4'];

    private int $now = self::START;

    public function testAPageOfAVisitorWithACartKeepsTheThreeValuesWithTheCart(): void
    {
        $this->connect();
        $idCart = $this->page(self::LANDING, 'cart-click@example.test');

        self::assertSame([[
            'id_cart' => $idCart,
            'rec_id' => self::REC_ID,
            'context' => 'storefront',
            'visitor_token' => 'vt_8f3k2a9xq4',
            'updated_at' => gmdate('Y-m-d H:i:s', self::START),
        ]], self::cartRows());
    }

    public function testTheHookOfEveryStorefrontPageIsWired(): void
    {
        $this->connect();
        $idCart = $this->visitor(self::LANDING, 'cart-hook@example.test');

        $this->hook('actionFrontControllerSetMedia');

        self::assertSame([$idCart], array_column(self::cartRows(), 'id_cart'));
    }

    /**
     * @dataProvider nothingToKeep
     *
     * @param array<string, string> $cookies
     */
    public function testWithoutAClickAndWithoutTrackingConsentNothingIsKeptWithTheCart(array $cookies, bool $setting, bool $guest, bool $newsletter): void
    {
        $this->connect();
        $this->confirmConsent($setting);

        $this->page($cookies, 'cart-nothing@example.test', $guest, $newsletter);

        self::assertSame([], self::cartRows());
    }

    /**
     * @return array<string, array{array<string, string>, bool, bool, bool}>
     */
    public function nothingToKeep(): array
    {
        return [
            'no cookie at all' => [[], true, false, true],
            'the browse session alone' => [['smaily_anon_sid' => '0f8fad5b-d9cb-469f-a165-70867728950e'], true, false, true],
            'a store-created token, the merchant has not confirmed' => [['smaily_rec_uid' => self::STORE_TOKEN], false, false, true],
            'a store-created token of a guest who left the newsletter box empty' => [['smaily_rec_uid' => self::STORE_TOKEN], true, true, false],
            'a context without a recommendation id' => [['smaily_rec_ctx' => 'storefront'], true, false, true],
        ];
    }

    public function testAStoreCreatedTokenIsKeptOnceTheMerchantConfirmed(): void
    {
        $this->connect();
        $this->confirmConsent();

        $idCart = $this->page(['smaily_rec_uid' => self::STORE_TOKEN], 'cart-store-token@example.test');

        self::assertSame([[$idCart, null, null, self::STORE_TOKEN]], array_map(static fn (array $row): array => [$row['id_cart'], $row['rec_id'], $row['context'], $row['visitor_token']], self::cartRows()));
    }

    public function testNothingIsKeptWithoutACartAConnectionOrAStorefrontRequest(): void
    {
        // No connection.
        $this->page(self::LANDING, 'cart-unconnected@example.test');
        self::assertSame([], self::cartRows());

        $this->connect();
        // A visitor who has put nothing into a cart: PrestaShop has saved none.
        $this->storefrontRequest(self::LANDING);
        \Context::getContext()->cart = new \Cart();
        $this->carts()->capture(\Context::getContext());
        self::assertSame([], self::cartRows());

        // The back office, a command and a cron URL's tick have no shopper's cookies.
        $this->visitor(self::LANDING, 'cart-backoffice@example.test');
        \Context::getContext()->controller = null;
        $this->carts()->capture(\Context::getContext());
        self::assertSame([], self::cartRows());
    }

    public function testARowIsWrittenAgainWhenItsValuesChangeOrOnceAnHour(): void
    {
        $this->connect();
        $idCart = $this->page(self::LANDING, 'cart-again@example.test');

        $this->now += CartAttribution::REFRESH_SECONDS - 60;
        $this->carts()->capture(\Context::getContext());
        self::assertSame(gmdate('Y-m-d H:i:s', self::START), self::cartRows()[0]['updated_at'], 'the same values within the hour: no write');

        $this->now += 120;
        $this->carts()->capture(\Context::getContext());
        self::assertSame(gmdate('Y-m-d H:i:s', $this->now), self::cartRows()[0]['updated_at'], 'the row\'s time follows the shopper\'s visits');

        $_COOKIE = ['smaily_rec_id' => '0f8fad5b-d9cb-469f-a165-70867728950e'];
        $this->now += 60;
        $this->carts()->capture(\Context::getContext());
        self::assertSame([[
            'id_cart' => $idCart,
            'rec_id' => '0f8fad5b-d9cb-469f-a165-70867728950e',
            'context' => null,
            'visitor_token' => null,
            'updated_at' => gmdate('Y-m-d H:i:s', $this->now),
        ]], self::cartRows(), 'a later click replaces the row at once');
    }

    /**
     * @dataProvider requestsWithoutTheShoppersCookies
     */
    public function testAnOrderOfARequestWithoutTheShoppersCookiesTakesWhatTheCartKept(bool $frontController): void
    {
        $this->connect();
        $idCart = $this->page(self::LANDING, 'cart-provider@example.test');

        // The payment provider's server confirms the payment: a request of its own, without cookies.
        $this->storefrontRequest([]);
        if (!$frontController) {
            \Context::getContext()->controller = null;
        }
        $idOrder = $this->fixture->order($idCart);

        self::assertSame([['id_order' => $idOrder, 'rec_id' => self::REC_ID, 'context' => 'storefront', 'visitor_token' => 'vt_8f3k2a9xq4']], self::attributionRows());
        self::assertSame([], self::cartRows(), 'the order holds the values from here on');
    }

    /**
     * @return array<string, array{bool}>
     */
    public function requestsWithoutTheShoppersCookies(): array
    {
        return [
            'a payment module\'s callback, a storefront controller' => [true],
            'the back office or a command' => [false],
        ];
    }

    public function testTheCookiesOfTheOrdersOwnRequestWinAndTheCartsRowGoes(): void
    {
        $this->connect();
        $idCart = $this->page(self::LANDING, 'cart-own-request@example.test');

        $this->storefrontRequest(['smaily_rec_id' => '0f8fad5b-d9cb-469f-a165-70867728950e']);
        $idOrder = $this->fixture->order($idCart);

        self::assertSame([['id_order' => $idOrder, 'rec_id' => '0f8fad5b-d9cb-469f-a165-70867728950e', 'context' => null, 'visitor_token' => null]], self::attributionRows());
        self::assertSame([], self::cartRows());
    }

    public function testAStoreCreatedTokenOfTheCartCountsOnlyWhileTheMerchantsConfirmationHolds(): void
    {
        $this->connect();
        $this->confirmConsent();
        $kept = $this->page(['smaily_rec_uid' => self::STORE_TOKEN], 'cart-token-kept@example.test');
        $dropped = $this->page(['smaily_rec_uid' => self::STORE_TOKEN, 'smaily_rec_id' => self::REC_ID], 'cart-token-dropped@example.test');

        $this->storefrontRequest([]);
        $first = $this->fixture->order($kept);
        $this->confirmConsent(false);
        $second = $this->fixture->order($dropped);

        self::assertSame([
            ['id_order' => $first, 'rec_id' => null, 'context' => null, 'visitor_token' => self::STORE_TOKEN],
            ['id_order' => $second, 'rec_id' => self::REC_ID, 'context' => null, 'visitor_token' => null],
        ], self::attributionRows());
    }

    public function testAnOrderOfACartThatKeptNothingHasNoRow(): void
    {
        $this->connect();
        $idCart = $this->page([], 'cart-kept-nothing@example.test');

        $this->storefrontRequest([]);
        $this->fixture->order($idCart);

        self::assertSame([], self::attributionRows());
    }

    public function testTheJanitorDeletesTheRowsOfCartsThatGotNoOrderAfterADay(): void
    {
        $this->connect();
        // The tick's own janitor, on the shop's clock.
        $this->now = time() - CartAttribution::KEEP_SECONDS - 60;
        $stale = $this->page(self::LANDING, 'cart-stale@example.test');
        $this->now = time() - CartAttribution::KEEP_SECONDS + 3600;
        $fresh = $this->page(self::LANDING, 'cart-fresh@example.test');

        $deleted = $this->services->get(QueueJanitor::class)->run(new Deadline(microtime(true) + 10, static fn (): float => microtime(true)));

        self::assertSame([$fresh], array_column(self::cartRows(), 'id_cart'), 'cart ' . $stale . ' lost its row');
        self::assertGreaterThanOrEqual(1, $deleted);
    }

    /**
     * A storefront page of a shopper with these cookies and a cart: what the page's hook does.
     *
     * @param array<string, string> $cookies
     *
     * @return int the cart
     */
    private function page(array $cookies, string $email, bool $guest = false, bool $newsletter = false): int
    {
        $idCart = $this->visitor($cookies, $email, $guest, $newsletter);
        $this->carts()->capture(\Context::getContext());

        return $idCart;
    }

    /**
     * Puts a shopper with these cookies and a cart into the request.
     *
     * @param array<string, string> $cookies
     */
    private function visitor(array $cookies, string $email, bool $guest = false, bool $newsletter = false): int
    {
        $idCustomer = $this->fixture->customer($email, $guest, $newsletter);
        $idCart = $this->fixture->cart($idCustomer, 0);
        $this->storefrontRequest($cookies);
        \Context::getContext()->cart = new \Cart($idCart);
        \Context::getContext()->customer = new \Customer($idCustomer);

        return $idCart;
    }

    /**
     * The carts' attribution of this request, with the test's clock.
     */
    private function carts(): CartAttribution
    {
        return new CartAttribution(
            $this->services->get(ActiveConnection::class),
            $this->services->get(Attribution::class),
            static fn (): ?Cookies => \Context::getContext()->controller instanceof \FrontController ? Cookies::ofRequest(\Context::getContext()) : null,
            fn (): int => $this->now
        );
    }

    /**
     * @return list<array{id_cart: int, rec_id: ?string, context: ?string, visitor_token: ?string, updated_at: string}>
     */
    private static function cartRows(): array
    {
        $rows = self::db()->executeS('SELECT `id_cart`, `rec_id`, `context`, `visitor_token`, `updated_at` FROM `' . _DB_PREFIX_ . CartAttribution::TABLE . '` ORDER BY `id_cart`', true, false) ?: [];

        return array_map(static fn (array $row): array => ['id_cart' => (int) $row['id_cart']] + $row, $rows);
    }
}
