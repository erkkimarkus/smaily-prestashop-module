<?php
declare(strict_types=1);

namespace Tests\Integration\Config;

use PrestaShop\Module\SmailyForPrestaShop\Config\Settings;
use Tests\Integration\IntegrationTestCase;

/**
 * A setting lives at the global scope or at one shop's scope; a shop reads its own value, else the
 * global one. The CI shop is a single shop, where PrestaShop keeps one value for both scopes; the
 * separate shop scope needs a multishop store.
 */
final class SettingsTest extends IntegrationTestCase
{
    private const KEY = Settings::PREFIX . 'TEST_PROBE';

    private ProbeSettings $settings;

    protected function setUp(): void
    {
        parent::setUp();
        \Configuration::deleteByName(self::KEY);
        $this->settings = new ProbeSettings();
    }

    protected function tearDown(): void
    {
        if (defined('_PS_ROOT_DIR_')) {
            \Configuration::deleteByName(self::KEY);
        }
        parent::tearDown();
    }

    public function testReadsNullWhenNoScopeHoldsTheKey(): void
    {
        self::assertNull($this->settings->read(self::KEY, 0));
        self::assertNull($this->settings->read(self::KEY, $this->shopId()));
    }

    public function testAShopWithoutItsOwnValueReadsTheGlobalOne(): void
    {
        self::assertTrue($this->settings->write(self::KEY, 'global', 0));

        self::assertSame('global', $this->settings->read(self::KEY, 0));
        self::assertSame('global', $this->settings->read(self::KEY, $this->shopId()));
    }

    public function testAShopReadsTheValueWrittenForIt(): void
    {
        $this->settings->write(self::KEY, 'global', 0);
        self::assertTrue($this->settings->write(self::KEY, 'shop', $this->shopId()));

        self::assertSame('shop', $this->settings->read(self::KEY, $this->shopId()));
    }

    public function testReadsTheStoredValueAfterTheRequestDroppedItsLoadedConfiguration(): void
    {
        $this->settings->write(self::KEY, 'global', 0);
        \Configuration::deleteByName(Settings::PREFIX . 'TEST_UNRELATED');

        self::assertSame('global', $this->settings->read(self::KEY, 0));
        self::assertSame('global', $this->settings->read(self::KEY, $this->shopId()));
    }

    private function shopId(): int
    {
        return (int) \Configuration::get('PS_SHOP_DEFAULT');
    }
}

final class ProbeSettings extends Settings
{
    public function read(string $key, int $idShop): ?string
    {
        return $this->get($key, $idShop);
    }

    public function write(string $key, string $value, int $idShop): bool
    {
        return $this->set($key, $value, $idShop);
    }
}
