<?php
declare(strict_types=1);

namespace Tests\Unit\Automation;

use PHPUnit\Framework\TestCase;
use PrestaShop\Module\SmailyForPrestaShop\Automation\Router;
use PrestaShop\Module\SmailyForPrestaShop\Automation\Trigger;
use PrestaShop\Module\SmailyForPrestaShop\Automation\WorkflowMatch;
use PrestaShop\Module\SmailyForPrestaShop\Config\AutomationSettings;
use PrestaShop\Module\SmailyForPrestaShop\Queue\Skipped;
use PrestaShop\Module\SmailyForPrestaShop\Scope\StoreLayout;
use PrestaShop\Module\SmailyForPrestaShop\Smaily\Account;
use Tests\Unit\Scope\FakeStoreLayout;

require_once __DIR__ . '/FakeAutomationSettings.php';
require_once __DIR__ . '/FakeMappingRepository.php';
require_once __DIR__ . '/../Scope/FakeStoreLayout.php';

/**
 * (trigger, shop, language) → the Smaily account and the workflow (ARCHITECTURE 5.2, the workflow
 * row), for each language mode in each shop layout, and the skips with their reason codes.
 *
 * In the tables, the setup a shop reads is "scope" 0 (shared) or the shop itself (separate stores).
 * The workflow of a scope's row is 100 × (scope + 1) plus 1 for the row of no language, 2 for `et`
 * and 3 for `en`; the account of a scope is "acct<scope>" plus its language. Separate stores also
 * hold leftover shared rows and a shared account ("leftover", workflow 999), which no shop reads.
 */
final class RouterTest extends TestCase
{
    private const LAYOUTS = [
        'one shop' => ['separate' => false, 'shops' => [1]],
        'shops sharing one setup' => ['separate' => false, 'shops' => [1, 2]],
        'separate stores' => ['separate' => true, 'shops' => [1, 2]],
    ];

    /**
     * Per mode and contact language: the row (1 none, 2 et, 3 en) and the account's language.
     */
    private const EXPECTED = [
        StoreLayout::MODE_SINGLE => ['et' => [1, ''], 'fi' => [1, ''], '' => [1, '']],
        StoreLayout::MODE_BRANCH_ON_LANGUAGE => ['et' => [1, ''], 'fi' => [1, ''], '' => [1, '']],
        StoreLayout::MODE_WORKFLOW_PER_LANGUAGE => ['et' => [2, ''], 'fi' => [1, ''], '' => [1, '']],
        StoreLayout::MODE_ACCOUNT_PER_LANGUAGE => ['et' => [2, 'et'], 'fi' => [3, 'en'], '' => [3, 'en']],
    ];

    /**
     * @return array<string, array{string, string, int, string}>
     */
    public function modesLayoutsAndLanguages(): array
    {
        $cases = [];
        foreach (self::EXPECTED as $mode => $languages) {
            foreach (self::LAYOUTS as $layout => $setup) {
                foreach ($setup['shops'] as $idShop) {
                    foreach (array_keys($languages) as $language) {
                        $cases[sprintf('mode %s, %s, shop %d, language "%s"', $mode, $layout, $idShop, $language)] = [$mode, $layout, $idShop, (string) $language];
                    }
                }
            }
        }

        return $cases;
    }

    /**
     * Acceptance A: each mode in each layout resolves the expected account and workflow, and a shop
     * of separate stores never another shop's or the shared ones.
     *
     * @dataProvider modesLayoutsAndLanguages
     */
    public function testResolvesTheAccountAndTheWorkflowOfTheModeAndTheLayout(string $mode, string $layout, int $idShop, string $language): void
    {
        $separate = self::LAYOUTS[$layout]['separate'];
        $scope = $separate ? $idShop : 0;
        [$row, $accountLanguage] = self::EXPECTED[$mode][$language];

        $match = $this->worldRouter($mode, $layout)->resolve(Trigger::WELCOME, $idShop, $language);

        self::assertInstanceOf(WorkflowMatch::class, $match);
        self::assertSame(100 * ($scope + 1) + $row, $match->workflowId());
        self::assertSame('acct' . $scope . $accountLanguage, $match->account()->subdomain());
        self::assertSame($accountLanguage === '' ? 'default' : $accountLanguage, $match->accountKey());
        self::assertSame([$idShop, $language], [$match->idShop(), $match->language()]);
        self::assertSame($mode === StoreLayout::MODE_BRANCH_ON_LANGUAGE, $match->branchesOnLanguage());
        self::assertFalse($match->isOptInTrigger());
    }

