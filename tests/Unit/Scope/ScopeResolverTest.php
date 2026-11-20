<?php
declare(strict_types=1);

namespace Tests\Unit\Scope;

use PHPUnit\Framework\TestCase;
use PrestaShop\Module\SmailyForPrestaShop\Scope\AccountResolver;
use PrestaShop\Module\SmailyForPrestaShop\Scope\ScopeResolver;
use PrestaShop\Module\SmailyForPrestaShop\Scope\StoreLayout;
use PrestaShop\Module\SmailyForPrestaShop\Smaily\Account;

require_once __DIR__ . '/FakeStoreLayout.php';

/**
 * The rules of ARCHITECTURE 5.2 for the contact's shop, language and account, in each shop layout:
 * one shop, one store with several shops, and separate stores. The contact's shop and language come
 * from the record of the event (customer, newsletter subscription, order, cart).
 */
final class ScopeResolverTest extends TestCase
{
    /** PrestaShop language ids and their ISO codes in these tests. */
    private const LANGUAGES = [1 => 'en', 2 => 'et', 3 => 'fi'];

    /**
     * Each layout: its settings (id_shop => key => value) and its account rows (id_shop, language).
     *
     * @return array<string, array{settings: array<int, array<string, string>>, accounts: list<array{int, string}>}>
     */
    private static function layouts(): array
    {
        return [
            'one shop' => [
                'settings' => [0 => [StoreLayout::SEPARATE_STORES => '0', StoreLayout::LANGUAGE_MODE => 'single']],
                'accounts' => [[0, '']],
            ],
            'one store with several shops' => [
                'settings' => [0 => [StoreLayout::SEPARATE_STORES => '0', StoreLayout::LANGUAGE_MODE => 'single']],
                'accounts' => [[0, ''], [2, '']],
            ],
            'separate stores' => [
                'settings' => [
                    0 => [StoreLayout::SEPARATE_STORES => '1'],
                    1 => [StoreLayout::LANGUAGE_MODE => 'single'],
                    2 => [StoreLayout::LANGUAGE_MODE => 'single'],
                ],
                'accounts' => [[1, ''], [2, '']],
            ],
        ];
    }

    /**
     * The records of the events, each with the shop and the language it names, and what each
     * layout resolves for it: the shop whose setup serves it and its account (id_shop, language).
     *
     * @return array<string, array{string, array<string, mixed>|object, int, string, int, array{int, string}}>
     */
    public function events(): array
    {
        $records = [
            'customer of shop 1' => (object) ['id' => 7, 'id_shop' => 1, 'id_shop_group' => 1, 'id_lang' => 2, 'newsletter' => 1],
            'newsletter subscription row of shop 1' => ['id' => '9', 'id_shop' => '1', 'id_shop_group' => '1', 'id_lang' => '3', 'active' => '1'],
            'order of shop 2' => (object) ['id' => 11, 'id_shop' => 2, 'id_shop_group' => 1, 'id_lang' => 1, 'id_customer' => 7],
            'cart of shop 2' => (object) ['id' => 13, 'id_shop' => 2, 'id_shop_group' => 1, 'id_lang' => 2, 'id_customer' => 7],
        ];
        $expected = [
            'one shop' => [
                'customer of shop 1' => [1, 'et', 0, [0, '']],
                'newsletter subscription row of shop 1' => [1, 'fi', 0, [0, '']],
            ],
            'one store with several shops' => [
                'customer of shop 1' => [1, 'et', 0, [0, '']],
                'newsletter subscription row of shop 1' => [1, 'fi', 0, [0, '']],
                'order of shop 2' => [2, 'en', 0, [2, '']],
                'cart of shop 2' => [2, 'et', 0, [2, '']],
            ],
            'separate stores' => [
                'customer of shop 1' => [1, 'et', 1, [1, '']],
                'newsletter subscription row of shop 1' => [1, 'fi', 1, [1, '']],
                'order of shop 2' => [2, 'en', 2, [2, '']],
                'cart of shop 2' => [2, 'et', 2, [2, '']],
            ],
        ];

        $cases = [];
        foreach ($expected as $layout => $events) {
            foreach ($events as $event => [$idShop, $language, $setupShop, $account]) {
                $cases[$layout . ': ' . $event] = [$layout, $records[$event], $idShop, $language, $setupShop, $account];
            }
        }

        return $cases;
    }

