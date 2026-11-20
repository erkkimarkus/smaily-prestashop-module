<?php
declare(strict_types=1);

namespace Tests\Integration\Scope;

use PrestaShop\Module\SmailyForPrestaShop\Scope\StoreLayout;
use Tests\Integration\IntegrationTestCase;
use Tests\Integration\MultistoreShop;

require_once __DIR__ . '/../MultistoreShop.php';

/**
 * The Initial setup stores the shop layout, a shop's language mode and its fallback language
 * where StoreLayout reads them: the layout globally, the language settings at the global scope or
 * at one shop's.
 */
final class StoreLayoutWriteTest extends IntegrationTestCase
{
    use MultistoreShop;

    private const KEYS = [StoreLayout::SEPARATE_STORES, StoreLayout::LANGUAGE_MODE, StoreLayout::FALLBACK_LANGUAGE];

    /** @var array<string, list<array<string, mixed>>> */
    private array $saved = [];

    protected function setUp(): void
    {
        parent::setUp();
        foreach (self::KEYS as $key) {
            $this->saved[$key] = (array) self::db()->executeS('SELECT * FROM `' . _DB_PREFIX_ . 'configuration` WHERE `name` = \'' . pSQL($key) . '\'');
            \Configuration::deleteByName($key);
        }
    }

    protected function tearDown(): void
    {
        if (defined('_PS_ROOT_DIR_')) {
            $this->closeMultistore();
            foreach (self::KEYS as $key) {
                \Configuration::deleteByName($key);
                foreach ($this->saved[$key] as $row) {
                    self::db()->insert('configuration', $row, true);
                }
            }
            \Configuration::resetStaticCache();
            \Configuration::loadConfiguration();
        }
        parent::tearDown();
    }

    public function testTheLayoutIsUnchosenUntilOneIsStored(): void
    {
        $layout = new StoreLayout();
        self::assertFalse($layout->layoutChosen());

        self::assertTrue($layout->setSeparateStores(false));
        self::assertTrue($layout->layoutChosen());
        self::assertFalse($layout->separateStores());

        self::assertTrue($layout->setSeparateStores(true));
        self::assertTrue($layout->separateStores());
    }

    public function testALanguageModeAndFallbackOfOneShopLeaveTheGlobalOnes(): void
    {
        $this->openMultistore();
        $shop = $this->addShop((int) \Shop::getGroupFromShop($this->defaultShopId()));
        $layout = new StoreLayout();

        $layout->setLanguageMode(StoreLayout::MODE_WORKFLOW_PER_LANGUAGE, 0);
        $layout->setLanguageMode(StoreLayout::MODE_ACCOUNT_PER_LANGUAGE, $shop);
        $layout->setFallbackLanguage('et', $shop);

        self::assertSame(StoreLayout::MODE_ACCOUNT_PER_LANGUAGE, $layout->languageMode($shop));
        self::assertSame('et', $layout->fallbackLanguage($shop));
        self::assertSame(StoreLayout::MODE_WORKFLOW_PER_LANGUAGE, $layout->languageMode($this->defaultShopId()));
        self::assertSame('', $layout->fallbackLanguage($this->defaultShopId()));
    }

    public function testRefusesAnUnknownModeAndALanguageThatIsNoIsoCode(): void
    {
        $layout = new StoreLayout();

        try {
            $layout->setLanguageMode('x', 0);
            self::fail('an unknown mode is stored');
        } catch (\InvalidArgumentException $expected) {
        }
        $this->expectException(\InvalidArgumentException::class);
        $layout->setFallbackLanguage('', 0);
    }
}
