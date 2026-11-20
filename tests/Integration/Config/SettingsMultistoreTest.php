<?php
declare(strict_types=1);

namespace Tests\Integration\Config;

use PrestaShop\Module\SmailyForPrestaShop\Config\Settings;
use Tests\Integration\IntegrationTestCase;
use Tests\Integration\MultistoreShop;

require_once __DIR__ . '/../MultistoreShop.php';

/**
 * On a multistore shop (two shops in one group), removing a shop's own value deletes that shop's
 * row in every admin shop context, and the shop then reads the global value. A shop row that holds
 * NULL would read as '' instead, so no write stores NULL.
 */
final class SettingsMultistoreTest extends IntegrationTestCase
{
    use MultistoreShop;

    private const KEY = Settings::PREFIX . 'TEST_PROBE';

    private RemovingProbeSettings $settings;

    private int $shop;

    private int $otherShop;

    protected function setUp(): void
    {
        parent::setUp();
        \Configuration::deleteByName(self::KEY);
        $this->openMultistore();
        $this->otherShop = $this->defaultShopId();
        $this->shop = $this->addShop((int) \Shop::getGroupFromShop($this->otherShop));
        $this->settings = new RemovingProbeSettings();
    }

    protected function tearDown(): void
    {
        if (defined('_PS_ROOT_DIR_')) {
            \Configuration::deleteByName(self::KEY);
            $this->closeMultistore();
        }
        parent::tearDown();
    }

    /**
     * @return array<string, array{string}>
     */
    public function contexts(): array
    {
        return [
            'All shops' => ['all'],
            'the group of both shops' => ['group'],
            'the shop itself' => ['shop'],
            'the other shop' => ['other shop'],
        ];
    }

    /**
     * @dataProvider contexts
     */
    public function testRemovingAShopsOwnValueDeletesItsRowAndTheShopReadsTheGlobalValue(string $context): void
    {
        self::assertTrue(\Shop::isFeatureActive(), 'the shop is a multistore shop');
        $this->settings->write(self::KEY, 'global', 0);
        $this->settings->write(self::KEY, 'own', $this->shop);
        $this->settings->write(self::KEY, 'other', $this->otherShop);
        self::assertSame('own', $this->settings->read(self::KEY, $this->shop));
        $this->enterContext($context);

        $this->settings->removeOwn(self::KEY, $this->shop);

        self::assertSame([
            ['id_shop_group' => null, 'id_shop' => null, 'value' => 'global'],
            ['id_shop_group' => (int) \Shop::getGroupFromShop($this->otherShop), 'id_shop' => $this->otherShop, 'value' => 'other'],
        ], $this->storedRows());
        self::assertSame('global', $this->settings->read(self::KEY, $this->shop), 'the same request');
        self::assertSame('other', $this->settings->read(self::KEY, $this->otherShop));
        \Configuration::resetStaticCache();
        self::assertSame('global', $this->settings->read(self::KEY, $this->shop), 'the next request');
    }

    public function testRemovingAValueTheShopDoesNotHoldChangesNothing(): void
    {
        $this->settings->write(self::KEY, 'global', 0);

        $this->settings->removeOwn(self::KEY, $this->shop);

        self::assertSame([['id_shop_group' => null, 'id_shop' => null, 'value' => 'global']], $this->storedRows());
        self::assertSame('global', $this->settings->read(self::KEY, $this->shop));
    }

    public function testJsonIsReadAndWrittenAtTheScopeItNames(): void
    {
        self::assertSame([], $this->settings->readJson(self::KEY, $this->shop));
        self::assertTrue($this->settings->writeJson(self::KEY, ['scope' => 'global']));
        self::assertTrue($this->settings->writeJson(self::KEY, ['scope' => 'own'], $this->shop));

        self::assertSame(['scope' => 'global'], $this->settings->readJson(self::KEY));
        self::assertSame(['scope' => 'own'], $this->settings->readJson(self::KEY, $this->shop));
        self::assertSame(['scope' => 'global'], $this->settings->readJson(self::KEY, $this->otherShop));
    }

    public function testTheStoredValueIsTheRowAtExactlyTheScopeItNames(): void
    {
        $this->settings->write(self::KEY, 'global', 0);
        $this->settings->write(self::KEY, 'own', $this->shop);
        self::db()->update('configuration', ['value' => 'written elsewhere'], sprintf('`name` = \'%s\' AND `id_shop` = %d', self::KEY, $this->shop));

        self::assertSame('global', $this->settings->stored(self::KEY));
        self::assertSame('written elsewhere', $this->settings->stored(self::KEY, $this->shop));
        self::assertNull($this->settings->stored(self::KEY, $this->otherShop), 'no fallback to the global row');
        self::assertSame('own', $this->settings->read(self::KEY, $this->shop), 'the request keeps what it loaded');
    }

    private function enterContext(string $context): void
    {
        $group = (int) \Shop::getGroupFromShop($this->shop);
        switch ($context) {
            case 'all':
                \Shop::setContext(\Shop::CONTEXT_ALL);
                break;
            case 'group':
                \Shop::setContext(\Shop::CONTEXT_GROUP, $group);
                break;
            case 'shop':
                \Shop::setContext(\Shop::CONTEXT_SHOP, $this->shop);
                break;
            default:
                \Shop::setContext(\Shop::CONTEXT_SHOP, $this->otherShop);
        }
    }

    /**
     * @return list<array{id_shop_group: int|null, id_shop: int|null, value: string|null}>
     */
    private function storedRows(): array
    {
        return array_map(
            static fn (array $row): array => [
                'id_shop_group' => $row['id_shop_group'] === null ? null : (int) $row['id_shop_group'],
                'id_shop' => $row['id_shop'] === null ? null : (int) $row['id_shop'],
                'value' => $row['value'],
            ],
            self::db()->executeS(sprintf(
                'SELECT `id_shop_group`, `id_shop`, `value` FROM `%sconfiguration` WHERE `name` = \'%s\' ORDER BY `id_shop`',
                _DB_PREFIX_,
                self::KEY
            ), true, false)
        );
    }
}

final class RemovingProbeSettings extends Settings
{
    public function read(string $key, int $idShop): ?string
    {
        return $this->get($key, $idShop);
    }

    public function write(string $key, string $value, int $idShop): bool
    {
        return $this->set($key, $value, $idShop);
    }

    public function removeOwn(string $key, int $idShop): void
    {
        $this->removeShopValue($key, $idShop);
    }

    /**
     * @return array<mixed>
     */
    public function readJson(string $key, ?int $idShop = null): array
    {
        return $this->getJson($key, $idShop);
    }

    /**
     * @param array<mixed> $value
     */
    public function writeJson(string $key, array $value, ?int $idShop = null): bool
    {
        return $this->setJson($key, $value, $idShop);
    }

    public function stored(string $key, ?int $idShop = null): ?string
    {
        return $this->storedValue($key, $idShop);
    }
}
