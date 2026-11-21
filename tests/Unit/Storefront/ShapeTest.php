<?php
declare(strict_types=1);

namespace Tests\Unit\Storefront;

use PHPUnit\Framework\TestCase;
use PrestaShop\Module\SmailyForPrestaShop\Storefront\ClientConfig;
use PrestaShop\Module\SmailyForPrestaShop\Engine\Connection;
use PrestaShop\Module\SmailyForPrestaShop\Storefront\Shape;
use PrestaShop\Module\SmailyForPrestaShop\Storefront\VisitorToken;

/**
 * The shapes of the attribution values, the names and lifetimes of the cookies, and the
 * store-created visitor token.
 */
final class ShapeTest extends TestCase
{
    public function testARecommendationIdIsAUuidAndNothingElse(): void
    {
        self::assertTrue(Shape::isRecId('3fa85f64-5717-4562-b3fc-2c963f66afa6'));
        self::assertTrue(Shape::isRecId('3FA85F64-5717-4562-B3FC-2C963F66AFA6'));
        foreach (['', 'rec_abc123', '3fa85f64-5717-4562-b3fc-2c963f66afa', "3fa85f64-5717-4562-b3fc-2c963f66afa6\n", '3fa85f64-5717-4562-b3fc-2c963f66afa6 '] as $value) {
            self::assertFalse(Shape::isRecId($value), var_export($value, true));
        }
    }

    public function testALinksVisitorTokenIsTheEnginesFormOnly(): void
    {
        self::assertTrue(Shape::isEngineVisitorToken('vt_8f3k2a9xq4'));
        self::assertFalse(Shape::isEngineVisitorToken('vs_0123456789ABCDEFGHIJKL'));
        self::assertFalse(Shape::isEngineVisitorToken('vt_'));
        self::assertFalse(Shape::isEngineVisitorToken('vt_' . str_repeat('a', 62)), 'more than 64 characters in all');
        self::assertFalse(Shape::isEngineVisitorToken('vt_ab-cd'));
    }

    public function testAStoreCreatedTokenHasExactly22Alphanumerics(): void
    {
        self::assertTrue(Shape::isStoreVisitorToken('vs_0123456789ABCDEFGHIJKL'));
        self::assertFalse(Shape::isStoreVisitorToken('vs_0123456789ABCDEFGHIJK'));
        self::assertFalse(Shape::isStoreVisitorToken('vs_0123456789ABCDEFGHIJKLM'));
        self::assertTrue(Shape::isVisitorToken('vs_0123456789ABCDEFGHIJKL'));
        self::assertTrue(Shape::isVisitorToken('vt_8f3k2a9xq4'));
        self::assertFalse(Shape::isVisitorToken('someone@example.test'));
    }

    public function testAContextAndASessionIdAreShortPlainTokens(): void
    {
        self::assertTrue(Shape::isContext('cart_abandoned'));
        self::assertTrue(Shape::isSessionId('0f8fad5b-d9cb-469f-a165-70867728950e'));
        self::assertFalse(Shape::isContext(''));
        self::assertFalse(Shape::isContext('a b'));
        self::assertFalse(Shape::isContext(str_repeat('a', 65)));
        self::assertFalse(Shape::isSessionId('<script>'));
    }

    public function testAGeneratedTokenHasTheContractsFormAndDiffersEachTime(): void
    {
        $tokens = [];
        for ($i = 0; $i < 50; ++$i) {
            $token = VisitorToken::generate();
            self::assertMatchesRegularExpression('/^vs_[A-Za-z0-9]{22}$/D', $token);
            self::assertTrue(Shape::isStoreVisitorToken($token));
            $tokens[$token] = true;
        }
        self::assertCount(50, $tokens);
    }

    public function testTheNamesAndLifetimesAreTheContractsDefaultsWithoutAConfig(): void
    {
        $names = new ClientConfig(new Connection(1, 0, '', 'tenant', 'key', []));

        self::assertSame('smaily_rec_uid', $names->cookieName(ClientConfig::VISITOR));
        self::assertSame('smaily_anon_sid', $names->cookieName(ClientConfig::SESSION));
        self::assertSame('smaily_rec_id', $names->cookieName(ClientConfig::REC_ID));
        self::assertSame('smaily_rec_ctx', $names->cookieName(ClientConfig::CONTEXT));
        self::assertSame([365, 30, 30, 30], array_map([$names, 'days'], [ClientConfig::VISITOR, ClientConfig::SESSION, ClientConfig::REC_ID, ClientConfig::CONTEXT]));
        self::assertSame('smaily_vt', $names->parameter(ClientConfig::VISITOR));
        self::assertSame('smaily_rec', $names->parameter(ClientConfig::REC_ID));
        self::assertSame('smaily_ctx', $names->parameter(ClientConfig::CONTEXT));
    }

    public function testTheConnectionsConfigNamesThemAndABadValueFallsBackToTheDefault(): void
    {
        $names = new ClientConfig(new Connection(1, 0, '', 'tenant', 'key', ['config' => [
            'tracking_cookie_name' => 'shop_uid',
            'cookie_ttl_days' => 90,
            'session_cookie_name' => 'bad name; Path=/',
            'session_ttl_days' => '30 days',
            'rec_id_ttl_days' => 0,
            'url_param_rec_id' => 'rec',
            'url_param_context' => ['ctx'],
        ]]));

        self::assertSame('shop_uid', $names->cookieName(ClientConfig::VISITOR));
        self::assertSame(90, $names->days(ClientConfig::VISITOR));
        self::assertSame('smaily_anon_sid', $names->cookieName(ClientConfig::SESSION));
        self::assertSame(30, $names->days(ClientConfig::SESSION));
        self::assertSame(30, $names->days(ClientConfig::REC_ID));
        self::assertSame('rec', $names->parameter(ClientConfig::REC_ID));
        self::assertSame('smaily_ctx', $names->parameter(ClientConfig::CONTEXT));
    }
}
