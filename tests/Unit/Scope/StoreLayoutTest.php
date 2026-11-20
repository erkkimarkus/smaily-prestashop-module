<?php
declare(strict_types=1);

namespace Tests\Unit\Scope;

use PHPUnit\Framework\TestCase;
use PrestaShop\Module\SmailyForPrestaShop\Scope\StoreLayout;

require_once __DIR__ . '/FakeStoreLayout.php';

/**
 * The store layout as the upgrade and the settings store it: the shops share one setup or each
 * has its own, each shop's language mode, and how the shop groups share customer accounts.
 */
final class StoreLayoutTest extends TestCase
{
    public function testTheShopsShareOneSetupUnlessSeparateStoresIsOn(): void
    {
        self::assertFalse((new FakeStoreLayout([]))->separateStores(), 'a fresh install');
        self::assertFalse((new FakeStoreLayout([0 => [StoreLayout::SEPARATE_STORES => '0']]))->separateStores());
        self::assertTrue((new FakeStoreLayout([0 => [StoreLayout::SEPARATE_STORES => '1']]))->separateStores());
    }

    public function testReadsTheLanguageModeOfAShopElseTheGlobalOneElseSingle(): void
    {
        $layout = new FakeStoreLayout([
            0 => [StoreLayout::LANGUAGE_MODE => 'b'],
            2 => [StoreLayout::LANGUAGE_MODE => 'a'],
            3 => [StoreLayout::LANGUAGE_MODE => 'x'],
        ]);

        self::assertSame(StoreLayout::MODE_ACCOUNT_PER_LANGUAGE, $layout->languageMode(2));
        self::assertSame(StoreLayout::MODE_WORKFLOW_PER_LANGUAGE, $layout->languageMode(1));
        self::assertSame(StoreLayout::MODE_SINGLE, $layout->languageMode(3), 'an unknown mode');
        self::assertSame(StoreLayout::MODE_SINGLE, (new FakeStoreLayout([]))->languageMode(1), 'none stored');
    }

    public function testReadsTheFallbackLanguageOfAShop(): void
    {
        $layout = new FakeStoreLayout([2 => [StoreLayout::FALLBACK_LANGUAGE => 'et']]);

        self::assertSame('et', $layout->fallbackLanguage(2));
        self::assertSame('', $layout->fallbackLanguage(1));
    }

    /**
     * @return array<string, array{array<int, bool>, string}>
     */
    public function shopGroups(): array
    {
        return [
            'one shop, its group shares customers' => [[1 => true], 'global'],
            'one shop, its group does not share customers' => [[1 => false], 'website'],
            'several shops in one group that shares customers' => [[1 => true], 'global'],
            'several shops in groups that each share customers' => [[1 => true, 2 => true], 'website'],
            'several shops in groups that do not share customers' => [[1 => false, 2 => false], 'website'],
            'no shop' => [[], 'website'],
        ];
    }

    /**
     * @dataProvider shopGroups
     *
     * @param array<int, bool> $shareCustomerByGroup the groups that hold a shop
     */
    public function testCustomerAccountsAreGlobalOnlyWhenEveryShopIsInOneGroupThatSharesCustomers(array $shareCustomerByGroup, string $scope): void
    {
        self::assertSame($scope, StoreLayout::customerAccountScopeOf($shareCustomerByGroup));
    }
}
