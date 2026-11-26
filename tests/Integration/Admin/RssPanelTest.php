<?php
declare(strict_types=1);

namespace Tests\Integration\Admin;

use PrestaShop\Module\SmailyForPrestaShop\Admin\Settings\RssPanel;
use PrestaShop\Module\SmailyForPrestaShop\Config\RssSettings;
use Symfony\Component\Translation\IdentityTranslator;
use Tests\Integration\IntegrationTestCase;

/**
 * Settings > RSS: the feed URL builder opens with the stored defaults that the upgrade migrated
 * from 2.x, or the feed's own defaults on a fresh install, and saves only values the feed takes. It
 * offers the shop's active languages, each with its address, and starts on the employee's.
 */
final class RssPanelTest extends IntegrationTestCase
{
    private const KEYS = [RssSettings::CATEGORY, RssSettings::LIMIT, RssSettings::SORT_BY, RssSettings::SORT_ORDER];

    /** @var array<string, list<array<string, mixed>>> */
    private array $savedKeys = [];

    protected function setUp(): void
    {
        parent::setUp();
        foreach (self::KEYS as $key) {
            $this->savedKeys[$key] = (array) self::db()->executeS('SELECT * FROM `' . _DB_PREFIX_ . 'configuration` WHERE `name` = \'' . pSQL($key) . '\'');
            \Configuration::deleteByName($key);
        }
    }

    protected function tearDown(): void
    {
        if (defined('_PS_ROOT_DIR_')) {
            foreach (self::KEYS as $key) {
                \Configuration::deleteByName($key);
                foreach ($this->savedKeys[$key] as $row) {
                    self::db()->insert('configuration', $row, true);
                }
            }
            \Configuration::resetStaticCache();
            \Configuration::loadConfiguration();
        }
        parent::tearDown();
    }

    public function testAFreshInstallOpensWithTheFeedsOwnDefaultsAndItsUrl(): void
    {
        $values = $this->panel()->values();

        self::assertSame(['category' => 0, 'limit' => 50, 'sort_by' => 'date_upd', 'sort_order' => 'desc'], $values['defaults']);
        self::assertStringContainsString('SmailyRssFeed', $values['base_url']);
        self::assertStringNotContainsString('limit=', $values['base_url'], 'the base URL carries no parameter');
    }

    public function testTheFeedsAddressDoesNotDependOnTheEmployeesLanguage(): void
    {
        $context = \Context::getContext();
        $language = $context->language;
        $url = $this->panel()->values()['base_url'];
        try {
            $other = clone $language;
            $other->id = (int) $language->id + 1000;
            $other->iso_code = 'zz';
            $context->language = $other;

            self::assertSame($url, $this->panel()->values()['base_url']);
        } finally {
            $context->language = $language;
        }
        self::assertStringStartsWith($context->link->getBaseLink(null, true), $url);
        self::assertDoesNotMatchRegularExpression('#/[a-z]{2}/module/#', $url, 'no language prefix');
        self::assertTrue(
            (string) parse_url($url, PHP_URL_PATH) === $context->shop->getBaseURI() . 'module/smailyforprestashop/SmailyRssFeed'
                || str_contains($url, 'controller=SmailyRssFeed'),
            'the dispatcher\'s module route, friendly or not'
        );
    }

    public function testTheLanguageSelectListsTheShopsActiveLanguagesAndStartsOnTheEmployees(): void
    {
        $context = \Context::getContext();
        $language = $context->language;
        $default = (int) \Configuration::get('PS_LANG_DEFAULT');
        $values = $this->panel()->values();

        self::assertSame(
            array_map('intval', array_column(\Language::getLanguages(true, (int) $context->shop->id), 'id_lang')),
            array_column($values['languages'], 'id')
        );
        $urls = array_column($values['languages'], 'url', 'id');
        self::assertSame($values['base_url'], $urls[$default], 'the default language\'s address has no prefix');
        self::assertSame((int) $language->id, $values['language'], 'the employee\'s language, active in the shop');
        try {
            $other = clone $language;
            $other->id = (int) $language->id + 1000;
            $context->language = $other;

            self::assertSame($default, $this->panel()->values()['language'], 'a language the shop does not have active: the default');
        } finally {
            $context->language = $language;
        }
    }

    public function testTheBuilderOpensWithTheValuesTheUpgradeMigrated(): void
    {
        \Configuration::updateGlobalValue(RssSettings::CATEGORY, '4');
        \Configuration::updateGlobalValue(RssSettings::LIMIT, '120');
        \Configuration::updateGlobalValue(RssSettings::SORT_BY, 'price');
        \Configuration::updateGlobalValue(RssSettings::SORT_ORDER, 'asc');

        self::assertSame(['category' => 4, 'limit' => 120, 'sort_by' => 'price', 'sort_order' => 'asc'], $this->panel()->values()['defaults']);
    }

    public function testAStoredValueTheFeedDoesNotTakeReadsAsTheFeedsDefault(): void
    {
        \Configuration::updateGlobalValue(RssSettings::LIMIT, '900');
        \Configuration::updateGlobalValue(RssSettings::SORT_BY, 'reference');

        $defaults = $this->panel()->values()['defaults'];

        self::assertSame([50, 'date_upd'], [$defaults['limit'], $defaults['sort_by']]);
    }

    public function testSaveStoresTheDefaultsAndRefusesValuesTheFeedDoesNotTake(): void
    {
        $panel = $this->panel();

        self::assertSame([], $panel->save(['category' => '12', 'limit' => '25', 'sort_by' => 'name', 'sort_order' => 'asc']));
        self::assertSame(['category' => 12, 'limit' => 25, 'sort_by' => 'name', 'sort_order' => 'asc'], (new RssSettings())->defaults());

        self::assertSame([], $panel->save(['category' => '', 'limit' => '50', 'sort_by' => 'date_upd', 'sort_order' => 'desc']), 'an empty category is all products');
        self::assertSame(0, (new RssSettings())->defaults()['category']);

        $refused = $panel->save(['category' => '-1', 'limit' => '251', 'sort_by' => 'reference', 'sort_order' => 'up']);
        self::assertSame(['category', 'limit', 'sort_by', 'sort_order'], array_column($refused, 'field'));
        self::assertSame(['category' => 0, 'limit' => 50, 'sort_by' => 'date_upd', 'sort_order' => 'desc'], (new RssSettings())->defaults(), 'nothing was written');
    }

    private function panel(): RssPanel
    {
        return new RssPanel(new RssSettings(), new IdentityTranslator());
    }
}
