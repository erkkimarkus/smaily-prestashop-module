<?php
declare(strict_types=1);

namespace Tests\Integration\Engine;

use PrestaShop\Module\SmailyForPrestaShop\Engine\Connections;
use PrestaShop\Module\SmailyForPrestaShop\Scope\StoreLayout;
use PrestaShop\Module\SmailyForPrestaShop\Smaily\Crypto;

require_once __DIR__ . '/EngineTestCase.php';

/**
 * A connection is stored per separately set-up shop, with its API key encrypted by the shop's own
 * key and its settings exactly as they were given. A write or a removal touches a row only while
 * it still holds the connection that was read.
 */
final class ConnectionsTest extends EngineTestCase
{
    private const KEY = 'sk_not_a_real_key_0123456789abcdef';

    private const SHOP = 991;

    private const OTHER_SHOP = 992;

    /** A prefix no parser would read: tags, quotes, a backslash, a format mark, a back-tick. */
    private const ODD_PREFIX = "<c9>-'\"\\ %s\u{00e4}`;--\n";

    public function testAShopWithoutAConnectionReadsNull(): void
    {
        self::assertNull($this->connections()->find(0));
        self::assertNull($this->connections()->forShop(self::SHOP));
        self::assertSame([], $this->connections()->all());
    }

    public function testAConnectionRoundTripsWithItsKeyEncrypted(): void
    {
        $settings = self::settings('c2-');

        $saved = $this->connections()->save(0, 'demo', '550e8400-e29b-41d4-a716-446655440000', self::KEY, $settings);

        $row = self::row(0);
        self::assertNotSame('', $row['api_key_enc']);
        self::assertStringNotContainsString(self::KEY, implode('|', $row), 'the key is nowhere in the row as plain text');
        self::assertSame(self::KEY, (new Crypto(_NEW_COOKIE_KEY_))->decrypt($row['api_key_enc']));
        self::assertSame(['demo', '550e8400-e29b-41d4-a716-446655440000'], [$row['smaily_subdomain'], $row['tenant_id']]);

        $read = $this->connections()->find(0);
        self::assertNotNull($read);
        self::assertSame(
            [$saved->id(), 0, 'demo', '550e8400-e29b-41d4-a716-446655440000', self::KEY, $settings, 'c2-'],
            [$read->id(), $read->idShop(), $read->smailySubdomain(), $read->tenantId(), $read->apiKey(), $read->settings(), $read->prefix()]
        );
    }

