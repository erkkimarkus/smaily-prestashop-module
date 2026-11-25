<?php
declare(strict_types=1);

namespace Tests\Integration\Storefront;

use PrestaShop\Module\SmailyForPrestaShop\Config\EngineSettings;
use PrestaShop\Module\SmailyForPrestaShop\Config\MigrationNotices;
use PrestaShop\Module\SmailyForPrestaShop\Migration\LegacyConfigMapper;
use PrestaShop\Module\SmailyForPrestaShop\Migration\LegacyFiles;
use PrestaShop\Module\SmailyForPrestaShop\Migration\LegacyOverride;
use PrestaShop\Module\SmailyForPrestaShop\Migration\LegacyReader;
use PrestaShop\Module\SmailyForPrestaShop\Migration\Upgrade300;
use PrestaShop\Module\SmailyForPrestaShop\Runtime\Provider\MigrationProvider;
use PrestaShop\Module\SmailyForPrestaShop\Runtime\Services;
use PrestaShop\Module\SmailyForPrestaShop\Smaily\AccountStore;
use PrestaShop\Module\SmailyForPrestaShop\Storefront\PageScripts;
use Tests\Integration\Migration\LegacyStore;
use Tests\Integration\MultistoreShop;

require_once __DIR__ . '/StorefrontTestCase.php';
require_once __DIR__ . '/../Migration/LegacyStore.php';
require_once __DIR__ . '/../MultistoreShop.php';

/**
 * The merchant's consent setting "I confirm that my store's cookie banner lets a visitor choose
 * whether to allow Smaily to track their browsing.": off after an install and after an upgrade, and on only where the merchant confirmed
 * it.
 */
final class ConsentSettingTest extends StorefrontTestCase
{
    use MultistoreShop;

    protected function tearDown(): void
    {
        if (defined('_PS_ROOT_DIR_')) {
            $this->closeMultistore();
        }
        parent::tearDown();
    }

    public function testTheSettingIsOffAndNotStoredAfterAnInstall(): void
    {
        self::assertSame(0, self::rows());
        self::assertFalse($this->settings()->consentConfirmed(0));
        self::assertFalse($this->settings()->consentConfirmed(1));
    }

    public function testAConnectionAloneDoesNotSwitchItOn(): void
    {
        $connection = $this->connect();

        self::assertSame(0, self::rows());
        self::assertFalse($this->definition()['consentConfirmed']);
        self::assertSame(0, $connection->idShop());
    }

    public function testThePageSaysConfirmedOnlyAfterTheMerchantConfirmed(): void
    {
        $this->connect();

        $this->confirmConsent();
        self::assertTrue($this->definition()['consentConfirmed']);

        $this->confirmConsent(false);
        self::assertFalse($this->definition()['consentConfirmed']);
    }

    /**
     * @dataProvider legacyStores
     */
    public function testTheSettingIsOffAndNotStoredAfterAnUpgrade(string $fixture, string $from): void
    {
        $moduleDir = sys_get_temp_dir() . '/smaily-legacy-files-' . bin2hex(random_bytes(4));
        mkdir($moduleDir);
        LegacyStore::clear();
        try {
            LegacyStore::loadFixture($fixture);
            self::db()->execute('UPDATE `' . _DB_PREFIX_ . 'module` SET `version` = \'' . pSQL($from) . '\' WHERE `name` = \'smailyforprestashop\'');

            $upgrade = new Upgrade300(
                new LegacyReader(),
                new LegacyConfigMapper(),
                Services::instance()->get(AccountStore::class),
                new MigrationNotices(),
                new LegacyOverride(_PS_OVERRIDE_DIR_),
                new LegacyFiles($moduleDir)
            );
            self::assertTrue($upgrade->runPending());
            self::assertSame('done', $upgrade->state()['status']);

            self::assertSame(0, self::rows());
            self::assertFalse($this->settings()->consentConfirmed(0));
            self::assertFalse($this->settings()->consentConfirmed(1));
        } finally {
            rmdir($moduleDir);
            LegacyStore::restore();
            (new MigrationProvider())->register(Services::instance());
            self::db()->execute('UPDATE `' . _DB_PREFIX_ . 'module` SET `version` = \'3.0.0\' WHERE `name` = \'smailyforprestashop\'');
        }
    }

    /**
     * @return array<string, array{string, string}>
     */
    public function legacyStores(): array
    {
        return [
            '2.2.0 configured' => ['2.2.0-configured.sql', '2.2.0'],
            '2.2.0 fresh install' => ['2.2.0-fresh-install.sql', '2.2.0'],
            '1.6.2 configured' => ['1.6.2-configured.sql', '1.6.2'],
        ];
    }

    public function testAShopCanConfirmForItselfAndOtherwiseReadsTheGlobalValue(): void
    {
        $this->openMultistore();
        $first = $this->defaultShopId();
        $second = $this->addShop((int) \Shop::getGroupFromShop($first));

        $this->settings()->setConsentConfirmed(true, $second);
        self::assertTrue($this->settings()->consentConfirmed($second));
        self::assertFalse($this->settings()->consentConfirmed($first));
        self::assertFalse($this->settings()->consentConfirmed(0));

        $this->settings()->setConsentConfirmed(true, 0);
        self::assertTrue($this->settings()->consentConfirmed($first), 'a shop without a value of its own reads the global one');
    }

    private function settings(): EngineSettings
    {
        return $this->services->get(EngineSettings::class);
    }

    /**
     * What the home page of the default shop prints for the storefront scripts.
     *
     * @return array<string, mixed>
     */
    private function definition(): array
    {
        $this->storefrontRequest();
        $this->hook('actionFrontControllerSetMedia');

        return \Media::getJsDef()[PageScripts::JS_DEF];
    }

    private static function rows(): int
    {
        return (int) self::db()->getValue('SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'configuration` WHERE `name` = \'' . pSQL(EngineSettings::CONSENT_CONFIRMED) . '\'', false);
    }
}