    public function testAShopOfSeparateStoresWithoutItsOwnRowOrAccountNeverUsesTheSharedOrAnotherShops(): void
    {
        $layout = [0 => [StoreLayout::SEPARATE_STORES => '1']];
        $accounts = [0 => ['' => 'leftover'], 1 => ['' => 'acct1']];
        $rows = [[0, Trigger::WELCOME, '', 'default', 999], [1, Trigger::WELCOME, '', 'default', 201]];

        self::assertEquals(new Skipped(Router::SKIPPED_NO_WORKFLOW), $this->router($rows, $accounts, $layout)->route(Trigger::WELCOME, 2, ''));

        $rows[] = [2, Trigger::WELCOME, '', 'default', 301];
        self::assertEquals(new Skipped(Router::SKIPPED_NO_ACCOUNT), $this->router($rows, $accounts, $layout)->route(Trigger::WELCOME, 2, ''));
        self::assertNull($this->router($rows, $accounts, $layout)->accountFor(2, 'default'));
    }

    public function testASkipNamesItsReasonAndResolveAnswersNull(): void
    {
        $router = $this->router([[0, Trigger::WELCOME, '', 'default', 101]], [0 => ['' => 'shared']], [], [0 => [AutomationSettings::FIRST_ORDER_ENABLED => '1']]);

        self::assertEquals(new Skipped(Router::SKIPPED_AUTOMATION_OFF), $router->route(Trigger::WELCOME, 1, 'et'));
        self::assertEquals(new Skipped(Router::SKIPPED_NO_WORKFLOW), $router->route(Trigger::FIRST_ORDER, 1, 'et'));
        self::assertEquals(new Skipped(Router::SKIPPED_UNKNOWN_TRIGGER), $router->route('order_confirmation', 1, 'et'));
        self::assertNull($router->resolve(Trigger::WELCOME, 1, 'et'));
    }

    public function testARowWhoseAccountIsMissingOrUnusableIsSkippedNeverSentThroughAnotherAccount(): void
    {
        $layout = [0 => [StoreLayout::LANGUAGE_MODE => StoreLayout::MODE_ACCOUNT_PER_LANGUAGE, StoreLayout::FALLBACK_LANGUAGE => 'en']];
        $rows = [[0, Trigger::WELCOME, 'et', 'et', 102], [0, Trigger::WELCOME, 'en', 'en', 103]];

        $router = $this->router($rows, [0 => ['' => 'shared', 'en' => 'sharedEn']], $layout);
        self::assertEquals(new Skipped(Router::SKIPPED_NO_ACCOUNT), $router->route(Trigger::WELCOME, 1, 'et'));

        $router = $this->router($rows, [0 => ['' => 'shared', 'et' => 'sharedEt']], $layout, null, ['sharedEt']);
        self::assertEquals(new Skipped(Router::SKIPPED_NO_CREDENTIALS), $router->route(Trigger::WELCOME, 1, 'et'));
    }

    /**
     * Acceptance 1 of Story 4.1, "a shop on another account" (Magento CON-190): in one store, a
     * shop whose own account is another Smaily account than the shared one reads only its own rows.
     */
    public function testAShopOnAnotherAccountReadsOnlyItsOwnRows(): void
    {
        $accounts = [0 => ['' => 'shared'], 2 => ['' => 'other']];
        $shared = [0, Trigger::WELCOME, '', 'default', 101];

        $router = $this->router([$shared], $accounts);
        self::assertEquals(new Skipped(Router::SKIPPED_NO_WORKFLOW), $router->route(Trigger::WELCOME, 2, ''));
        self::assertSame(101, $router->resolve(Trigger::WELCOME, 1, '')->workflowId());

        $match = $this->router([$shared, [2, Trigger::WELCOME, '', 'default', 301]], $accounts)->resolve(Trigger::WELCOME, 2, '');
        self::assertSame([301, 'other'], [$match->workflowId(), $match->account()->subdomain()]);
    }

    public function testAShopWhoseOwnAccountIsTheSharedOneReadsTheSharedRowsThroughItsOwnRow(): void
    {
        $router = $this->router([[0, Trigger::WELCOME, '', 'default', 101]], [0 => ['' => 'shared'], 2 => ['' => 'Shared ']]);

        $match = $router->resolve(Trigger::WELCOME, 2, '');

        self::assertSame(101, $match->workflowId());
        self::assertSame(2, $match->account()->idShop(), 'the shop\'s own account row comes first');
    }

