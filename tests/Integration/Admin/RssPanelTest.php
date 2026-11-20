<?php
declare(strict_types=1);

namespace Tests\Integration\Admin;

use PrestaShop\Module\SmailyForPrestaShop\Admin\Settings\RssPanel;
use PrestaShop\Module\SmailyForPrestaShop\Config\RssSettings;
use Symfony\Component\Translation\IdentityTranslator;
use Tests\Integration\IntegrationTestCase;

/**
 * Settings > RSS: the feed URL builder opens with the stored defaults that the upgrade migrated
 * from 2.x, or the feed's own defaults on a fresh install, and saves only values the feed takes.
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
