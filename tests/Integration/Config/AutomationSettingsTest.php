<?php
declare(strict_types=1);

namespace Tests\Integration\Config;

use PrestaShop\Module\SmailyForPrestaShop\Automation\Trigger;
use PrestaShop\Module\SmailyForPrestaShop\Config\AutomationSettings;
use PrestaShop\Module\SmailyForPrestaShop\Migration\Upgrade300;
use PrestaShop\Module\SmailyForPrestaShop\Runtime\Services;
use Tests\Integration\IntegrationTestCase;
use Tests\Integration\Migration\LegacyStore;
use Tests\Integration\MultistoreShop;

require_once __DIR__ . '/../Migration/LegacyStore.php';
require_once __DIR__ . '/../MultistoreShop.php';

/**
 * The Automations settings read the keys that the 3.0.0 upgrade writes, and the writers store a
 * shop's own value without touching the global one.
 */
final class AutomationSettingsTest extends IntegrationTestCase
{
    use MultistoreShop;

    protected function setUp(): void
    {
        parent::setUp();
        LegacyStore::clear();
    }

    protected function tearDown(): void
    {
        if (defined('_PS_ROOT_DIR_')) {
            if ($this->addedShops !== []) {
                $this->closeMultistore();
            }
            LegacyStore::restore();
            self::db()->execute('UPDATE `' . _DB_PREFIX_ . 'module` SET `version` = \'3.0.0\' WHERE `name` = \'smailyforprestashop\'');
        }
        parent::tearDown();
    }

    public function testAnUpgraded220StoreKeepsItsWelcomeAndItsAbandonedCart(): void
    {
        LegacyStore::loadFixture('2.2.0-configured.sql');
        self::db()->execute('UPDATE `' . _DB_PREFIX_ . 'module` SET `version` = \'2.2.0\' WHERE `name` = \'smailyforprestashop\'');

        self::assertTrue(Services::fromProviders(Services::PROVIDERS)->get(Upgrade300::class)->runPending());

        $settings = new AutomationSettings();
        $idShop = (int) \Configuration::get('PS_SHOP_DEFAULT');
        self::assertTrue($settings->enabled(Trigger::WELCOME, $idShop));
        self::assertFalse($settings->enabled(Trigger::FIRST_ORDER, $idShop));
        self::assertTrue($settings->enabled(Trigger::ABANDONED_CART, $idShop));
        self::assertSame(45, $settings->cartDelayMinutes($idShop));
        self::assertSame(AutomationSettings::CART_FIELD_NAMES, $settings->cartFields($idShop));
    }

    public function testAShopsOwnValueLeavesTheGlobalOne(): void
    {
        $this->openMultistore();
        $shop = $this->addShop((int) \Shop::getGroupFromShop($this->defaultShopId()));
        $settings = new AutomationSettings();

        self::assertTrue($settings->setEnabled(Trigger::FIRST_ORDER, true, 0));
        self::assertTrue($settings->setEnabled(Trigger::FIRST_ORDER, false, $shop));
        self::assertTrue($settings->setCartDelayMinutes(60, $shop));
        self::assertTrue($settings->setCartFields(['price', 'first_name', 'unknown'], $shop));

        self::assertTrue($settings->enabled(Trigger::FIRST_ORDER, $this->defaultShopId()));
        self::assertFalse($settings->enabled(Trigger::FIRST_ORDER, $shop));
        self::assertSame(60, $settings->cartDelayMinutes($shop));
        self::assertSame(15, $settings->cartDelayMinutes($this->defaultShopId()));
        self::assertSame(['first_name', 'price'], $settings->cartFields($shop));
        self::assertSame('first_name,price', LegacyStore::row(AutomationSettings::CART_FIELDS, (int) \Shop::getGroupFromShop($shop), $shop));
    }
}