    public function testAShopsOwnRowWinsOverTheSharedOne(): void
    {
        $router = $this->router([[0, Trigger::WELCOME, '', 'default', 101], [2, Trigger::WELCOME, '', 'default', 301]], [0 => ['' => 'shared']]);

        self::assertSame(301, $router->resolve(Trigger::WELCOME, 2, '')->workflowId());
        self::assertSame(101, $router->resolve(Trigger::WELCOME, 1, '')->workflowId());
    }

    public function testInModeBTheDefaultFallbackRowComesBeforeTheRowOfNoLanguage(): void
    {
        $layout = [0 => [StoreLayout::LANGUAGE_MODE => StoreLayout::MODE_WORKFLOW_PER_LANGUAGE]];
        $router = $this->router([
            [0, Trigger::WELCOME, '', 'default', 101],
            [0, Trigger::WELCOME, 'en', 'default', 103, false, true],
        ], [0 => ['' => 'shared']], $layout);

        self::assertSame(103, $router->resolve(Trigger::WELCOME, 1, 'fi')->workflowId());
    }

    /**
     * ARCHITECTURE 5.2, "the shop's own mapping rows first, then the `id_shop = 0` rows", at each
     * step of a per-language mode: the step decides before the scope does.
     */
    public function testInModeBAShopsOwnRowWinsOnlyAtTheSameStep(): void
    {
        $layout = [0 => [StoreLayout::LANGUAGE_MODE => StoreLayout::MODE_WORKFLOW_PER_LANGUAGE]];
        $accounts = [0 => ['' => 'shared']];
        $shared = [
            [0, Trigger::WELCOME, 'et', 'default', 102],
            [0, Trigger::WELCOME, 'en', 'default', 103, false, true],
            [0, Trigger::WELCOME, '', 'default', 101],
        ];

        $router = $this->router(array_merge($shared, [[2, Trigger::WELCOME, 'et', 'default', 302]]), $accounts, $layout);
        self::assertSame(302, $router->resolve(Trigger::WELCOME, 2, 'et')->workflowId(), 'its own row of the language before the shared one');
        self::assertSame(102, $router->resolve(Trigger::WELCOME, 1, 'et')->workflowId());

        $router = $this->router(array_merge($shared, [[2, Trigger::WELCOME, 'lv', 'default', 304, false, true]]), $accounts, $layout);
        self::assertSame(102, $router->resolve(Trigger::WELCOME, 2, 'et')->workflowId(), 'the shared row of the language before its own default fallback');
        self::assertSame(304, $router->resolve(Trigger::WELCOME, 2, 'fi')->workflowId(), 'its own default fallback before the shared one');

        $router = $this->router(array_merge($shared, [[2, Trigger::WELCOME, '', 'default', 301]]), $accounts, $layout);
        self::assertSame(103, $router->resolve(Trigger::WELCOME, 2, 'fi')->workflowId(), 'the shared default fallback before its own row of no language');
    }

    /**
     * ARCHITECTURE 5.2, mode `a`: the row of the contact's language, else the default-fallback row,
     * else the row of the shop's fallback language; with none of them, a skip.
     */
    public function testInModeATheDefaultFallbackRowComesBeforeTheFallbackLanguagesRow(): void
    {
        $layout = [0 => [StoreLayout::LANGUAGE_MODE => StoreLayout::MODE_ACCOUNT_PER_LANGUAGE, StoreLayout::FALLBACK_LANGUAGE => 'en']];
        $accounts = [0 => ['' => 'sharedEn', 'en' => 'sharedEn', 'et' => 'sharedEt']];
        $rows = [[0, Trigger::WELCOME, 'en', 'en', 103], [0, Trigger::WELCOME, 'et', 'et', 102, false, true]];

        $match = $this->router($rows, $accounts, $layout)->resolve(Trigger::WELCOME, 1, 'fi');
        self::assertSame([102, 'et', 'sharedEt'], [$match->workflowId(), $match->accountKey(), $match->account()->subdomain()], 'the default fallback, through its own account');

        $rows[1][6] = false;
        $match = $this->router($rows, $accounts, $layout)->resolve(Trigger::WELCOME, 1, 'fi');
        self::assertSame([103, 'en'], [$match->workflowId(), $match->accountKey()], 'the row of the fallback language');

        $router = $this->router([[0, Trigger::WELCOME, 'et', 'et', 102]], $accounts, $layout);
        self::assertEquals(new Skipped(Router::SKIPPED_NO_WORKFLOW), $router->route(Trigger::WELCOME, 1, 'fi'), 'no row of the language, no default fallback, none of the fallback language');
    }

