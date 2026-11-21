<?php
declare(strict_types=1);

namespace Tests\Integration\Storefront;

use PrestaShop\Module\SmailyForPrestaShop\Privacy\ProfilingRecord;
use PrestaShop\Module\SmailyForPrestaShop\Storefront\Cookies;
use PrestaShop\Module\SmailyForPrestaShop\Storefront\PageScripts;
use PrestaShop\Module\SmailyForPrestaShop\Storefront\RecommendationsBlock;
use PrestaShop\PrestaShop\Core\Module\WidgetInterface;

require_once __DIR__ . '/StorefrontTestCase.php';

/**
 * The recommendations block: the widget's container is the same for every visitor, and the
 * products that fill it are the shopper's own, drawn by the theme, or nothing at all. And what
 * every storefront page of a connected shop gets for the scripts.
 */
final class RecommendationsBlockTest extends StorefrontTestCase
{
    private const RECOMMENDATIONS = '/api/v1/recommendations/customer';

    /** @var list<string|null> */
    private array $seen = [];

    private const CONTAINER = '<div class="smaily-connect-recommendations" data-smaily-connect-recs></div>';

    private const CONTAINED = '<div class="smaily-connect-recommendations container" data-smaily-connect-recs></div>';

    public function testTheMainClassIsAWidgetThatRendersNothingWithoutAConnection(): void
    {
        self::assertInstanceOf(WidgetInterface::class, self::module());
        self::assertSame('', self::module()->renderWidget('displayHome', []));
        self::assertSame([], self::module()->getWidgetVariables('displayHome', []));
    }

    public function testTheWidgetRendersTheSameEmptyContainerForEveryVisitor(): void
    {
        $this->connect();
        $this->storefrontRequest();

        foreach (['displayHome', 'displayFooterProduct', null] as $hook) {
            \Context::getContext()->customer = new \Customer();
            $anonymous = self::module()->renderWidget($hook, []);
            \Context::getContext()->customer = new \Customer($this->fixture->customer('widget-shopper-' . count($this->seen) . '@example.test', false, true));
            $this->seen[] = $hook;

            self::assertSame($anonymous, self::module()->renderWidget($hook, []), 'the same for a customer as for a visitor');
            self::assertStringContainsString(' data-smaily-connect-recs></div>', $anonymous);
        }
    }

    public function testOnlyTheHomePageOfAThemeBuiltFromComponentsGetsTheThemesContainer(): void
    {
        $this->connect();
        $this->storefrontRequest();
        $components = \Context::getContext()->smarty->templateExists('components/section-title.tpl');

        self::assertSame($components ? self::CONTAINED : self::CONTAINER, self::withoutTemplateComments(self::module()->renderWidget('displayHome', [])));
        self::assertSame(self::CONTAINER, self::withoutTemplateComments(self::module()->renderWidget('displayFooterProduct', ['product' => 1])));
        self::assertSame(self::CONTAINER, self::withoutTemplateComments(self::module()->renderWidget(null, [])));
    }

    public function testTheWidgetRendersNothingWhileTheEngineRefusesTheConnection(): void
    {
        $connection = $this->connect();
        $this->connections()->saveSettings($connection, ['refused_at' => '2026-11-21T09:00:00Z'] + $connection->settings());

        self::assertSame('', self::module()->renderWidget('displayHome', []));
    }

    public function testNoDisplayHookIsRegisteredForTheBlock(): void
    {
        $hooks = array_column(self::db()->executeS(
            'SELECT DISTINCT h.`name` FROM `' . _DB_PREFIX_ . 'hook_module` hm JOIN `' . _DB_PREFIX_ . 'hook` h ON h.`id_hook` = hm.`id_hook`'
            . ' WHERE hm.`id_module` = ' . (int) self::module()->id
        ), 'name');

        $display = array_values(array_filter($hooks, static fn (string $hook): bool => stripos($hook, 'display') === 0));
        sort($display);

        self::assertSame(['displayBackOfficeHeader', 'displayCustomerAccount'], $display);
    }

