<?php
declare(strict_types=1);

namespace Tests\Integration\Smaily;

use PrestaShop\Module\SmailyForPrestaShop\Runtime\Services;
use PrestaShop\Module\SmailyForPrestaShop\Smaily\Account;
use PrestaShop\Module\SmailyForPrestaShop\Smaily\AccountStore;
use PrestaShop\Module\SmailyForPrestaShop\Smaily\Crypto;
use Tests\Integration\IntegrationTestCase;

/**
 * A Smaily account is stored per shop and language with its password encrypted by the shop's own
 * key. A password that does not decrypt, as after a move to a server with another key, reads as
 * no credentials.
 */
final class AccountStoreTest extends IntegrationTestCase
{
    private const SHOP = 990;
    private const PASSWORD = 'not-a-real-password';

    private AccountStore $store;

    protected function setUp(): void
    {
        parent::setUp();
        $this->clean();
        $this->store = Services::instance()->get(AccountStore::class);
    }

    protected function tearDown(): void
    {
        if (defined('_PS_ROOT_DIR_')) {
            $this->clean();
        }
        parent::tearDown();
    }

    public function testAShopWithoutAnAccountReadsNull(): void
    {
        self::assertNull($this->store->find(self::SHOP));
    }

    public function testAnAccountRoundTripsWithItsPasswordEncrypted(): void
    {
        self::assertTrue($this->store->save(new Account(self::SHOP, '', 'demo', 'api-user', self::PASSWORD)));

        $stored = (string) self::db()->getValue(
            'SELECT `password_enc` FROM `' . _DB_PREFIX_ . 'smaily_connect_account` WHERE `id_shop` = ' . self::SHOP
        );
        self::assertNotSame('', $stored);
        self::assertStringNotContainsString(self::PASSWORD, $stored);

        $account = $this->store->find(self::SHOP);
        self::assertNotNull($account);
        self::assertSame(
            [self::SHOP, '', 'demo', 'api-user', self::PASSWORD, true],
            [$account->idShop(), $account->language(), $account->subdomain(), $account->username(), $account->password(), $account->hasCredentials()]
        );
    }

    public function testSavingAgainReplacesTheAccountOfThatShopAndLanguage(): void
    {
        $this->store->save(new Account(self::SHOP, '', 'demo', 'api-user', self::PASSWORD));
        $this->store->save(new Account(self::SHOP, 'et', 'demo-et', 'api-user-et', self::PASSWORD));
        self::assertTrue($this->store->save(new Account(self::SHOP, '', 'other', 'api-user-2', 'another-fake-password')));

        self::assertSame(2, (int) self::db()->getValue(
            'SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'smaily_connect_account` WHERE `id_shop` = ' . self::SHOP
        ));
        self::assertSame('another-fake-password', $this->store->find(self::SHOP)->password());
        self::assertSame('demo-et', $this->store->find(self::SHOP, 'et')->subdomain());
    }

    /**
     * @return array<string, array{callable(): string}>
     */
    public static function undecryptable(): array
    {
        return [
            'encrypted with another key' => [
                fn (): string => (new Crypto(\PhpEncryption::createNewRandomKey()))->encrypt(self::PASSWORD),
            ],
            'not a ciphertext' => [fn (): string => 'plain text'],
        ];
    }

    /**
     * @dataProvider undecryptable
     *
     * @param callable(): string $ciphertext
     */
    public function testAPasswordThatDoesNotDecryptReadsAsNoCredentials(callable $ciphertext): void
    {
        $this->store->save(new Account(self::SHOP, '', 'demo', 'api-user', self::PASSWORD));
        self::db()->update(
            'smaily_connect_account',
            ['password_enc' => pSQL($ciphertext())],
            '`id_shop` = ' . self::SHOP
        );

        $account = $this->store->find(self::SHOP);

        self::assertNotNull($account);
        self::assertSame('demo', $account->subdomain());
        self::assertSame('', $account->password());
        self::assertFalse($account->hasCredentials());
    }

    private function clean(): void
    {
        self::db()->execute('DELETE FROM `' . _DB_PREFIX_ . 'smaily_connect_account` WHERE `id_shop` = ' . self::SHOP);
    }
}