    /**
     * ARCHITECTURE 5.2, "a shop on another account than the global one reads only its own rows",
     * in a per-language mode and for an account that differs in its API user alone.
     */
    public function testAShopOnAnotherAccountReadsNoSharedRowOfAnyStep(): void
    {
        $layout = [0 => [StoreLayout::LANGUAGE_MODE => StoreLayout::MODE_WORKFLOW_PER_LANGUAGE]];
        $accounts = [0 => ['' => 'shared'], 2 => ['' => 'other']];
        $shared = [
            [0, Trigger::WELCOME, 'et', 'default', 102],
            [0, Trigger::WELCOME, 'en', 'default', 103, false, true],
            [0, Trigger::WELCOME, '', 'default', 101],
        ];

        $router = $this->router($shared, $accounts, $layout);
        foreach (['et', 'fi', ''] as $language) {
            self::assertEquals(new Skipped(Router::SKIPPED_NO_WORKFLOW), $router->route(Trigger::WELCOME, 2, $language));
        }
        self::assertSame([[1, 0], [2], [0]], [$router->rowShops(1), $router->rowShops(2), $router->rowShops(0)]);

        $match = $this->router(array_merge($shared, [[2, Trigger::WELCOME, '', 'default', 301]]), $accounts, $layout)->resolve(Trigger::WELCOME, 2, 'et');
        self::assertSame([301, 'other'], [$match->workflowId(), $match->account()->subdomain()]);

        $sameSubdomain = new Router(
            new FakeAutomationSettings([0 => [AutomationSettings::WELCOME_ENABLED => '1']]),
            new FakeStoreLayout([]),
            new FakeMappingRepository([[0, Trigger::WELCOME, '', 'default', 101]]),
            static fn (int $idShop, string $language): ?Account => $language !== '' ? null : new Account($idShop, '', 'shared', $idShop === 2 ? 'second-api-user' : 'api-user', 'not-a-real-password')
        );
        self::assertEquals(new Skipped(Router::SKIPPED_NO_WORKFLOW), $sameSubdomain->route(Trigger::WELCOME, 2, ''), 'another API user of the same subdomain is another account');
        self::assertSame(101, $sameSubdomain->resolve(Trigger::WELCOME, 1, '')->workflowId());
    }

    /**
     * Magento CON-184: a workflow id belongs to the account it was picked from, so a mode never reads
     * the rows of another mode's accounts.
     */
    public function testAModeReadsOnlyTheRowsOfItsOwnAccounts(): void
    {
        $rows = [[0, Trigger::WELCOME, 'et', 'et', 102], [0, Trigger::WELCOME, 'en', 'default', 103]];
        $accounts = [0 => ['' => 'shared', 'et' => 'sharedEt']];

        $modeB = $this->router($rows, $accounts, [0 => [StoreLayout::LANGUAGE_MODE => StoreLayout::MODE_WORKFLOW_PER_LANGUAGE]]);
        self::assertEquals(new Skipped(Router::SKIPPED_NO_WORKFLOW), $modeB->route(Trigger::WELCOME, 1, 'et'));
        self::assertSame(103, $modeB->resolve(Trigger::WELCOME, 1, 'en')->workflowId());

        $modeA = $this->router($rows, $accounts, [0 => [StoreLayout::LANGUAGE_MODE => StoreLayout::MODE_ACCOUNT_PER_LANGUAGE]]);
        self::assertSame(102, $modeA->resolve(Trigger::WELCOME, 1, 'et')->workflowId());
        self::assertEquals(new Skipped(Router::SKIPPED_NO_WORKFLOW), $modeA->route(Trigger::WELCOME, 1, 'en'));
    }