    public function testAVisitorWithATokenGetsTheirProductsInTheThemesOwnMiniatures(): void
    {
        $this->connect();
        $this->engine->set('recommendations', ['token:vt_8f3k2a9xq4' => [
            ['external_id' => '1'],
            ['external_id' => '2-9'],
            ['external_id' => '999999'],
            ['external_id' => '6'],
        ]]);

        [$status, $html] = $this->answer(['smaily_rec_uid' => 'vt_8f3k2a9xq4']);

        self::assertSame(200, $status);
        self::assertSame(3, substr_count($html, '<article'), 'a product that the shop does not have is left out, and nothing takes its place');
        self::assertStringContainsString('Recommended for you', $html);
        self::assertStringContainsString('product-miniature', $html);
        self::assertStringContainsString(htmlspecialchars((new \Product(1, false, 1))->name), $html);
        self::assertStringContainsString('smaily_rec=3fa85f64-5717-4562-b3fc-000000000001&amp;smaily_ctx=storefront', $html);
        self::assertStringContainsString('smaily_rec=3fa85f64-5717-4562-b3fc-000000000002&amp;smaily_ctx=storefront#', $html, 'a combination keeps its fragment after the parameters');
        self::assertStringNotContainsString('smaily_vt', $html);
        self::assertSame([['smaily_visitor_token' => 'vt_8f3k2a9xq4', 'limit' => 4]], array_column($this->engine->requestsTo(self::RECOMMENDATIONS), 'body'));
    }

    public function testALoggedInCustomerIsNamedByTheCustomerIdAndNeverByTheCookie(): void
    {
        $this->connect();
        $idCustomer = $this->fixture->customer('recs-customer@example.test');
        $this->engine->set('recommendations', ['customer:' . $idCustomer => [['external_id' => '6']], 'token:vt_8f3k2a9xq4' => [['external_id' => '1']]]);

        [, $html] = $this->answer(['smaily_rec_uid' => 'vt_8f3k2a9xq4'], $this->loggedIn($idCustomer));

        self::assertSame(1, substr_count($html, '<article'));
        $bodies = array_column($this->engine->requestsTo(self::RECOMMENDATIONS), 'body');
        self::assertSame([['customer_external_id' => (string) $idCustomer, 'limit' => 4]], $bodies);
        self::assertStringNotContainsString('@', (string) json_encode($bodies));
    }

    public function testACustomerWhoObjectedToPersonalizationIsNeverAskedAbout(): void
    {
        $this->connect();
        $idCustomer = $this->fixture->customer('recs-objected@example.test');
        $this->engine->set('recommendations', ['customer:' . $idCustomer => [['external_id' => '6']], 'token:vt_8f3k2a9xq4' => [['external_id' => '1']]]);
        $record = $this->services->get(ProfilingRecord::class);
        $record->write('recs-objected@example.test', true, ProfilingRecord::SOURCE_ACCOUNT);
        try {
            self::assertSame([200, ''], $this->answer(['smaily_rec_uid' => 'vt_8f3k2a9xq4'], $this->loggedIn($idCustomer)));
            self::assertSame([], $this->engine->requestsTo(self::RECOMMENDATIONS), 'not by the visitor token either');

            $record->write('recs-objected@example.test', false, ProfilingRecord::SOURCE_ACCOUNT);
            self::assertSame(1, substr_count($this->answer([], $this->loggedIn($idCustomer))[1], '<article'), 'a choice that is taken back shows the block again');
        } finally {
            self::db()->delete(ProfilingRecord::TABLE);
        }
    }

    public function testNothingIsShownAndNoEngineIsAskedWithoutAShopper(): void
    {
        $this->connect();

        self::assertSame([200, ''], $this->answer([]));
        self::assertSame([200, ''], $this->answer(['smaily_rec_uid' => self::STORE_TOKEN]), 'a store-created token without the consent setting');
        self::assertSame([200, ''], $this->answer(['smaily_rec_uid' => 'someone@example.test']));
        self::assertSame([], $this->engine->requestsTo(self::RECOMMENDATIONS));
    }

