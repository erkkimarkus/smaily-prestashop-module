<?php
declare(strict_types=1);

namespace Tests\Unit\Storefront;

use PHPUnit\Framework\TestCase;
use PrestaShop\Module\SmailyForPrestaShop\Storefront\Landing;

require_once __DIR__ . '/StorefrontFixture.php';

/**
 * The attribution cookies: what the landing endpoint sets, with which attributes, and what a
 * request's cookies read as.
 */
final class AttributionTest extends TestCase
{
    private const REC_ID = '3fa85f64-5717-4562-b3fc-2c963f66afa6';

    private const STORE_TOKEN = 'vs_0123456789ABCDEFGHIJKL';

    private StorefrontFixture $fixture;

    protected function setUp(): void
    {
        $this->fixture = new StorefrontFixture();
    }

    public function testALandingSetsTheThreeCookiesFromTheServerWithTheDesignedAttributes(): void
    {
        $status = $this->landing()->handle(1, 'POST', ['rec_id' => self::REC_ID, 'context' => 'cart_abandoned', 'visitor_token' => 'vt_8f3k2a9xq4'], $this->fixture->cookies());

        self::assertSame(204, $status);
        self::assertSame(['smaily_rec_id', 'smaily_rec_ctx', 'smaily_rec_uid'], array_column($this->fixture->sent, 0));
        self::assertSame([self::REC_ID, 'cart_abandoned', 'vt_8f3k2a9xq4'], array_column($this->fixture->sent, 1));
        $day = 86400;
        foreach ([30, 30, 365] as $index => $days) {
            $options = $this->fixture->sent[$index][2];
            self::assertEqualsWithDelta(time() + $days * $day, $options['expires'], 5);
            self::assertSame(['path' => '/', 'secure' => false, 'httponly' => false, 'samesite' => 'Lax'], array_diff_key($options, ['expires' => 0]));
        }
    }

    public function testALandingWithoutAContextDeletesTheContextCookie(): void
    {
        self::assertSame(204, $this->landing()->handle(1, 'POST', ['rec_id' => self::REC_ID], $this->fixture->cookies()));

        self::assertSame(['smaily_rec_id', 'smaily_rec_ctx'], array_column($this->fixture->sent, 0));
        self::assertSame('', $this->fixture->sent[1][1]);
        self::assertSame(1, $this->fixture->sent[1][2]['expires'], 'a time in the past deletes the cookie');
    }

    public function testAContextWithoutARecommendationIdSetsNothing(): void
    {
        self::assertSame(400, $this->landing()->handle(1, 'POST', ['context' => 'welcome'], $this->fixture->cookies()));
        self::assertSame([], $this->fixture->sent);
    }

    public function testAMalformedIdAndAStoreCreatedTokenAreNotTaken(): void
    {
        $post = ['rec_id' => 'rec_abc123', 'context' => 'welcome', 'visitor_token' => self::STORE_TOKEN];

        self::assertSame(400, $this->landing()->handle(1, 'POST', $post, $this->fixture->cookies()));
        self::assertSame([], $this->fixture->sent);

        self::assertSame(204, $this->landing()->handle(1, 'POST', ['visitor_token' => 'vt_8f3k2a9xq4', 'rec_id' => ['x']], $this->fixture->cookies()));
        self::assertSame(['smaily_rec_uid'], array_column($this->fixture->sent, 0));
    }

    public function testALandingIsNotGatedOnTheConsentSetting(): void
    {
        $this->fixture->consentConfirmed = false;

        self::assertSame(204, $this->landing()->handle(1, 'POST', ['rec_id' => self::REC_ID], $this->fixture->cookies()));
    }

    public function testTheLandingRefusesAnotherMethodAShopWithoutAConnectionAndAFlood(): void
    {
        self::assertSame(405, $this->landing()->handle(1, 'GET', ['rec_id' => self::REC_ID], $this->fixture->cookies()));

        $landing = $this->landing();
        for ($i = 0; $i < Landing::LIMIT_PER_MINUTE; ++$i) {
            self::assertSame(204, $landing->handle(1, 'POST', ['rec_id' => self::REC_ID], $this->fixture->cookies()));
        }
        self::assertSame(429, $landing->handle(1, 'POST', ['rec_id' => self::REC_ID], $this->fixture->cookies()));

        $this->fixture->connection = null;
        self::assertSame(404, $this->landing()->handle(1, 'POST', ['rec_id' => self::REC_ID], $this->fixture->cookies()));
    }

    public function testACookieThatCannotBeSentAnswers500SoTheScriptWritesItItself(): void
    {
        self::assertSame(500, $this->landing()->handle(1, 'POST', ['rec_id' => self::REC_ID], $this->fixture->cookies([], false)));
    }

    public function testEachCookieIsReadInItsShapeAndAnOffShapeOneAsAbsent(): void
    {
        $read = $this->fixture->attribution()->read($this->fixture->cookies([
            'smaily_rec_id' => ' ' . self::REC_ID . ' ',
            'smaily_rec_ctx' => 'cart abandoned',
            'smaily_rec_uid' => 'vt_8f3k2a9xq4',
            'smaily_anon_sid' => ['a list'],
        ]), $this->fixture->connection);

        self::assertSame(['rec_id' => self::REC_ID, 'context' => null, 'visitor_token' => 'vt_8f3k2a9xq4', 'session_id' => null], $read);
    }

    public function testAnEngineTokenIsReadWhateverTheConsentSettingSays(): void
    {
        $this->fixture->consentConfirmed = false;

        $read = $this->fixture->attribution()->read($this->fixture->cookies(['smaily_rec_uid' => 'vt_8f3k2a9xq4']), $this->fixture->connection);

        self::assertSame('vt_8f3k2a9xq4', $read['visitor_token']);
    }

    public function testAStoreCreatedTokenReadsAsAbsentUntilTheMerchantConfirmedTheBanner(): void
    {
        $cookies = $this->fixture->cookies(['smaily_rec_uid' => self::STORE_TOKEN]);

        self::assertNull($this->fixture->attribution()->read($cookies, $this->fixture->connection)['visitor_token']);
        self::assertFalse($this->fixture->attribution()->marketingConsent($this->fixture->connection, null));

        $this->fixture->consentConfirmed = true;
        self::assertSame(self::STORE_TOKEN, $this->fixture->attribution()->read($cookies, $this->fixture->connection)['visitor_token']);
        self::assertTrue($this->fixture->attribution()->marketingConsent($this->fixture->connection, null));
    }

    public function testTheCookieNamesAreTheConnectionsOwn(): void
    {
        $connection = StorefrontFixture::connection(['config' => ['rec_id_cookie_name' => 'shop_rec']]);

        $read = $this->fixture->attribution()->read($this->fixture->cookies(['shop_rec' => self::REC_ID, 'smaily_rec_id' => 'ignored']), $connection);

        self::assertSame(self::REC_ID, $read['rec_id']);
    }

    private function landing(): Landing
    {
        return new Landing($this->fixture->connections(), $this->fixture->limiter());
    }
}
