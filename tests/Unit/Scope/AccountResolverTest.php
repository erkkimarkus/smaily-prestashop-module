<?php
declare(strict_types=1);

namespace Tests\Unit\Scope;

use PHPUnit\Framework\TestCase;
use PrestaShop\Module\SmailyForPrestaShop\Scope\AccountResolver;
use PrestaShop\Module\SmailyForPrestaShop\Scope\StoreLayout;
use PrestaShop\Module\SmailyForPrestaShop\Smaily\Account;

require_once __DIR__ . '/FakeStoreLayout.php';

/**
 * The account of a contact: in mode `a` (one Smaily account per language) the account of the
 * contact's language, else the fallback account; in the other modes the one account of the shop.
 * A shop reads its own rows first; in one store it then reads the global rows (id_shop 0), and in
 * separate stores never.
 */
final class AccountResolverTest extends TestCase
{
    /**
     * Each layout with a shop in mode `a`: its settings and that shop's id. The shop holds the
     * accounts of 'en' and 'et' and the fallback language 'en'.
     *
     * @return array<string, array{array<int, array<string, string>>, int}>
     */
    public function modeAShops(): array
    {
        $modeA = [StoreLayout::LANGUAGE_MODE => 'a', StoreLayout::FALLBACK_LANGUAGE => 'en'];

        return [
            'one shop (the global scope)' => [[0 => [StoreLayout::SEPARATE_STORES => '0'] + $modeA], 1],
            'one store with several shops (the shop own setting)' => [[0 => [StoreLayout::SEPARATE_STORES => '0', StoreLayout::LANGUAGE_MODE => 'single'], 2 => $modeA], 2],
            'separate stores' => [[0 => [StoreLayout::SEPARATE_STORES => '1'], 2 => $modeA], 2],
        ];
    }

    /**
     * @dataProvider modeAShops
     *
     * @param array<int, array<string, string>> $settings
     */
    public function testModeAResolvesALanguageWithItsOwnAccountToThatAccount(array $settings, int $idShop): void
    {
        $owner = $idShop === 1 ? 0 : $idShop;
        $resolver = $this->resolver($settings, [[$owner, 'en'], [$owner, 'et']]);

        self::assertSame([$owner, 'et'], $this->key($resolver->for($idShop, 'et')));
        self::assertSame([$owner, 'en'], $this->key($resolver->for($idShop, 'en')));
    }

    /**
     * @dataProvider modeAShops
     *
     * @param array<int, array<string, string>> $settings
     */
    public function testModeAResolvesALanguageWithoutAnAccountToTheFallbackAccount(array $settings, int $idShop): void
    {
        $owner = $idShop === 1 ? 0 : $idShop;
        $resolver = $this->resolver($settings, [[$owner, 'en'], [$owner, 'et'], [$owner, '']]);

        self::assertSame([$owner, 'en'], $this->key($resolver->for($idShop, 'fi')), 'a language without an account');
        self::assertSame([$owner, 'en'], $this->key($resolver->for($idShop, '')), 'no language');
    }

    /**
     * @dataProvider modeAShops
     *
     * @param array<int, array<string, string>> $settings
     */
    public function testModeAWithoutAFallbackLanguageResolvesToTheAccountOfNoLanguage(array $settings, int $idShop): void
    {
        $owner = $idShop === 1 ? 0 : $idShop;
        $settings[$owner === 0 ? 0 : $idShop][StoreLayout::FALLBACK_LANGUAGE] = '';
        $resolver = $this->resolver($settings, [[$owner, 'et'], [$owner, '']]);

        self::assertSame([$owner, ''], $this->key($resolver->for($idShop, 'fi')));
    }

    /**
     * @return array<string, array{string}>
     */
    public function oneAccountModes(): array
    {
        return ['single' => ['single'], 'b: one workflow per language' => ['b'], 'c: one workflow that branches' => ['c'], 'not set' => ['']];
    }

    /**
     * Rows of a language left from an earlier mode `a` are not read.
     *
     * @dataProvider oneAccountModes
     */
    public function testTheOtherModesResolveEveryLanguageToTheOneAccount(string $mode): void
    {
        $settings = [0 => [StoreLayout::SEPARATE_STORES => '0']];
        if ($mode !== '') {
            $settings[0][StoreLayout::LANGUAGE_MODE] = $mode;
        }
        $resolver = $this->resolver($settings, [[0, ''], [0, 'et']]);

        self::assertSame([0, ''], $this->key($resolver->for(1, 'et')));
        self::assertSame([0, ''], $this->key($resolver->for(1, '')));
    }

    public function testInOneStoreAShopWithoutAnAccountOfItsOwnUsesTheGlobalOne(): void
    {
        $resolver = $this->resolver([0 => [StoreLayout::SEPARATE_STORES => '0']], [[0, ''], [2, '']]);

        self::assertSame([0, ''], $this->key($resolver->for(1, 'et')));
        self::assertSame([2, ''], $this->key($resolver->for(2, 'et')));
    }

    public function testInSeparateStoresAShopUsesOnlyItsOwnAccount(): void
    {
        $resolver = $this->resolver([0 => [StoreLayout::SEPARATE_STORES => '1']], [[0, ''], [1, '']]);

        self::assertSame([1, ''], $this->key($resolver->for(1, 'et')));
        self::assertNull($resolver->for(2, 'et'));
    }

    public function testAnAccountWhosePasswordDoesNotDecryptIsStillTheContactsAccount(): void
    {
        $resolver = new AccountResolver(
            new FakeStoreLayout([0 => [StoreLayout::SEPARATE_STORES => '0']]),
            static fn (int $idShop, string $language): ?Account => $idShop === 1 && $language === ''
                ? new Account(1, '', 'shop1', 'api-user', '')
                : new Account($idShop, $language, 'global', 'api-user', 'not-a-real-password')
        );

        $account = $resolver->for(1, 'et');

        self::assertNotNull($account);
        self::assertSame('shop1', $account->subdomain(), 'the shop is not sent through another account');
        self::assertFalse($account->hasCredentials());
    }

    /**
     * @param array<int, array<string, string>> $settings
     * @param list<array{int, string}> $accounts
     */
    private function resolver(array $settings, array $accounts): AccountResolver
    {
        $rows = [];
        foreach ($accounts as [$idShop, $language]) {
            $rows[$idShop . '|' . $language] = new Account($idShop, $language, 'shop' . $idShop . $language, 'api-user', 'not-a-real-password');
        }

        return new AccountResolver(
            new FakeStoreLayout($settings),
            static fn (int $idShop, string $language): ?Account => $rows[$idShop . '|' . $language] ?? null
        );
    }

    /**
     * @return array{int, string}|null
     */
    private function key(?Account $account): ?array
    {
        return $account === null ? null : [$account->idShop(), $account->language()];
    }
}
