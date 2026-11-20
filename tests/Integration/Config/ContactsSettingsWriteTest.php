<?php
declare(strict_types=1);

namespace Tests\Integration\Config;

use PrestaShop\Module\SmailyForPrestaShop\Config\ContactsSettings;
use PrestaShop\Module\SmailyForPrestaShop\Contacts\ContactSyncMode;
use Tests\Integration\IntegrationTestCase;
use Tests\Integration\MultistoreShop;

require_once __DIR__ . '/../MultistoreShop.php';

/**
 * The Initial setup stores the Contacts settings where ContactsSettings reads them, at the global
 * scope or at one shop's; contact sync is active only once they are enabled and confirmed.
 */
final class ContactsSettingsWriteTest extends IntegrationTestCase
{
    use MultistoreShop;

    private const KEYS = [
        ContactsSettings::ENABLED,
        ContactsSettings::CONFIRMED,
        ContactsSettings::MODE,
        ContactsSettings::GUESTS,
        ContactsSettings::FIELDS,
        ContactsSettings::CHECKOUT_CHECKBOX,
    ];

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

    public function testTheSavedSettingsReadBackAndSyncStartsOnlyOnceConfirmed(): void
    {
        $settings = new ContactsSettings();
        $idShop = (int) \Configuration::get('PS_SHOP_DEFAULT');
        self::assertFalse($settings->isStored($idShop));
        self::assertNull($settings->checkoutCheckbox($idShop));

        $settings->setMode(new ContactSyncMode(ContactSyncMode::ALL_CUSTOMERS, true), 0);
        $settings->setFields(['website', 'first_name', 'unknown'], 0);
        $settings->setCheckoutCheckbox(true, 0);
        $settings->setEnabled(true, 0);

        self::assertTrue($settings->isStored($idShop));
        self::assertSame(ContactSyncMode::ALL_CUSTOMERS, $settings->mode($idShop)->mode());
        self::assertTrue($settings->mode($idShop)->includesNewsletterGuests());
        self::assertSame(['first_name', 'website'], $settings->fields($idShop));
        self::assertSame('store,first_name,website', \Configuration::getGlobalValue(ContactsSettings::FIELDS));
        self::assertTrue($settings->checkoutCheckbox($idShop));
        self::assertFalse($settings->syncActive($idShop), 'not confirmed yet');

        $settings->confirm(0);
        self::assertTrue($settings->syncActive($idShop));

        $settings->setMode(new ContactSyncMode(ContactSyncMode::SUBSCRIBERS, false), 0);
        $settings->setFields([], 0);
        self::assertFalse($settings->mode($idShop)->includesNewsletterGuests());
        self::assertSame([], $settings->fields($idShop));
    }

    public function testAShopsOwnSettingsLeaveTheOtherShops(): void
    {
        $this->openMultistore();
        $default = $this->defaultShopId();
        $shop = $this->addShop((int) \Shop::getGroupFromShop($default));
        $settings = new ContactsSettings();

        $settings->setMode(new ContactSyncMode(ContactSyncMode::CHECKOUT_OPTIN, false), $shop);
        $settings->setEnabled(true, $shop);
        $settings->confirm($shop);

        self::assertTrue($settings->syncActive($shop));
        self::assertSame(ContactSyncMode::CHECKOUT_OPTIN, $settings->mode($shop)->mode());
        self::assertFalse($settings->syncActive($default));
        self::assertFalse($settings->isStored($default));
    }
}