    public function testAStoreCreatedTokenAsksOnceTheMerchantConfirmedTheBanner(): void
    {
        $this->connect();
        $this->confirmConsent();
        $this->engine->set('recommendations', ['token:' . self::STORE_TOKEN => [['external_id' => '6']]]);

        [, $html] = $this->answer(['smaily_rec_uid' => self::STORE_TOKEN]);

        self::assertSame(1, substr_count($html, '<article'));
    }

    /**
     * @dataProvider emptyOutcomes
     */
    public function testNothingIsShownWhenTheEngineAnswersNothingOrFails(string $case): void
    {
        $this->connect();
        if ($case === 'the engine fails') {
            $this->engine->fail('recommendations', 500);
        }
        if ($case === 'only products that the shop cannot show') {
            $this->engine->set('recommendations', ['token:vt_8f3k2a9xq4' => [['external_id' => '999999'], ['external_id' => 'not-an-id'], ['external_id' => '1-999999']]]);
        }

        self::assertSame([200, ''], $this->answer(['smaily_rec_uid' => 'vt_8f3k2a9xq4']));
        self::assertCount(1, $this->engine->requestsTo(self::RECOMMENDATIONS));
    }

    /**
     * @return array<string, array{string}>
     */
    public function emptyOutcomes(): array
    {
        return [
            'no recommendations' => ['no recommendations'],
            'the engine fails' => ['the engine fails'],
            'only products that the shop cannot show' => ['only products that the shop cannot show'],
        ];
    }

    public function testAProductThatIsSwitchedOffIsLeftOut(): void
    {
        $this->connect();
        $this->engine->set('recommendations', ['token:vt_8f3k2a9xq4' => [['external_id' => '6'], ['external_id' => '7']]]);
        self::db()->update('product_shop', ['active' => 0], '`id_product` = 7');
        try {
            [, $html] = $this->answer(['smaily_rec_uid' => 'vt_8f3k2a9xq4']);
        } finally {
            self::db()->update('product_shop', ['active' => 1], '`id_product` = 7');
        }

        self::assertSame(1, substr_count($html, '<article'));
    }

    public function testTheAnswerIsCachedPerShopperAndARequestOfAnotherSiteGetsNothing(): void
    {
        $this->connect();
        $this->engine->set('recommendations', ['token:vt_8f3k2a9xq4' => [['external_id' => '6']]]);
        $cookies = ['smaily_rec_uid' => 'vt_8f3k2a9xq4'];

        [, $first] = $this->answer($cookies);
        [, $second] = $this->answer($cookies);
        self::assertSame($first, $second);
        self::assertCount(1, $this->engine->requestsTo(self::RECOMMENDATIONS), 'the second answer came from the cache');

        self::assertSame([200, ''], $this->answer($cookies, null, 'cross-site'));
        self::assertSame([200, ''], $this->answer($cookies, null, 'same-site'));
        self::assertNotSame('', $this->answer($cookies, null, 'same-origin')[1]);
    }

    public function testAShopWithoutAConnectionAnswers404AndAFloodIsRefused(): void
    {
        self::assertSame([404, ''], $this->answer(['smaily_rec_uid' => 'vt_8f3k2a9xq4']));

        $this->connect();
        for ($i = 0; $i < RecommendationsBlock::LIMIT_PER_MINUTE; ++$i) {
            self::assertSame(200, $this->answer([])[0]);
        }
        self::assertSame([429, ''], $this->answer([]));
    }

