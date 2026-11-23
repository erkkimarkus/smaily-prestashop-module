<?php
declare(strict_types=1);

namespace Tests\Integration\Storefront;

use PrestaShop\Module\SmailyForPrestaShop\Runtime\Provider\StorefrontProvider;
use PrestaShop\Module\SmailyForPrestaShop\Scheduler\Job;
use PrestaShop\Module\SmailyForPrestaShop\Engine\Capture\VisitorToken as VisitorTokenSource;
use PrestaShop\Module\SmailyForPrestaShop\Runtime\Provider\SchedulerProvider;
use PrestaShop\Module\SmailyForPrestaShop\Storefront\ActiveConnection;
use PrestaShop\Module\SmailyForPrestaShop\Storefront\Attribution;
use PrestaShop\Module\SmailyForPrestaShop\Storefront\Cookies;
use PrestaShop\Module\SmailyForPrestaShop\Storefront\Shape;
use PrestaShop\Module\SmailyForPrestaShop\Storefront\VisitorToken;

require_once __DIR__ . '/StorefrontTestCase.php';

/**
 * An order keeps the attribution cookies of the request that placed it, and a shopper whom the
 * store comes to know gets a visitor token only where the merchant confirmed the cookie banner.
 */
final class OrderAttributionTest extends StorefrontTestCase
{
    /** @var list<array{string, string, array<string, mixed>}> the cookies the server set */
    private array $sent = [];

    public function testAnOrderAfterALandingStoresTheThreeValues(): void
    {
        $this->connect();
        $this->storefrontRequest(['smaily_rec_id' => self::REC_ID, 'smaily_rec_ctx' => 'cart_abandoned', 'smaily_rec_uid' => 'vt_8f3k2a9xq4']);

        $idOrder = $this->order('attribution-landing@example.test');

        self::assertSame([[
            'id_order' => $idOrder,
            'rec_id' => self::REC_ID,
            'context' => 'cart_abandoned',
            'visitor_token' => 'vt_8f3k2a9xq4',
        ]], self::attributionRows());
        self::assertMatchesRegularExpression(
            '/^\d{4}-\d\d-\d\d \d\d:\d\d:\d\d$/',
            (string) self::db()->getValue('SELECT `created_at` FROM `' . _DB_PREFIX_ . 'smaily_connect_order_attribution` WHERE `id_order` = ' . $idOrder, false)
        );
    }

    public function testAnOrderWithoutALandingHasNoRow(): void
    {
        $this->connect();
        $this->storefrontRequest([]);

        $this->order('attribution-none@example.test');

        self::assertSame([], self::attributionRows());
    }

    public function testTheBrowseSessionAloneGivesNoRow(): void
    {
        $this->connect();
        $this->storefrontRequest(['smaily_anon_sid' => '0f8fad5b-d9cb-469f-a165-70867728950e']);

        $this->order('attribution-session@example.test');

        self::assertSame([], self::attributionRows());
    }

    public function testAnOrderThatNoStorefrontRequestPlacesHasNoRow(): void
    {
        $this->connect();
        $this->storefrontRequest(['smaily_rec_id' => self::REC_ID, 'smaily_rec_uid' => 'vt_8f3k2a9xq4']);
        // The back office, a command and a payment provider's server call have no front controller.
        \Context::getContext()->controller = null;

        $this->order('attribution-backoffice@example.test');

        self::assertSame([], self::attributionRows());
    }

    public function testAShopWithoutAConnectionStoresNothing(): void
    {
        $this->storefrontRequest(['smaily_rec_id' => self::REC_ID, 'smaily_rec_uid' => 'vt_8f3k2a9xq4']);

        $this->order('attribution-unconnected@example.test');

        self::assertSame([], self::attributionRows());
    }

    public function testAMalformedIdIsDroppedWithItsContextAndTheTokenStays(): void
    {
        $this->connect();
        $this->storefrontRequest(['smaily_rec_id' => 'rec_abc123', 'smaily_rec_ctx' => 'welcome', 'smaily_rec_uid' => 'vt_8f3k2a9xq4']);

        $idOrder = $this->order('attribution-malformed@example.test');

        self::assertSame([['id_order' => $idOrder, 'rec_id' => null, 'context' => null, 'visitor_token' => 'vt_8f3k2a9xq4']], self::attributionRows());
    }

    public function testARecommendationIdWithoutAContextOrATokenIsStoredAlone(): void
    {
        $this->connect();
        $this->storefrontRequest(['smaily_rec_id' => self::REC_ID]);

        $idOrder = $this->order('attribution-rec@example.test');

        self::assertSame([['id_order' => $idOrder, 'rec_id' => self::REC_ID, 'context' => null, 'visitor_token' => null]], self::attributionRows());
    }

    /**
     * @dataProvider storeTokenCases
     */
    public function testAStoreCreatedTokenIsStoredByTheMerchantsConfirmationAndAGuestsOwnAnswer(bool $setting, bool $guest, bool $newsletter, bool $stored): void
    {
        $this->connect();
        $this->confirmConsent($setting);
        $this->storefrontRequest(['smaily_rec_uid' => self::STORE_TOKEN]);

        $idOrder = $this->fixture->order($this->fixture->cart($this->fixture->customer('attribution-store-token@example.test', $guest, $newsletter), 0));

        self::assertSame(
            $stored ? [['id_order' => $idOrder, 'rec_id' => null, 'context' => null, 'visitor_token' => self::STORE_TOKEN]] : [],
            self::attributionRows()
        );
    }