    /**
     * @dataProvider prefixes
     */
    public function testThePrefixIsStoredByteForByte(string $prefix): void
    {
        $this->connections()->save(0, 'demo', 'tenant', self::KEY, self::settings($prefix));

        $read = $this->connections()->find(0);

        self::assertNotNull($read);
        self::assertSame($prefix, $read->prefix());
        self::assertSame(bin2hex($prefix), bin2hex($read->prefix()));
        self::assertSame($prefix, json_decode(self::row(0)['settings'], true)['config']['connection']['prefix']);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function prefixes(): iterable
    {
        yield 'none' => [''];
        yield 'as the contract shows it' => ['c2-'];
        yield 'characters a parser trips on' => [self::ODD_PREFIX];
        yield 'markup and an emoji' => ["<b>c2-</b>&amp;\u{1F600}\0"];
    }

    public function testAKeyThatDoesNotDecryptReadsAsNoKey(): void
    {
        $this->connections()->save(0, 'demo', 'tenant', self::KEY, self::settings('c2-'));
        self::db()->update(self::CONNECTION_TABLE, ['api_key_enc' => 'not-a-ciphertext'], '`id_shop` = 0');

        $read = $this->connections()->find(0);

        self::assertNotNull($read);
        self::assertSame('', $read->apiKey());
        self::assertSame('c2-', $read->prefix(), 'the rest of the connection still reads');
    }

    public function testOneStoreSharesTheInstallationWideConnection(): void
    {
        \Configuration::updateGlobalValue(StoreLayout::SEPARATE_STORES, '0');
        $connections = $this->runtime()->get(Connections::class);
        $shared = $connections->save(0, 'demo', 'tenant', self::KEY, self::settings('c2-'));
        $connections->save(self::OTHER_SHOP, 'left', 'left-over', self::KEY, self::settings(''));

        self::assertSame([0, 0], [$connections->keyOf(self::SHOP), $connections->keyOf(self::OTHER_SHOP)]);
        self::assertSame($shared->id(), $connections->forShop(self::SHOP)->id());
        self::assertSame($shared->id(), $connections->forShop(self::OTHER_SHOP)->id(), 'a row of its own from an earlier layout is not read');
        self::assertCount(2, $connections->all(), 'every stored connection is listed: each holds a live key');
    }

    public function testSeparateStoresEachHaveTheirOwnConnection(): void
    {
        \Configuration::updateGlobalValue(StoreLayout::SEPARATE_STORES, '1');
        $connections = $this->runtime()->get(Connections::class);
        $first = $connections->save(self::SHOP, 'demo', 'tenant-1', self::KEY, self::settings('c2-'));
        $second = $connections->save(self::OTHER_SHOP, 'demo', 'tenant-1', self::KEY . '2', self::settings('c3-'));

        self::assertSame([self::SHOP, self::OTHER_SHOP], [$connections->keyOf(self::SHOP), $connections->keyOf(self::OTHER_SHOP)]);
        self::assertSame([$first->id(), 'c2-'], [$connections->forShop(self::SHOP)->id(), $connections->forShop(self::SHOP)->prefix()]);
        self::assertSame([$second->id(), 'c3-'], [$connections->forShop(self::OTHER_SHOP)->id(), $connections->forShop(self::OTHER_SHOP)->prefix()]);
        self::assertNull($connections->forShop(993), 'a shop that never connected has none');
        self::assertNotSame($first->id(), $second->id());
    }

    public function testANewConnectionOfAShopKeepsTheRowAndReplacesEverythingInIt(): void
    {
        $old = $this->connections()->save(0, 'demo', 'tenant-1', self::KEY, self::settings('c2-') + ['refused_at' => '2026-11-21T10:00:00Z']);

        $new = $this->connections()->save(0, 'other', 'tenant-2', self::KEY . '2', self::settings('c3-'));

        self::assertSame(1, self::connectionRows());
        self::assertSame($old->id(), $new->id());
        self::assertSame(['other', 'tenant-2', self::KEY . '2', 'c3-', ''], [$new->smailySubdomain(), $new->tenantId(), $new->apiKey(), $new->prefix(), $new->refusedAt()]);
    }

    public function testASettingsWriteAndARemovalTouchOnlyTheConnectionThatWasRead(): void
    {
        $old = $this->connections()->save(0, 'demo', 'tenant-1', self::KEY, self::settings('c2-'));
        $this->connections()->saveSettings($old, self::settings('c5-'));
        self::assertSame('c5-', $this->connections()->find(0)->prefix());

        // A reconnect puts another connection into the row while $old is still in a caller's hands.
        $this->connections()->save(0, 'other', 'tenant-2', self::KEY . '2', self::settings('c3-'));
        $this->connections()->saveSettings($old, self::settings('c9-'));
        $this->connections()->delete($old);

        $current = $this->connections()->find(0);
        self::assertNotNull($current, 'the removal of the old connection left the new one');
        self::assertSame(['tenant-2', 'c3-'], [$current->tenantId(), $current->prefix()]);

        $this->connections()->delete($current);
        self::assertSame(0, self::connectionRows());
    }

    /**
     * @return array<string, mixed>
     */
    private static function settings(string $prefix): array
    {
        return [
            'tenant_name' => 'Mock Store',
            'engine_base_url' => 'https://intelligence.smaily.com',
            'endpoints' => ['customer_export' => 'https://intelligence.smaily.com/api/v1/customer/{email}/export'],
            'config' => ['batch_size_max' => 100, 'connection' => ['prefix' => $prefix]],
        ];
    }
}
