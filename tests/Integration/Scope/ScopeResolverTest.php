<?php
declare(strict_types=1);

namespace Tests\Integration\Scope;

use PrestaShop\Module\SmailyForPrestaShop\Runtime\Services;
use PrestaShop\Module\SmailyForPrestaShop\Scope\ScopeResolver;
use PrestaShop\Module\SmailyForPrestaShop\Scope\StoreLayout;
use PrestaShop\Module\SmailyForPrestaShop\Smaily\Account;
use PrestaShop\Module\SmailyForPrestaShop\Smaily\AccountStore;
use Tests\Integration\IntegrationTestCase;
use Tests\Integration\MultistoreShop;

require_once __DIR__ . '/../MultistoreShop.php';

/**
 * On a multistore shop with two shops, the runtime's scope resolver reads the store layout from the
 * configuration, the languages from PrestaShop and the accounts from the account table.
 */
final class ScopeResolverTest extends IntegrationTestCase
{
    use MultistoreShop;

    private const TABLE = 'smaily_connect_account';

    /** @var list<array<string, mixed>> */
    private array $savedAccounts = [];

    private int $shop;

    private int $otherShop;

    protected function setUp(): void
    {
        parent::setUp();
        $this->savedAccounts = self::db()->executeS('SELECT * FROM `' . _DB_PREFIX_ . self::TABLE . '`', true, false) ?: [];
        self::db()->delete(self::TABLE);
        $this->deleteLayout();
        $this->openMultistore();
        $this->otherShop = $this->defaultShopId();
        $this->shop = $this->addShop((int) \Shop::getGroupFromShop($this->otherShop));
    }

    protected function tearDown(): void
    {
        if (defined('_PS_ROOT_DIR_')) {
            self::db()->delete(self::TABLE);
            foreach ($this->savedAccounts as $row) {
                self::db()->insert(self::TABLE, array_map(static fn ($value) => $value === null ? null : pSQL((string) $value), $row), true);
            }
            $this->deleteLayout();
            $this->closeMultistore();
        }
        parent::tearDown();
    }

    public function testInSeparateStoresEachShopsContactsGoToThatShopsOwnAccount(): void
    {
        \Configuration::updateGlobalValue(StoreLayout::SEPARATE_STORES, '1');
        $this->saveAccount(0, '', 'globalleftover');
        $this->saveAccount($this->otherShop, '', 'othershop');
        $this->saveAccount($this->shop, '', 'ownshop');
        $idLang = (int) \Configuration::get('PS_LANG_DEFAULT');

        $scope = $this->resolver()->forRecord(['id_shop' => $this->shop, 'id_lang' => $idLang]);

        self::assertSame($this->shop, $scope->idShop());
        self::assertSame(\Language::getIsoById($idLang), $scope->language());
        self::assertSame($this->shop, $scope->setupShopId());
        self::assertNotNull($scope->account());
        self::assertSame(['ownshop', true], [$scope->account()->subdomain(), $scope->account()->hasCredentials()]);
        self::assertSame('othershop', $this->resolver()->resolve($this->otherShop, $idLang)->account()->subdomain());

        self::db()->delete(self::TABLE, '`id_shop` = ' . $this->shop);

        self::assertNull($this->resolver()->resolve($this->shop, $idLang)->account(), 'never another shop\'s account or a global one');
    }

    public function testInOneStoreAShopWithoutAnAccountOfItsOwnUsesTheGlobalOne(): void
    {
        \Configuration::updateGlobalValue(StoreLayout::SEPARATE_STORES, '0');
        $this->saveAccount(0, '', 'global');
        $this->saveAccount($this->otherShop, '', 'othershop');

        $scope = $this->resolver()->resolve($this->shop, (int) \Configuration::get('PS_LANG_DEFAULT'));

        self::assertSame(0, $scope->setupShopId());
        self::assertSame('global', $scope->account()->subdomain());
    }

    public function testModeAOfAShopSendsALanguageWithoutAnAccountToTheFallbackAccount(): void
    {
        \Configuration::updateGlobalValue(StoreLayout::SEPARATE_STORES, '0');
        $layout = new StoreLayoutWriter();
        $layout->write(StoreLayout::LANGUAGE_MODE, 'a', $this->shop);
        $layout->write(StoreLayout::FALLBACK_LANGUAGE, 'zz', $this->shop);
        $this->saveAccount(0, '', 'global');
        $this->saveAccount($this->shop, 'zz', 'fallback');

        $scope = $this->resolver()->resolve($this->shop, (int) \Configuration::get('PS_LANG_DEFAULT'));

        self::assertSame('fallback', $scope->account()->subdomain());
        self::assertSame('global', $this->resolver()->resolve($this->otherShop, (int) \Configuration::get('PS_LANG_DEFAULT'))->account()->subdomain());
    }

    public function testCustomerAccountsAreGlobalOnlyWhileEveryShopIsInOneGroupThatSharesCustomers(): void
    {
        $layout = new StoreLayout();
        $group = (int) \Shop::getGroupFromShop($this->otherShop);

        $this->setShareCustomer($group, false);
        self::assertSame('website', $layout->customerAccountScope());

        $this->setShareCustomer($group, true);
        self::assertSame('global', $layout->customerAccountScope());

        $this->addShop($this->addShopGroup(true));
        self::assertSame('website', $layout->customerAccountScope(), 'a shop in a second group');
    }

    private function resolver(): ScopeResolver
    {
        return Services::fromProviders(Services::PROVIDERS)->get(ScopeResolver::class);
    }

    private function saveAccount(int $idShop, string $language, string $subdomain): void
    {
        $store = Services::fromProviders(Services::PROVIDERS)->get(AccountStore::class);
        self::assertTrue($store->save(new Account($idShop, $language, $subdomain, 'api-user', 'not-a-real-password')));
    }

    private function deleteLayout(): void
    {
        foreach ([StoreLayout::SEPARATE_STORES, StoreLayout::LANGUAGE_MODE, StoreLayout::FALLBACK_LANGUAGE] as $key) {
            \Configuration::deleteByName($key);
        }
    }
}

final class StoreLayoutWriter extends StoreLayout
{
    public function write(string $key, string $value, int $idShop): void
    {
        $this->set($key, $value, $idShop);
    }
}
