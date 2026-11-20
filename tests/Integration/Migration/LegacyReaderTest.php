<?php
declare(strict_types=1);

namespace Tests\Integration\Migration;

use PrestaShop\Module\SmailyForPrestaShop\Migration\LegacyReader;
use Tests\Integration\IntegrationTestCase;

require_once __DIR__ . '/LegacyStore.php';

/**
 * The legacy reader takes the state that 2.x or 1.6.x left exactly as the database holds it, and
 * writes nothing.
 */
final class LegacyReaderTest extends IntegrationTestCase
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
        }
        parent::tearDown();
    }

    public function testReadsEveryLegacyRowInStorageOrderWithNullKept(): void
    {
        LegacyStore::insertRows([
            [null, null, 'SMAILY_RSS_LIMIT', '50'],
            [null, null, 'SMAILY_SUBDOMAIN', null],
            [1, 1, 'SMAILY_RSS_LIMIT', '20'],
            [null, null, 'SMAILY_CONNECT_SETUP_STATE', 'new'],
        ]);

        $state = (new LegacyReader())->read('2.2.0');

        self::assertSame([
            ['id_shop_group' => null, 'id_shop' => null, 'name' => 'SMAILY_RSS_LIMIT', 'value' => '50'],
            ['id_shop_group' => null, 'id_shop' => null, 'name' => 'SMAILY_SUBDOMAIN', 'value' => null],
            ['id_shop_group' => 1, 'id_shop' => 1, 'name' => 'SMAILY_RSS_LIMIT', 'value' => '20'],
        ], $state->rows());
        self::assertSame('2.2.0', $state->previousVersion());
    }

    public function testReadsTheShopTreeOfThisShop(): void
    {
        $state = (new LegacyReader())->read('2.2.0');

        self::assertFalse($state->multishopActive());
        self::assertSame((int) \Configuration::get('PS_SHOP_DEFAULT'), $state->defaultShopId());
        self::assertSame([1 => 1], $state->shopGroups());
        self::assertFalse($state->groupSharesCustomers(1));
    }

    public function testCountsTheSentCarts(): void
    {
        self::assertSame(0, (new LegacyReader())->read('2.2.0')->sentCartCount(), 'no smaily_cart table');

        LegacyStore::createSentCarts(3);

        self::assertSame(3, (new LegacyReader())->read('2.2.0')->sentCartCount());
    }

    public function testReadsTheRecordedModuleVersion(): void
    {
        self::assertSame(self::module()->version, (new LegacyReader())->moduleVersion());
    }
}
