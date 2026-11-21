<?php
declare(strict_types=1);

namespace Tests\Unit\Storefront;

use PHPUnit\Framework\TestCase;
use PrestaShop\Module\SmailyForPrestaShop\Config\EngineSettings;
use PrestaShop\Module\SmailyForPrestaShop\Install\Uninstaller;
use PrestaShop\Module\SmailyForPrestaShop\Migration\LegacyConfigMapper;
use Tests\Unit\Migration\LegacyFixture;

require_once __DIR__ . '/../Migration/LegacyFixture.php';

/**
 * An upgrade never switches browse tracking on: no store that 2.x or 1.6.x left gets the consent
 * setting, at any scope. The setting is on only where a merchant confirmed it.
 */
final class ConsentAfterUpgradeTest extends TestCase
{
    /**
     * @dataProvider legacyStores
     *
     * @param array<int, int> $shopGroups
     */
    public function testNoLegacyStoresPlanStoresTheConsentSetting(string $fixture, string $from, array $shopGroups): void
    {
        $plan = (new LegacyConfigMapper())->map(LegacyFixture::state($fixture, $from, $shopGroups));

        self::assertNotSame([], $plan->settings());
        foreach ($plan->settings() as $idShop => $settings) {
            self::assertArrayNotHasKey(EngineSettings::CONSENT_CONFIRMED, $settings, 'shop ' . $idShop);
        }
    }

    /**
     * @return iterable<string, array{string, string, array<int, int>}>
     */
    public static function legacyStores(): iterable
    {
        yield '2.2.0 right after install' => ['2.2.0-fresh-install.sql', '2.2.0', [1 => 1]];
        yield '2.2.0 configured' => ['2.2.0-configured.sql', '2.2.0', [1 => 1]];
        yield '1.6.2 configured' => ['1.6.2-configured.sql', '1.6.2', [1 => 1]];
        yield '2.2.0 with a second shop row, multistore off' => ['2.2.0-multistore-off-two-shops.sql', '2.2.0', [1 => 1, 2 => 1]];
        yield '2.2.0 multistore, own rows' => ['2.2.0-multistore-own-rows.sql', '2.2.0', [1 => 1, 2 => 1]];
        yield '2.2.0 multistore, shared' => ['2.2.0-multistore-shared.sql', '2.2.0', [1 => 1, 2 => 1]];
    }

    public function testAnUninstallRemovesTheSetting(): void
    {
        self::assertSame('SMAILY_CONNECT_CONSENT_CONFIRMED', EngineSettings::CONSENT_CONFIRMED);
        self::assertContains(EngineSettings::CONSENT_CONFIRMED, Uninstaller::KEYS);
    }
}
