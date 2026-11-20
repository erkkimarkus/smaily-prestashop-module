<?php
declare(strict_types=1);

namespace Tests\Integration\Config;

use PrestaShop\Module\SmailyForPrestaShop\Config\ContactsSettings;
use PrestaShop\Module\SmailyForPrestaShop\Contacts\ContactSyncMode;
use PrestaShop\Module\SmailyForPrestaShop\Migration\Upgrade300;
use PrestaShop\Module\SmailyForPrestaShop\Runtime\Services;
use Tests\Integration\IntegrationTestCase;
use Tests\Integration\Migration\LegacyStore;

require_once __DIR__ . '/../Migration/LegacyStore.php';

/**
 * The Contacts settings read the keys that the 3.0.0 upgrade writes (acceptance 7 of Story 2.2): a
 * configured 2.2.0 store keeps syncing its subscribers and its newsletter-block guests with the
 * fields it selected.
 */
final class ContactsSettingsTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        LegacyStore::clear();
    }

    protected function tearDown(): void
    {
        if (defined('_PS_ROOT_DIR_')) {
            LegacyStore::restore();
            $this->recordModuleVersion('3.0.0');
        }
        parent::tearDown();
    }

    public function testAnUpgraded220StoreSyncsSubscribersAndGuestsWithItsLegacyFields(): void
    {
        LegacyStore::loadFixture('2.2.0-configured.sql');
        $this->recordModuleVersion('2.2.0');

        self::assertTrue(Services::fromProviders(Services::PROVIDERS)->get(Upgrade300::class)->runPending());

        $settings = new ContactsSettings();
        $idShop = (int) \Configuration::get('PS_SHOP_DEFAULT');
        self::assertTrue($settings->syncActive($idShop));
        self::assertSame(ContactSyncMode::SUBSCRIBERS, $settings->mode($idShop)->mode());
        self::assertTrue($settings->mode($idShop)->includesNewsletterGuests());
        self::assertSame(['first_name', 'last_name', 'birthday', 'website'], $settings->fields($idShop));
    }

    public function testAStoreWithNothingStoredSyncsNothing(): void
    {
        $settings = new ContactsSettings();
        $idShop = (int) \Configuration::get('PS_SHOP_DEFAULT');

        self::assertFalse($settings->syncActive($idShop));
        self::assertSame(ContactSyncMode::SUBSCRIBERS, $settings->mode($idShop)->mode());
        self::assertFalse($settings->mode($idShop)->includesNewsletterGuests());
        self::assertSame([], $settings->fields($idShop));
    }

    private function recordModuleVersion(string $version): void
    {
        self::db()->execute(
            'UPDATE `' . _DB_PREFIX_ . 'module` SET `version` = \'' . pSQL($version) . '\' WHERE `name` = \'smailyforprestashop\''
        );
    }
}