    /**
     * @return array<string, array{bool, bool, bool, bool}>
     */
    public function storeTokenCases(): array
    {
        return [
            'the setting and a subscribed customer' => [true, false, true, true],
            'the setting, a customer who is not subscribed' => [true, false, false, true],
            'no setting, a subscribed customer' => [false, false, true, false],
            'the setting and a guest who ticked the newsletter box' => [true, true, true, true],
            'the setting, a guest who left the box empty' => [true, true, false, false],
            'no setting, a guest who ticked the box' => [false, true, true, false],
        ];
    }

    public function testAConsentingShopperWithoutATokenGetsOneFromTheServer(): void
    {
        $this->connect();
        $this->confirmConsent();
        $tokens = $this->visitorToken([]);

        $token = $tokens->token(1, true);

        self::assertTrue(Shape::isStoreVisitorToken((string) $token));
        self::assertCount(1, $this->sent);
        [$name, $value, $options] = $this->sent[0];
        self::assertSame(['smaily_rec_uid', $token], [$name, $value]);
        self::assertEqualsWithDelta(time() + 365 * 86400, $options['expires'], 5);
        self::assertSame(['path' => '/', 'secure' => false, 'httponly' => false, 'samesite' => 'Lax'], array_diff_key($options, ['expires' => 0]));

        self::assertSame($token, $tokens->token(1, true), 'the same answer through the request');
        self::assertCount(1, $this->sent, 'and one cookie');
        self::assertNull($tokens->token(1, false), 'and none for a shopper without consent in the same request');
    }

    public function testTheStorefrontAnswersTheIdentityMergesSeam(): void
    {
        self::assertInstanceOf(VisitorTokenSource::class, $this->services->get(VisitorToken::class));
        self::assertSame($this->services->get(VisitorToken::class), $this->services->get(VisitorTokenSource::class));
    }

    /**
     * @dataProvider withoutToken
     */
    public function testWithoutMarketingConsentNoTokenIsCreated(string $case): void
    {
        if ($case !== 'no connection') {
            $this->connect();
        }
        $this->confirmConsent($case !== 'the merchant has not confirmed the banner');
        $tokens = $case === 'no storefront request'
            ? new VisitorToken($this->services->get(ActiveConnection::class), $this->services->get(Attribution::class), static fn (): ?Cookies => null)
            : $this->visitorToken([], $case !== 'the cookie cannot be set');

        self::assertNull($tokens->token(1, $case !== 'the caller answers no'));
        self::assertSame([], $this->sent);
    }

    /**
     * @return array<string, array{string}>
     */
    public function withoutToken(): array
    {
        return [
            'the merchant has not confirmed the banner' => ['the merchant has not confirmed the banner'],
            'the caller answers no' => ['the caller answers no'],
            'no connection' => ['no connection'],
            'no storefront request' => ['no storefront request'],
            'the cookie cannot be set' => ['the cookie cannot be set'],
        ];
    }

    public function testATokenThatTheBrowserHoldsIsKeptAndNeverReplaced(): void
    {
        $this->connect();
        $this->confirmConsent();

        self::assertSame('vt_8f3k2a9xq4', $this->visitorToken(['smaily_rec_uid' => 'vt_8f3k2a9xq4'])->token(1, true));
        self::assertSame('vt_8f3k2a9xq4', $this->visitorToken(['smaily_rec_uid' => 'vt_8f3k2a9xq4'])->token(1, false), 'an email link\'s token is attribution');
        self::assertSame(self::STORE_TOKEN, $this->visitorToken(['smaily_rec_uid' => self::STORE_TOKEN])->token(1, true));
        self::assertNull($this->visitorToken(['smaily_rec_uid' => self::STORE_TOKEN])->token(1, false), 'consent that is withdrawn sends no store-created token');
        self::assertSame([], $this->sent);
    }

    public function testTheLoginTheRegistrationAndTheOrderAreWiredAndRunOutsideAStorefrontRequestWithoutEffect(): void
    {
        $this->connect();
        $this->confirmConsent();
        $customer = new \Customer($this->fixture->customer('token-hooks@example.test', false, true));
        \Context::getContext()->controller = null;

        foreach (['actionAuthentication' => ['customer' => $customer], 'actionCustomerAccountAdd' => ['newCustomer' => $customer]] as $hook => $params) {
            self::assertNotSame([], $this->services->listeners($hook));
            $this->hook($hook, $params);
        }

        $jobs = array_map(static fn (callable $job): Job => $job([]), $this->services->listeners(SchedulerProvider::JOBS));
        self::assertContains(StorefrontProvider::CACHE_JOB, array_map(static fn (Job $job): string => $job->name(), $jobs));
        self::assertSame([], self::attributionRows());
    }

    /**
     * Places an order of an invented shopper through PrestaShop's own order validation, in the
     * request the test prepared.
     */
    private function order(string $email, bool $newsletter = false): int
    {
        $idCustomer = $this->fixture->customer($email, false, $newsletter);

        return $this->fixture->order($this->fixture->cart($idCustomer, 0));
    }

    /**
     * The visitor tokens of a storefront request with these cookies, which records what it sets.
     *
     * @param array<string, string> $cookies
     */
    private function visitorToken(array $cookies, bool $sendable = true): VisitorToken
    {
        return new VisitorToken(
            $this->services->get(ActiveConnection::class),
            $this->services->get(Attribution::class),
            fn (): Cookies => new Cookies($cookies, '/', false, function (string $name, string $value, array $options) use ($sendable): bool {
                if ($sendable) {
                    $this->sent[] = [$name, $value, $options];
                }

                return $sendable;
            })
        );
    }
}