    public function testEveryPageOfAConnectedShopGetsTheScriptsAndADefinitionThatNamesNoVisitor(): void
    {
        $this->connect();
        $key = (string) array_key_first($this->engine->connections());
        \Context::getContext()->customer = new \Customer($this->fixture->customer('scripts-shopper@example.test', false, true));
        $controller = $this->storefrontRequest(['smaily_rec_uid' => 'vt_8f3k2a9xq4'], 'search');

        $this->hook('actionFrontControllerSetMedia');

        $base = 'modules/smailyforprestashop/views/js/front/';
        self::assertSame([$base . 'consent.js', $base . 'attribution.js', $base . 'tracker.js', $base . 'recommendations.js'], $controller->scripts);
        $definition = \Media::getJsDef()[PageScripts::JS_DEF];
        self::assertSame(['consentConfirmed', 'landUrl', 'relayUrl', 'recommendationsUrl', 'cookiePath', 'cookies', 'days', 'parameters', 'page'], array_keys($definition));
        self::assertFalse($definition['consentConfirmed']);
        self::assertSame(['visitor' => 'smaily_rec_uid', 'session' => 'smaily_anon_sid', 'recId' => 'smaily_rec_id', 'context' => 'smaily_rec_ctx'], $definition['cookies']);
        self::assertSame(['visitor' => 365, 'session' => 30, 'recId' => 30, 'context' => 30], $definition['days']);
        self::assertSame(['visitor' => 'smaily_vt', 'recId' => 'smaily_rec', 'context' => 'smaily_ctx'], $definition['parameters']);
        self::assertSame('search', $definition['page']['type']);
        foreach (['land', 'relay', 'recommendations'] as $endpoint) {
            self::assertSame(\Context::getContext()->link->getModuleLink('smailyforprestashop', $endpoint), $definition[$endpoint . 'Url']);
        }
        $printed = (string) json_encode($definition);
        self::assertStringNotContainsString($key, $printed, 'the key stays on the server');
        self::assertStringNotContainsString('vt_8f3k2a9xq4', $printed);
        self::assertStringNotContainsString('scripts-shopper', $printed);
    }

    public function testAShopWithoutAConnectionGetsNoScriptAndARefusedOneOnlyTheLandingCapture(): void
    {
        $controller = $this->storefrontRequest();
        $this->hook('actionFrontControllerSetMedia');
        self::assertSame([], $controller->scripts);
        self::assertArrayNotHasKey(PageScripts::JS_DEF, \Media::getJsDef());

        $connection = $this->connect();
        $this->connections()->saveSettings($connection, ['refused_at' => '2026-11-21T09:00:00Z'] + $connection->settings());
        $controller = $this->storefrontRequest();
        $this->hook('actionFrontControllerSetMedia');

        $base = 'modules/smailyforprestashop/views/js/front/';
        self::assertSame([$base . 'consent.js', $base . 'attribution.js'], $controller->scripts);
        $definition = \Media::getJsDef()[PageScripts::JS_DEF];
        self::assertNull($definition['relayUrl']);
        self::assertNull($definition['recommendationsUrl']);
        self::assertNotNull($definition['landUrl']);
    }

    /**
     * What the front controller `recommendations` answers to a request with these cookies.
     *
     * @param array<string, string> $cookies
     *
     * @return array{int, string}
     */
    private function answer(array $cookies, ?\Customer $customer = null, string $fetchSite = ''): array
    {
        $context = \Context::getContext();
        $this->themedRequest($cookies);
        if ($customer !== null) {
            $context->customer = $customer;
        }

        return $this->services->get(RecommendationsBlock::class)->answer($context, $fetchSite, new Cookies($cookies, '/', false, static fn (): bool => true));
    }

    /**
     * The customer as a logged-in shopper. PrestaShop asks its own session cookie whether a
     * customer is logged in, and a test has no session.
     */
    private function loggedIn(int $idCustomer): \Customer
    {
        return new class($idCustomer) extends \Customer {
            public function isLogged($withGuest = false)
            {
                return true;
            }
        };
    }

    /**
     * The HTML without the comments that Smarty adds around a template in debug mode.
     */
    private static function withoutTemplateComments(string $html): string
    {
        return trim((string) preg_replace('/<!--.*?-->/s', '', $html));
    }
}
