<?php
declare(strict_types=1);

namespace Tests\Unit\Admin;

use PHPUnit\Framework\TestCase;
use PrestaShop\Module\SmailyForPrestaShop\Admin\Setup\ConnectionPanel;
use PrestaShop\Module\SmailyForPrestaShop\Admin\Setup\SetupScope;

/**
 * What the Initial setup works on: the shop it saves, the questions it asks, the language modes
 * it offers; and the subdomain it reads from what the merchant pasted.
 */
final class SetupScopeTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public function pastedSubdomains(): array
    {
        return [
            'the subdomain' => ['demo', 'demo'],
            'its host' => ['demo.sendsmaily.net', 'demo'],
            'the URL of a page' => ['https://Demo.sendsmaily.net/account/settings?x=1', 'demo'],
            'with spaces and a slash' => ['  demo/ ', 'demo'],
            'empty' => ['  ', ''],
        ];
    }

    /**
     * @dataProvider pastedSubdomains
     */
    public function testAPastedUrlIsReducedToItsSubdomain(string $pasted, string $subdomain): void
    {
        self::assertSame($subdomain, ConnectionPanel::normalizeSubdomain($pasted));
    }

    public function testOneShopSavesTheGlobalValuesAndAsksNoLayout(): void
    {
        $scope = new SetupScope(false, true, false, 0, ['en' => 'English']);

        self::assertSame(0, $scope->targetShop());
        self::assertFalse($scope->separateStores(), 'separate stores need several shops');
        self::assertFalse($scope->asksLayoutFirst());
        self::assertFalse($scope->multilingual());
        self::assertSame(['single'], $scope->languageModes(), 'one language: no picker');
    }

    public function testOneShopWithTwoLanguagesOffersEveryMode(): void
    {
        $scope = new SetupScope(false, false, true, 0, ['en' => 'English', 'et' => 'Eesti']);

        self::assertSame(['single', 'a', 'b', 'c'], $scope->languageModes());
    }

    public function testSeveralShopsAskTheLayoutFirstUntilOneIsStored(): void
    {
        self::assertTrue((new SetupScope(true, false, false, 0, []))->asksLayoutFirst());
        self::assertFalse((new SetupScope(true, false, true, 0, []))->asksLayoutFirst());
    }

    public function testOneStoreOfSeveralShopsSavesForAllShopsWithoutAnAccountPerLanguage(): void
    {
        $scope = new SetupScope(true, false, true, 3, ['en' => 'English', 'et' => 'Eesti']);

        self::assertSame(0, $scope->targetShop(), 'whatever shop the selector names');
        self::assertSame(['single', 'b', 'c'], $scope->languageModes());
    }

    public function testSeparateStoresSaveTheSelectedShopAndNothingForAllShops(): void
    {
        $shop = new SetupScope(true, true, true, 3, ['en' => 'English', 'et' => 'Eesti']);
        self::assertSame(3, $shop->targetShop());
        self::assertSame(['single', 'a', 'b', 'c'], $shop->languageModes());

        self::assertNull((new SetupScope(true, true, true, 0, []))->targetShop());
    }
}