    /**
     * @dataProvider events
     *
     * @param array<string, mixed>|object $record
     * @param array{int, string} $account
     */
    public function testTheContactsShopIsTheShopOfTheRecord(string $layout, $record, int $idShop, string $language, int $setupShop, array $account): void
    {
        self::assertSame($idShop, $this->resolver($layout)->forRecord($record)->idShop());
    }

    /**
     * @dataProvider events
     *
     * @param array<string, mixed>|object $record
     * @param array{int, string} $account
     */
    public function testTheContactsLanguageIsTheIsoCodeOfTheLanguageOfTheRecord(string $layout, $record, int $idShop, string $language, int $setupShop, array $account): void
    {
        self::assertSame($language, $this->resolver($layout)->forRecord($record)->language());
    }

    /**
     * @dataProvider events
     *
     * @param array<string, mixed>|object $record
     * @param array{int, string} $account
     */
    public function testTheContactsAccountIsTheShopsOwnElseTheGlobalOneInOneStore(string $layout, $record, int $idShop, string $language, int $setupShop, array $account): void
    {
        $resolved = $this->resolver($layout)->forRecord($record)->account();

        self::assertNotNull($resolved);
        self::assertSame($account, [$resolved->idShop(), $resolved->language()]);
    }

    /**
     * @dataProvider events
     *
     * @param array<string, mixed>|object $record
     * @param array{int, string} $account
     */
    public function testTheSetupThatServesTheContactIsTheShopsOwnOnlyInSeparateStores(string $layout, $record, int $idShop, string $language, int $setupShop, array $account): void
    {
        self::assertSame($setupShop, $this->resolver($layout)->forRecord($record)->setupShopId());
    }

    /**
     * @return array<string, array{string}>
     */
    public function layoutNames(): array
    {
        return array_combine(array_keys(self::layouts()), array_map(static fn (string $name): array => [$name], array_keys(self::layouts())));
    }

    /**
     * The storefront request names its shop and language itself.
     *
     * @dataProvider layoutNames
     */
    public function testResolvesTheShopAndTheLanguageThatTheCallerNames(string $layout): void
    {
        $scope = $this->resolver($layout)->resolve(1, 1);

        self::assertSame(1, $scope->idShop());
        self::assertSame('en', $scope->language());
    }

    /**
     * @dataProvider layoutNames
     */
    public function testALanguageWithoutAnIsoCodeIsNoLanguage(string $layout): void
    {
        self::assertSame('', $this->resolver($layout)->resolve(1, 99)->language(), 'an unknown language id');
        self::assertSame('', $this->resolver($layout)->resolve(1, 0)->language(), 'no language id');
        self::assertSame('', $this->resolver($layout)->forRecord(['id_shop' => 1])->language(), 'a record without a language');
    }

    public function testASeparateStoreWithoutAnAccountHasNoneAndNeverAnotherShopsOrAGlobalOne(): void
    {
        $settings = self::layouts()['separate stores']['settings'];
        $settings[3] = [StoreLayout::LANGUAGE_MODE => 'single'];
        $resolver = $this->resolverOver($settings, [[0, ''], [1, ''], [2, '']]);

        self::assertNull($resolver->resolve(3, 1)->account());
        self::assertSame(3, $resolver->resolve(3, 1)->setupShopId());
    }

    private function resolver(string $layout): ScopeResolver
    {
        return $this->resolverOver(self::layouts()[$layout]['settings'], self::layouts()[$layout]['accounts']);
    }

    /**
     * @param array<int, array<string, string>> $settings
     * @param list<array{int, string}> $accounts
     */
    private function resolverOver(array $settings, array $accounts): ScopeResolver
    {
        $rows = [];
        foreach ($accounts as [$idShop, $language]) {
            $rows[$idShop . '|' . $language] = new Account($idShop, $language, 'shop' . $idShop . $language, 'api-user', 'not-a-real-password');
        }
        $layout = new FakeStoreLayout($settings);

        return new ScopeResolver(
            $layout,
            new AccountResolver($layout, static fn (int $idShop, string $language): ?Account => $rows[$idShop . '|' . $language] ?? null),
            static fn (int $idLang) => self::LANGUAGES[$idLang] ?? false
        );
    }
}