    /**
     * Decision 5: an upgraded welcome without a chosen workflow keeps the generic opt-in trigger;
     * no other automation starts Smaily's opt-in workflows.
     */
    public function testTheWelcomesGenericOptInRowMatchesAndNoOtherAutomationsDoes(): void
    {
        $router = $this->router([
            [0, Trigger::WELCOME, '', 'default', null, true, true],
            [0, Trigger::ABANDONED_CART, '', 'default', null, true, true],
        ], [0 => ['' => 'shared']], [], [0 => [AutomationSettings::WELCOME_ENABLED => '1', AutomationSettings::CART_ENABLED => '1']]);

        $match = $router->resolve(Trigger::WELCOME, 1, 'et');
        self::assertTrue($match->isOptInTrigger());
        self::assertNull($match->workflowId());
        self::assertEquals(new Skipped(Router::SKIPPED_NO_WORKFLOW), $router->route(Trigger::ABANDONED_CART, 1, 'et'));
    }

    public function testTheAccountOfARowsKey(): void
    {
        $router = $this->router([], [0 => ['' => 'shared', 'et' => 'sharedEt'], 2 => ['et' => 'ownEt']]);

        self::assertSame('shared', $router->accountFor(2, 'default')->subdomain());
        self::assertSame('ownEt', $router->accountFor(2, 'et')->subdomain());
        self::assertSame('sharedEt', $router->accountFor(1, 'et')->subdomain());
        self::assertNull($router->accountFor(1, 'fi'));
    }

    /**
     * The router of the table's world, with every automation on.
     */
    private function worldRouter(string $mode, string $layout): Router
    {
        $setup = self::LAYOUTS[$layout];
        $scopes = $setup['separate'] ? $setup['shops'] : [0];
        $rows = [];
        $accounts = [];
        foreach ($scopes as $scope) {
            $base = 100 * ($scope + 1);
            $accounts[$scope] = ['' => 'acct' . $scope, 'et' => 'acct' . $scope . 'et', 'en' => 'acct' . $scope . 'en'];
            $rows[] = [$scope, Trigger::WELCOME, '', 'default', $base + 1];
            if ($mode === StoreLayout::MODE_WORKFLOW_PER_LANGUAGE) {
                $rows[] = [$scope, Trigger::WELCOME, 'et', 'default', $base + 2];
            }
            if ($mode === StoreLayout::MODE_ACCOUNT_PER_LANGUAGE) {
                $rows[] = [$scope, Trigger::WELCOME, 'et', 'et', $base + 2];
                $rows[] = [$scope, Trigger::WELCOME, 'en', 'en', $base + 3];
            }
        }
        if ($setup['separate']) {
            $accounts[0] = ['' => 'leftover', 'et' => 'leftover', 'en' => 'leftover'];
            foreach (['', 'et', 'en'] as $language) {
                $rows[] = [0, Trigger::WELCOME, $language, $language === '' ? 'default' : $language, 999];
            }
        }
        $layoutValues = [StoreLayout::LANGUAGE_MODE => $mode, StoreLayout::FALLBACK_LANGUAGE => 'en'];
        if ($setup['separate']) {
            $layoutValues[StoreLayout::SEPARATE_STORES] = '1';
        }

        return $this->router($rows, $accounts, [0 => $layoutValues]);
    }

    /**
     * @param list<array{0: int, 1: string, 2: string, 3: string, 4: int|null, 5?: bool, 6?: bool}> $rows
     * @param array<int, array<string, string>> $accounts subdomain by shop and language
     * @param array<int, array<string, string>> $layout
     * @param array<int, array<string, string>>|null $settings null: every automation on
     * @param list<string> $withoutPassword the subdomains whose password does not decrypt
     */
    private function router(array $rows, array $accounts, array $layout = [], ?array $settings = null, array $withoutPassword = []): Router
    {
        $settings ??= [0 => [
            AutomationSettings::WELCOME_ENABLED => '1',
            AutomationSettings::FIRST_ORDER_ENABLED => '1',
            AutomationSettings::CART_ENABLED => '1',
        ]];

        return new Router(
            new FakeAutomationSettings($settings),
            new FakeStoreLayout($layout),
            new FakeMappingRepository($rows),
            static function (int $idShop, string $language) use ($accounts, $withoutPassword): ?Account {
                $subdomain = $accounts[$idShop][$language] ?? null;

                return $subdomain === null ? null : new Account(
                    $idShop,
                    $language,
                    $subdomain,
                    'api-user',
                    in_array($subdomain, $withoutPassword, true) ? '' : 'not-a-real-password'
                );
            }
        );
    }
}
