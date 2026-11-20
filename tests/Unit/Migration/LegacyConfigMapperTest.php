<?php
declare(strict_types=1);

namespace Tests\Unit\Migration;

use PHPUnit\Framework\TestCase;
use PrestaShop\Module\SmailyForPrestaShop\Migration\LegacyConfigMapper;
use PrestaShop\Module\SmailyForPrestaShop\Migration\LegacyState;
use PrestaShop\Module\SmailyForPrestaShop\Migration\MigrationPlan;

require_once __DIR__ . '/LegacyFixture.php';

/**
 * The inference rules of the legacy mapper, one case per rule. Rows are written as
 * [id_shop_group, id_shop, name, value]; [null, null, …] is a global row.
 */
final class LegacyConfigMapperTest extends TestCase
{
    private const PASSWORD = 'fake-password-for-tests';

    /**
     * A configured 2.x store at the global scope.
     */
    private const CONFIGURED = [
        [null, null, 'SMAILY_SUBDOMAIN', 'example-store'],
        [null, null, 'SMAILY_USERNAME', 'api-user'],
        [null, null, 'SMAILY_PASSWORD', self::PASSWORD],
        [null, null, 'SMAILY_ENABLE_CUSTOMER_SYNC', '1'],
        [null, null, 'SMAILY_CUSTOMER_CRON_TOKEN', 'abc123def456'],
        [null, null, 'SMAILY_SYNCRONIZE_ADDITIONAL', '{"store":true,"first_name":true}'],
        [null, null, 'SMAILY_OPTIN_ENABLED', '1'],
        [null, null, 'SMAILY_OPTIN_AUTORESPONDER', '101'],
        [null, null, 'SMAILY_ENABLE_ABANDONED_CART', '1'],
        [null, null, 'SMAILY_CART_CRON_TOKEN', 'fed654cba321'],
        [null, null, 'SMAILY_CART_AUTORESPONDER', '102'],
        [null, null, 'SMAILY_ABANDONED_CART_TIME', '45'],
        [null, null, 'SMAILY_CART_SYNCRONIZE_ADDITIONAL', '{"first_name":true}'],
    ];

    // --- Reading a legacy value: a row holding NULL, an empty row and no row ---

    /**
     * @dataProvider singleShopReads
     *
     * @param list<array{0: int|null, 1: int|null, 2: string, 3: string|null}> $rows
     */
    public function testASingleShopReadsTheGlobalRowAndIgnoresShopRows(array $rows, string $welcome): void
    {
        $plan = $this->map(self::rows($rows, 'SMAILY_OPTIN_ENABLED'));

        self::assertSame($welcome, $plan['settings'][0]['SMAILY_CONNECT_WELCOME_ENABLED']);
        self::assertArrayNotHasKey(1, $plan['settings']);
    }

    /**
     * @return array<string, array{list<array{0: int|null, 1: int|null, 2: string, 3: string|null}>, string}>
     */
    public static function singleShopReads(): array
    {
        return [
            'global row on' => [[[null, null, 'SMAILY_OPTIN_ENABLED', '1']], '1'],
            'global row NULL' => [[[null, null, 'SMAILY_OPTIN_ENABLED', null]], '0'],
            'global row empty' => [[[null, null, 'SMAILY_OPTIN_ENABLED', '']], '0'],
            'no row' => [[], '0'],
            // With multistore off PrestaShop reads only global rows.
            'shop row NULL over a global row on' => [
                [[null, null, 'SMAILY_OPTIN_ENABLED', '1'], [1, 1, 'SMAILY_OPTIN_ENABLED', null]],
                '1',
            ],
        ];
    }

    /**
     * @dataProvider sharedSetupReads
     *
     * @param list<array{0: int|null, 1: int|null, 2: string, 3: string|null}> $rows
     */
    public function testShopsSharingOneSetupReadTheGlobalRow(array $rows, string $welcome): void
    {
        $plan = $this->map(self::rows($rows, 'SMAILY_OPTIN_ENABLED'), [1 => 1, 2 => 1], '2.2.0', 1, [1]);

        self::assertSame([0], array_keys($plan['settings']));
        self::assertSame($welcome, $plan['settings'][0]['SMAILY_CONNECT_WELCOME_ENABLED']);
    }

    /**
     * @return array<string, array{list<array{0: int|null, 1: int|null, 2: string, 3: string|null}>, string}>
     */
    public static function sharedSetupReads(): array
    {
        return [
            'global row on' => [[[null, null, 'SMAILY_OPTIN_ENABLED', '1']], '1'],
            'global row NULL' => [[[null, null, 'SMAILY_OPTIN_ENABLED', null]], '0'],
            'global row empty' => [[[null, null, 'SMAILY_OPTIN_ENABLED', '']], '0'],
            'no row' => [[], '0'],
        ];
    }

    /**
     * A shop row wins even when it holds NULL or ''; the shop group's row, then the global row,
     * apply only where the narrower scope has no row (docs/internal/spikes/platform-facts.md 1.5).
     *
     * @dataProvider ownRowReads
     *
     * @param list<array{0: int|null, 1: int|null, 2: string, 3: string|null}> $rows
     * @param array<int, string> $shopWelcome id_shop => own WELCOME_ENABLED, absent = inherits
     */
    public function testShopsWithOwnRowsReadTheirOwnRowBeforeTheGroupAndGlobalRows(
        array $rows,
        string $globalWelcome,
        array $shopWelcome
    ): void {
        $plan = $this->map(self::rows($rows, 'SMAILY_OPTIN_ENABLED'), [1 => 1, 2 => 2, 3 => 2]);

        self::assertSame($globalWelcome, $plan['settings'][0]['SMAILY_CONNECT_WELCOME_ENABLED']);
        $own = [];
        foreach ($plan['settings'] as $idShop => $settings) {
            if ($idShop > 0 && isset($settings['SMAILY_CONNECT_WELCOME_ENABLED'])) {
                $own[$idShop] = $settings['SMAILY_CONNECT_WELCOME_ENABLED'];
            }
        }
        self::assertSame($shopWelcome, $own);
    }

    /**
     * @return array<string, array{list<array{0: int|null, 1: int|null, 2: string, 3: string|null}>, string, array<int, string>}>
     */
    public static function ownRowReads(): array
    {
        $globalOn = [null, null, 'SMAILY_OPTIN_ENABLED', '1'];
        $globalNull = [null, null, 'SMAILY_OPTIN_ENABLED', null];

        return [
            'shop row NULL does not fall back' => [[$globalOn, [2, 2, 'SMAILY_OPTIN_ENABLED', null]], '1', [2 => '0']],
            'shop row empty does not fall back' => [[$globalOn, [2, 2, 'SMAILY_OPTIN_ENABLED', '']], '1', [2 => '0']],
            'no shop row falls back' => [[$globalOn], '1', []],
            'shop row on over a global row NULL' => [[$globalNull, [2, 2, 'SMAILY_OPTIN_ENABLED', '1']], '0', [2 => '1']],
            'shop row on over no global row' => [[[2, 2, 'SMAILY_OPTIN_ENABLED', '1']], '0', [2 => '1']],
            'group row NULL does not fall back' => [[$globalOn, [2, null, 'SMAILY_OPTIN_ENABLED', null]], '1', [2 => '0', 3 => '0']],
            'shop row beats the group row' => [
                [$globalOn, [2, null, 'SMAILY_OPTIN_ENABLED', null], [2, 3, 'SMAILY_OPTIN_ENABLED', '1']],
                '1',
                [2 => '0'],
            ],
        ];
    }

    /**
     * After a save in shop 2 and a later "All shops" save, shop 2 keeps its older values
     * (tests/fixtures/legacy/README.md "Multistore"), and so does its plan.
     */
    public function testAShopRowLeftByAnAllShopsSaveKeepsTheShopsValue(): void
    {
        $plan = $this->map(array_merge(
            self::without(self::CONFIGURED, 'SMAILY_OPTIN_AUTORESPONDER'),
            [[null, null, 'SMAILY_OPTIN_AUTORESPONDER', '201'], [1, 2, 'SMAILY_OPTIN_AUTORESPONDER', '101']]
        ), [1 => 1, 2 => 1], '2.2.0', 1, [1]);

        self::assertSame([
            LegacyFixture::mapping(0, 'welcome', 201),
            LegacyFixture::mapping(0, 'abandoned_cart', 102),
            LegacyFixture::mapping(2, 'welcome', 101),
        ], $plan['mappings']);
        self::assertArrayNotHasKey(2, $plan['settings']);
    }

    // --- Account ---

    /**
     * @dataProvider subdomains
     */
    public function testTheSubdomainIsNormalised(string $stored, string $normalised): void
    {
        $plan = $this->map(array_merge(
            self::without(self::CONFIGURED, 'SMAILY_SUBDOMAIN'),
            [[null, null, 'SMAILY_SUBDOMAIN', $stored]]
        ));

        self::assertSame($normalised, $plan['accounts'][0]['subdomain']);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function subdomains(): array
    {
        return [
            'as typed' => ['example-store', 'example-store'],
            'spaces and capitals' => ['  Example-Store ', 'example-store'],
            'a host name' => ['example-store.sendsmaily.net', 'example-store'],
            'a URL' => ['https://Example-Store.sendsmaily.net/', 'example-store'],
            'stripped by 1.6.x' => ['examplestore', 'examplestore'],
        ];
    }

    /**
     * @dataProvider incompleteAccounts
     */
    public function testAnAccountNeedsSubdomainUsernameAndPassword(string $key, ?string $value): void
    {
        $plan = $this->map(array_merge(self::without(self::CONFIGURED, $key), [[null, null, $key, $value]]));

        self::assertSame([], $plan['accounts']);
        self::assertSame('new', $plan['settings'][0]['SMAILY_CONNECT_SETUP_STATE']);
        self::assertSame('0', $plan['settings'][0]['SMAILY_CONNECT_WELCOME_ENABLED']);
        self::assertSame('0', $plan['settings'][0]['SMAILY_CONNECT_CONTACTS_ENABLED']);
        self::assertSame('0', $plan['settings'][0]['SMAILY_CONNECT_CART_ENABLED']);
        self::assertSame([
            ['code' => MigrationPlan::NOTICE_CONTACT_SYNC_LEFT_OFF, 'params' => ['id_shop' => 1, 'reason' => MigrationPlan::REASON_NO_ACCOUNT]],
            ['code' => MigrationPlan::NOTICE_WELCOME_LEFT_OFF, 'params' => ['id_shop' => 1, 'reason' => MigrationPlan::REASON_NO_ACCOUNT]],
            ['code' => MigrationPlan::NOTICE_ABANDONED_CART_LEFT_OFF, 'params' => ['id_shop' => 1, 'reason' => MigrationPlan::REASON_NO_ACCOUNT]],
            ['code' => MigrationPlan::NOTICE_EMAILSUBSCRIPTION_EMAILS_CHANGED, 'params' => []],
        ], $plan['notices']);
    }

    /**
     * @return array<string, array{string, string|null}>
     */
    public static function incompleteAccounts(): array
    {
        return [
            'no subdomain' => ['SMAILY_SUBDOMAIN', null],
            'no username' => ['SMAILY_USERNAME', ''],
            'no password' => ['SMAILY_PASSWORD', null],
        ];
    }

    public function testAShopWhoseAccountEqualsTheGlobalOneGetsNoOwnRow(): void
    {
        $plan = $this->map(array_merge(self::CONFIGURED, [
            [1, 2, 'SMAILY_SUBDOMAIN', 'Example-Store'],
            [1, 2, 'SMAILY_USERNAME', 'api-user'],
            [1, 2, 'SMAILY_PASSWORD', self::PASSWORD],
        ]), [1 => 1, 2 => 1], '2.2.0', 1, [1]);

        self::assertSame([['id_shop' => 0, 'language' => '', 'subdomain' => 'example-store', 'username' => 'api-user']], $plan['accounts']);
        self::assertSame([0], array_keys($plan['settings']));
    }

    // --- Contact sync, read at the default shop ---

    public function testContactSyncStaysOnWhere2xSyncedWithAnAccount(): void
    {
        $settings = $this->map(self::CONFIGURED)['settings'][0];

        self::assertSame('1', $settings['SMAILY_CONNECT_CONTACTS_ENABLED']);
        self::assertSame('1', $settings['SMAILY_CONNECT_CONTACTS_CONFIRMED']);
    }

    /**
     * @dataProvider contactSyncSwitches
     *
     * @param list<array{0: int|null, 1: int|null, 2: string, 3: string|null}> $switches
     */
    public function testContactSyncReadsTheSwitchThatThePreviousCodeRead(
        string $version,
        array $switches,
        string $enabled,
        ?string $reason
    ): void {
        $plan = $this->map(
            array_merge(self::without(self::without(self::CONFIGURED, 'SMAILY_ENABLE_CUSTOMER_SYNC'), 'SMAILY_ENABLE_CRON'), $switches),
            [1 => 1],
            $version
        );

        self::assertSame($enabled, $plan['settings'][0]['SMAILY_CONNECT_CONTACTS_ENABLED']);
        $notices = self::noticesOf($plan, MigrationPlan::NOTICE_CONTACT_SYNC_LEFT_OFF);
        self::assertSame($reason === null ? [] : [['id_shop' => 1, 'reason' => $reason]], $notices);
    }

    /**
     * @return array<string, array{string, list<array{0: int|null, 1: int|null, 2: string, 3: string|null}>, string, string|null}>
     */
    public static function contactSyncSwitches(): array
    {
        return [
            '2.x on' => ['2.2.0', [[null, null, 'SMAILY_ENABLE_CUSTOMER_SYNC', '1']], '1', null],
            '2.x off (NULL)' => ['2.2.0', [[null, null, 'SMAILY_ENABLE_CUSTOMER_SYNC', null]], '0', null],
            '2.x with only the 1.6.x switch' => ['2.2.0', [[null, null, 'SMAILY_ENABLE_CRON', '1']], '0', MigrationPlan::REASON_SWITCH_NOT_READ],
            '1.6.x on' => ['1.6.2', [[null, null, 'SMAILY_ENABLE_CRON', '1']], '1', null],
            '1.6.x off' => ['1.6.2', [[null, null, 'SMAILY_ENABLE_CRON', '0']], '0', null],
            '1.6.x code ignores the 2.x switch' => ['1.6.2', [[null, null, 'SMAILY_ENABLE_CUSTOMER_SYNC', '1']], '0', null],
        ];
    }

    /**
     * The 2.x contact sync pushed every shop's subscribers through the account of the default
     * shop's context; a shop on another account was never synced to that account.
     */
    public function testAShopOnAnotherAccountGetsTheCronFeaturesOff(): void
    {
        $plan = $this->map(array_merge(self::CONFIGURED, [
            [1, 2, 'SMAILY_SUBDOMAIN', 'other-store'],
        ]), [1 => 1, 2 => 1], '2.2.0', 1, [1]);

        self::assertSame('1', $plan['settings'][0]['SMAILY_CONNECT_CONTACTS_ENABLED']);
        self::assertSame('0', $plan['settings'][2]['SMAILY_CONNECT_CONTACTS_ENABLED']);
        self::assertSame('0', $plan['settings'][2]['SMAILY_CONNECT_CART_ENABLED']);
        self::assertSame('1', $plan['settings'][2]['SMAILY_CONNECT_WELCOME_ENABLED'] ?? $plan['settings'][0]['SMAILY_CONNECT_WELCOME_ENABLED']);
        self::assertSame(
            [['id_shop' => 2, 'reason' => MigrationPlan::REASON_OTHER_ACCOUNT]],
            self::noticesOf($plan, MigrationPlan::NOTICE_CONTACT_SYNC_LEFT_OFF)
        );
        self::assertSame(
            [['id_shop' => 2, 'reason' => MigrationPlan::REASON_OTHER_ACCOUNT]],
            self::noticesOf($plan, MigrationPlan::NOTICE_ABANDONED_CART_LEFT_OFF)
        );
        self::assertSame(
            [['id_shop' => 0, 'language' => '', 'subdomain' => 'example-store', 'username' => 'api-user'],
                ['id_shop' => 2, 'language' => '', 'subdomain' => 'other-store', 'username' => 'api-user']],
            $plan['accounts']
        );
    }

    public function testASwitchOnOnlyOutsideTheDefaultShopLeavesContactSyncOff(): void
    {
        $plan = $this->map(array_merge(
            self::without(self::CONFIGURED, 'SMAILY_ENABLE_CUSTOMER_SYNC'),
            [[null, null, 'SMAILY_ENABLE_CUSTOMER_SYNC', null], [1, 2, 'SMAILY_ENABLE_CUSTOMER_SYNC', '1']]
        ), [1 => 1, 2 => 1], '2.2.0', 1, [1]);

        self::assertSame('0', $plan['settings'][0]['SMAILY_CONNECT_CONTACTS_ENABLED']);
        self::assertArrayNotHasKey(2, $plan['settings']);
        self::assertSame(
            [['id_shop' => 2, 'reason' => MigrationPlan::REASON_NOT_DEFAULT_SHOP]],
            self::noticesOf($plan, MigrationPlan::NOTICE_CONTACT_SYNC_LEFT_OFF)
        );
    }

    public function testTheDefaultShopsOwnSwitchDecidesContactSync(): void
    {
        $plan = $this->map(array_merge(
            self::without(self::CONFIGURED, 'SMAILY_ENABLE_CUSTOMER_SYNC'),
            [[null, null, 'SMAILY_ENABLE_CUSTOMER_SYNC', null], [1, 2, 'SMAILY_ENABLE_CUSTOMER_SYNC', '1']]
        ), [1 => 1, 2 => 1], '2.2.0', 2);

        self::assertSame('1', $plan['settings'][0]['SMAILY_CONNECT_CONTACTS_ENABLED']);
        self::assertSame([], self::noticesOf($plan, MigrationPlan::NOTICE_CONTACT_SYNC_LEFT_OFF));
        self::assertSame([['id_shop' => 2]], self::noticesOf($plan, MigrationPlan::NOTICE_CRON_SHOP_ASSUMED));
    }

    // --- Consent mode, contact fields, language mode, first order ---

    public function testAnUpgradeStartsInSubscribersOnlyWithGuestsAndOneLanguage(): void
    {
        $settings = $this->map(self::CONFIGURED)['settings'][0];

        self::assertSame('subscribers', $settings['SMAILY_CONNECT_CONTACTS_MODE']);
        self::assertSame('1', $settings['SMAILY_CONNECT_CONTACTS_GUESTS']);
        self::assertSame('0', $settings['SMAILY_CONNECT_CHECKOUT_CHECKBOX']);
        self::assertSame('single', $settings['SMAILY_CONNECT_LANGUAGE_MODE']);
        self::assertSame('0', $settings['SMAILY_CONNECT_FIRST_ORDER_ENABLED']);
    }

    /**
     * @dataProvider contactFieldLists
     */
    public function testContactFieldsKeepTheKnownTickedNamesWithStoreAlwaysOn(?string $stored, string $fields, bool $notice): void
    {
        $plan = $this->map(array_merge(
            self::without(self::CONFIGURED, 'SMAILY_SYNCRONIZE_ADDITIONAL'),
            [[null, null, 'SMAILY_SYNCRONIZE_ADDITIONAL', $stored]]
        ));

        self::assertSame($fields, $plan['settings'][0]['SMAILY_CONNECT_CONTACTS_FIELDS']);
        self::assertSame(
            $notice ? [['key' => 'SMAILY_SYNCRONIZE_ADDITIONAL']] : [],
            self::noticesOf($plan, MigrationPlan::NOTICE_FIELD_LIST_UNREADABLE)
        );
    }

    /**
     * @return array<string, array{string|null, string, bool}>
     */
    public static function contactFieldLists(): array
    {
        return [
            '2.x map' => ['{"store":true,"first_name":false,"last_name":true,"birthday":true,"website":false}', 'store,last_name,birthday', false],
            '1.6.x list' => ['a:2:{i:0;s:7:"website";i:1;s:10:"first_name";}', 'store,first_name,website', false],
            'unknown names dropped' => ['{"gender":true,"first_name":true}', 'store,first_name', false],
            '2.x install' => ['[]', 'store', false],
            '1.6.x install' => ['a:0:{}', 'store', false],
            'NULL' => [null, 'store', false],
            'unreadable' => ['first_name,last_name', 'store', true],
        ];
    }

    // --- Welcome ---

    /**
     * Decision 5: an opt-in without a workflow keeps firing the generic opt-in trigger.
     *
     * @dataProvider emptyOptInWorkflows
     */
    public function testAnOptInWithoutAWorkflowKeepsTheGenericOptInTrigger(string $version, ?string $workflow): void
    {
        $plan = $this->map(array_merge(
            self::without(self::CONFIGURED, 'SMAILY_OPTIN_AUTORESPONDER'),
            [[null, null, 'SMAILY_OPTIN_AUTORESPONDER', $workflow]]
        ), [1 => 1], $version);

        self::assertSame('1', $plan['settings'][0]['SMAILY_CONNECT_WELCOME_ENABLED']);
        self::assertSame(LegacyFixture::mapping(0, 'welcome', null), $plan['mappings'][0]);
    }

    /**
     * @return array<string, array{string, string|null}>
     */
    public static function emptyOptInWorkflows(): array
    {
        return [
            '2.x NULL' => ['2.2.0', null],
            '1.6.x any' => ['1.6.2', ''],
        ];
    }

    public function testAnOptInWithAnUnusableWorkflowIdIsLeftOff(): void
    {
        $plan = $this->map(array_merge(
            self::without(self::CONFIGURED, 'SMAILY_OPTIN_AUTORESPONDER'),
            [[null, null, 'SMAILY_OPTIN_AUTORESPONDER', 'abc']]
        ));

        self::assertSame('0', $plan['settings'][0]['SMAILY_CONNECT_WELCOME_ENABLED']);
        self::assertSame(
            [['id_shop' => 1, 'reason' => MigrationPlan::REASON_NO_WORKFLOW]],
            self::noticesOf($plan, MigrationPlan::NOTICE_WELCOME_LEFT_OFF)
        );
    }

    public function testAnOptInSwitchedOffKeepsItsChosenWorkflowButNoGenericTrigger(): void
    {
        $plan = $this->map(array_merge(
            self::without(self::CONFIGURED, 'SMAILY_OPTIN_ENABLED'),
            [[null, null, 'SMAILY_OPTIN_ENABLED', '0']]
        ), [1 => 1], '1.6.2');

        self::assertSame('0', $plan['settings'][0]['SMAILY_CONNECT_WELCOME_ENABLED']);
        self::assertSame(LegacyFixture::mapping(0, 'welcome', 101), $plan['mappings'][0]);

        $plan = $this->map(array_merge(
            self::without(self::without(self::CONFIGURED, 'SMAILY_OPTIN_ENABLED'), 'SMAILY_OPTIN_AUTORESPONDER'),
            [[null, null, 'SMAILY_OPTIN_ENABLED', null]]
        ));

        self::assertSame(['abandoned_cart'], array_column($plan['mappings'], 'trigger'));
    }

    // --- Abandoned cart, read at the default shop ---

    /**
     * @dataProvider cartCases
     *
     * @param list<array{0: int|null, 1: int|null, 2: string, 3: string|null}> $rows
     */
    public function testAbandonedCartStaysOnOnlyWhereTheCartCronSent(
        string $version,
        array $rows,
        string $enabled,
        ?string $reason
    ): void {
        $keys = array_column($rows, 2);
        $base = self::CONFIGURED;
        foreach ($keys as $key) {
            $base = self::without($base, $key);
        }
        $plan = $this->map(array_merge($base, $rows), [1 => 1], $version);

        self::assertSame($enabled, $plan['settings'][0]['SMAILY_CONNECT_CART_ENABLED']);
        self::assertSame(
            $reason === null ? [] : [['id_shop' => 1, 'reason' => $reason]],
            self::noticesOf($plan, MigrationPlan::NOTICE_ABANDONED_CART_LEFT_OFF)
        );
    }

    /**
     * @return array<string, array{string, list<array{0: int|null, 1: int|null, 2: string, 3: string|null}>, string, string|null}>
     */
    public static function cartCases(): array
    {
        return [
            '2.x on' => ['2.2.0', [], '1', null],
            '2.x off at install' => ['2.2.0', [[null, null, 'SMAILY_ENABLE_ABANDONED_CART', '0']], '0', null],
            '2.x switched off' => ['2.2.0', [[null, null, 'SMAILY_ENABLE_ABANDONED_CART', null]], '0', null],
            '2.x without a workflow' => ['2.2.0', [[null, null, 'SMAILY_CART_AUTORESPONDER', null]], '0', MigrationPlan::REASON_NO_WORKFLOW],
            '2.x with a serialized field list' => [
                '2.2.0',
                [[null, null, 'SMAILY_CART_SYNCRONIZE_ADDITIONAL', 'a:1:{i:0;s:10:"first_name";}']],
                '0',
                MigrationPlan::REASON_FIELD_LIST_NOT_JSON,
            ],
            '2.x with no field list' => ['2.2.0', [[null, null, 'SMAILY_CART_SYNCRONIZE_ADDITIONAL', null]], '0', MigrationPlan::REASON_FIELD_LIST_NOT_JSON],
            '1.6.x on with a serialized field list' => [
                '1.6.2',
                [[null, null, 'SMAILY_CART_SYNCRONIZE_ADDITIONAL', 'a:1:{i:0;s:10:"first_name";}']],
                '1',
                null,
            ],
            '1.6.x compares with 1' => ['1.6.2', [[null, null, 'SMAILY_ENABLE_ABANDONED_CART', 'on']], '0', null],
        ];
    }

    /**
     * @dataProvider cartDelays
     */
    public function testTheCartDelayIsTheLegacyMinutesFromTenUpAnd15WhenNoneIsStored(?string $stored, string $delay): void
    {
        $plan = $this->map(array_merge(
            self::without(self::CONFIGURED, 'SMAILY_ABANDONED_CART_TIME'),
            [[null, null, 'SMAILY_ABANDONED_CART_TIME', $stored]]
        ));

        self::assertSame($delay, $plan['settings'][0]['SMAILY_CONNECT_CART_DELAY_MINUTES']);
    }

    /**
     * @return array<string, array{string|null, string}>
     */
    public static function cartDelays(): array
    {
        return [
            '15' => ['15', '15'],
            '45' => ['45', '45'],
            '60' => ['60', '60'],
            '600' => ['600', '600'],
            '10, the minimum' => ['10', '10'],
            '1435, the maximum' => ['1435', '1435'],
            'below the minimum' => ['5', '10'],
            'two days, which 2.x reminded after' => ['2880', '2880'],
            'NULL' => [null, '15'],
            '1.6.x install' => ['', '15'],
        ];
    }

    /**
     * Acceptance 3: a shop that inherits the global switch but whose carts the cron never reached
     * gets the feature off at its own scope.
     */
    public function testAShopInheritingTheCartSwitchButNeverRemindedGetsItOff(): void
    {
        $plan = $this->map(self::CONFIGURED, [1 => 1, 2 => 2, 3 => 1], '2.2.0', 1, [1]);

        self::assertSame('1', $plan['settings'][0]['SMAILY_CONNECT_CART_ENABLED']);
        self::assertSame(['SMAILY_CONNECT_CART_ENABLED' => '0'], $plan['settings'][2]);
        self::assertArrayNotHasKey(3, $plan['settings']);
        self::assertSame(
            [['id_shop' => 2, 'reason' => MigrationPlan::REASON_CARTS_NOT_REACHED]],
            self::noticesOf($plan, MigrationPlan::NOTICE_ABANDONED_CART_LEFT_OFF)
        );
        self::assertSame([['id_shop' => 1]], self::noticesOf($plan, MigrationPlan::NOTICE_CRON_SHOP_ASSUMED));
    }

    public function testAShopInAGroupThatSharesNoCustomersIsNotReached(): void
    {
        $plan = $this->map(self::CONFIGURED, [1 => 1, 3 => 1], '2.2.0', 1, []);

        self::assertSame('0', $plan['settings'][3]['SMAILY_CONNECT_CART_ENABLED']);
    }

    public function testAShopRemindedByTheCronUsesTheDefaultShopsCartSettings(): void
    {
        $plan = $this->map(array_merge(self::CONFIGURED, [
            [1, 2, 'SMAILY_CART_AUTORESPONDER', '202'],
            [1, 2, 'SMAILY_ABANDONED_CART_TIME', '90'],
        ]), [1 => 1, 2 => 1], '2.2.0', 1, [1]);

        self::assertArrayNotHasKey(2, $plan['settings']);
        self::assertSame(['welcome', 'abandoned_cart'], array_column($plan['mappings'], 'trigger'));
    }

    // --- Shop layout ---

    public function testShopsOnDifferentAccountsWithoutAGlobalOneAreSeparateStores(): void
    {
        $shopAccount = static function (int $idShop, string $subdomain): array {
            return [
                [1, $idShop, 'SMAILY_SUBDOMAIN', $subdomain],
                [1, $idShop, 'SMAILY_USERNAME', 'api-user'],
                [1, $idShop, 'SMAILY_PASSWORD', self::PASSWORD],
            ];
        };
        $global = self::without(self::without(self::without(self::CONFIGURED, 'SMAILY_SUBDOMAIN'), 'SMAILY_USERNAME'), 'SMAILY_PASSWORD');
        $plan = $this->map(
            array_merge($global, $shopAccount(1, 'first-store'), $shopAccount(2, 'second-store')),
            [1 => 1, 2 => 1]
        );

        self::assertSame('1', $plan['settings'][0]['SMAILY_CONNECT_SEPARATE_STORES']);
        self::assertSame('upgraded', $plan['settings'][0]['SMAILY_CONNECT_SETUP_STATE']);
        self::assertArrayNotHasKey('SMAILY_CONNECT_WELCOME_ENABLED', $plan['settings'][0]);
        self::assertSame('1', $plan['settings'][1]['SMAILY_CONNECT_CONTACTS_ENABLED']);
        self::assertSame('1', $plan['settings'][1]['SMAILY_CONNECT_CART_ENABLED']);
        self::assertSame('1', $plan['settings'][2]['SMAILY_CONNECT_WELCOME_ENABLED']);
        self::assertSame('0', $plan['settings'][2]['SMAILY_CONNECT_CONTACTS_ENABLED']);
        self::assertSame('0', $plan['settings'][2]['SMAILY_CONNECT_CART_ENABLED']);
        self::assertSame([1, 2], array_column($plan['accounts'], 'id_shop'));
        self::assertSame([[1, 'welcome'], [1, 'abandoned_cart'], [2, 'welcome'], [2, 'abandoned_cart']], array_map(
            static function (array $mapping): array {
                return [$mapping['id_shop'], $mapping['trigger']];
            },
            $plan['mappings']
        ));
    }

    public function testShopsOnOneAccountWithoutAGlobalOneStayOneStore(): void
    {
        $rows = self::without(self::without(self::without(self::CONFIGURED, 'SMAILY_SUBDOMAIN'), 'SMAILY_USERNAME'), 'SMAILY_PASSWORD');
        foreach ([1, 2] as $idShop) {
            $rows[] = [1, $idShop, 'SMAILY_SUBDOMAIN', 'example-store'];
            $rows[] = [1, $idShop, 'SMAILY_USERNAME', 'api-user'];
            $rows[] = [1, $idShop, 'SMAILY_PASSWORD', self::PASSWORD];
        }
        $plan = $this->map($rows, [1 => 1, 2 => 1]);

        self::assertSame('0', $plan['settings'][0]['SMAILY_CONNECT_SEPARATE_STORES']);
        self::assertSame([1, 2], array_column($plan['accounts'], 'id_shop'));
        // The global scope has no account, so it sends nothing; each shop does.
        self::assertSame('0', $plan['settings'][0]['SMAILY_CONNECT_WELCOME_ENABLED']);
        self::assertSame('1', $plan['settings'][1]['SMAILY_CONNECT_WELCOME_ENABLED']);
        self::assertSame('1', $plan['settings'][2]['SMAILY_CONNECT_CONTACTS_ENABLED']);
    }

    // --- Cron tokens ---

    /**
     * @dataProvider emptyTokens
     *
     * @param list<array{0: int|null, 1: int|null, 2: string, 3: string|null}> $rows
     * @param list<array{key: string, id_shop_group: int|null, id_shop: int|null}> $tokens
     */
    public function testAnEmptyOrMissingTokenGetsANewOneAndANotice(array $rows, array $tokens): void
    {
        $plan = $this->map(array_merge(self::without(self::CONFIGURED, 'SMAILY_CART_CRON_TOKEN'), $rows), [1 => 1, 2 => 1]);

        self::assertSame($tokens, $plan['tokens_to_generate']);
        self::assertSame(array_map(static function (array $token): array {
            return ['key' => $token['key'], 'id_shop_group' => (int) $token['id_shop_group'], 'id_shop' => (int) $token['id_shop']];
        }, $tokens), self::noticesOf($plan, MigrationPlan::NOTICE_CRON_TOKEN_REPLACED));
        self::assertNotContains('SMAILY_CART_CRON_TOKEN', $plan['legacy_keys_to_delete']);
    }

    /**
     * @return array<string, array{list<array{0: int|null, 1: int|null, 2: string, 3: string|null}>, list<array{key: string, id_shop_group: int|null, id_shop: int|null}>}>
     */
    public static function emptyTokens(): array
    {
        $global = ['key' => 'SMAILY_CART_CRON_TOKEN', 'id_shop_group' => null, 'id_shop' => null];

        return [
            'non-empty kept' => [[[null, null, 'SMAILY_CART_CRON_TOKEN', 'fed654cba321']], []],
            '1.6.x install' => [[[null, null, 'SMAILY_CART_CRON_TOKEN', '']], [$global]],
            'NULL' => [[[null, null, 'SMAILY_CART_CRON_TOKEN', null]], [$global]],
            'no row' => [[], [$global]],
            'a shop row NULL' => [
                [[null, null, 'SMAILY_CART_CRON_TOKEN', 'fed654cba321'], [1, 2, 'SMAILY_CART_CRON_TOKEN', null]],
                [['key' => 'SMAILY_CART_CRON_TOKEN', 'id_shop_group' => 1, 'id_shop' => 2]],
            ],
        ];
    }

    // --- RSS, carts, notices ---

    public function testInvalidRssDefaultsAreLeftToTheNewDefaults(): void
    {
        $plan = $this->map([
            [null, null, 'SMAILY_RSS_CATEGORY_ID', ''],
            [null, null, 'SMAILY_RSS_LIMIT', '500'],
            [null, null, 'SMAILY_RSS_SORT_BY', 'random'],
            [null, null, 'SMAILY_RSS_SORT_ORDER', 'DESC'],
        ]);

        self::assertSame([], preg_grep('/^SMAILY_CONNECT_RSS_/', array_keys($plan['settings'][0])));
    }

    public function testSentCartsAreCarriedOverWhenTheTableHoldsRows(): void
    {
        self::assertTrue($this->map(self::CONFIGURED, [1 => 1], '2.2.0', 1, [], 1)['copy_sent_carts']);
        self::assertFalse($this->map(self::CONFIGURED)['copy_sent_carts']);
    }

    // --- Determinism and the password ---

    public function testTheSameStateAlwaysGivesTheSamePlan(): void
    {
        $rows = array_merge(self::CONFIGURED, [[1, 2, 'SMAILY_SUBDOMAIN', 'other-store'], [2, null, 'SMAILY_OPTIN_ENABLED', null]]);
        $mapper = new LegacyConfigMapper();
        $state = self::state($rows, [1 => 1, 2 => 1, 3 => 2]);

        $first = $mapper->map($state);
        $second = $mapper->map(self::state(array_reverse($rows), [3 => 2, 2 => 1, 1 => 1]));
        $third = (new LegacyConfigMapper())->map($state);

        self::assertSame($first->toArray(), $second->toArray());
        self::assertSame($first->toArray(), $third->toArray());
        self::assertSame(serialize($first->toArray()), serialize($third->toArray()));
        foreach ([0, 2] as $idShop) {
            self::assertTrue($first->passwordToEncrypt($idShop) === $second->passwordToEncrypt($idShop), 'same password for shop ' . $idShop);
        }
    }

    public function testThePasswordIsOnlyAnInstructionToEncrypt(): void
    {
        $plan = (new LegacyConfigMapper())->map(self::state(array_merge(
            self::CONFIGURED,
            [[1, 2, 'SMAILY_SUBDOMAIN', 'other-store'], [null, null, 'SMAILY_CART_CRON_TOKEN', '']]
        ), [1 => 1, 2 => 1]));

        self::assertTrue($plan->passwordToEncrypt(0) === self::PASSWORD, 'the global account carries the legacy password');
        self::assertTrue($plan->passwordToEncrypt(2) === self::PASSWORD, 'the shop account carries the legacy password');
        self::assertFalse(strpos(serialize($plan->toArray()), self::PASSWORD) !== false, 'the plan data holds no password');
        self::assertFalse(strpos(serialize($plan->notices()), self::PASSWORD) !== false, 'no notice holds the password');
    }

    // --- Helpers ---

    /**
     * @param list<array{0: int|null, 1: int|null, 2: string, 3: string|null}> $rows
     * @param array<int, int> $shopGroups
     * @param list<int> $groupsSharingCustomers
     *
     * @return array<string, mixed>
     */
    private function map(
        array $rows,
        array $shopGroups = [1 => 1],
        string $version = '2.2.0',
        int $defaultShop = 1,
        array $groupsSharingCustomers = [],
        int $sentCarts = 0
    ): array {
        return (new LegacyConfigMapper())
            ->map(self::state($rows, $shopGroups, $version, $defaultShop, $groupsSharingCustomers, $sentCarts))
            ->toArray();
    }

    /**
     * @param list<array{0: int|null, 1: int|null, 2: string, 3: string|null}> $rows
     * @param array<int, int> $shopGroups
     * @param list<int> $groupsSharingCustomers
     */
    private static function state(
        array $rows,
        array $shopGroups,
        string $version = '2.2.0',
        int $defaultShop = 1,
        array $groupsSharingCustomers = [],
        int $sentCarts = 0
    ): LegacyState {
        $stateRows = [];
        foreach ($rows as [$idShopGroup, $idShop, $name, $value]) {
            $stateRows[] = ['id_shop_group' => $idShopGroup, 'id_shop' => $idShop, 'name' => $name, 'value' => $value];
        }

        return new LegacyState($version, count($shopGroups) > 1, $defaultShop, $shopGroups, $groupsSharingCustomers, $stateRows, $sentCarts);
    }

    /**
     * A configured store with the rows of one key replaced.
     *
     * @param list<array{0: int|null, 1: int|null, 2: string, 3: string|null}> $rows
     *
     * @return list<array{0: int|null, 1: int|null, 2: string, 3: string|null}>
     */
    private static function rows(array $rows, string $key): array
    {
        return array_merge(self::without(self::CONFIGURED, $key), $rows);
    }

    /**
     * @param list<array{0: int|null, 1: int|null, 2: string, 3: string|null}> $rows
     *
     * @return list<array{0: int|null, 1: int|null, 2: string, 3: string|null}>
     */
    private static function without(array $rows, string $key): array
    {
        return array_values(array_filter($rows, static function (array $row) use ($key): bool {
            return $row[2] !== $key;
        }));
    }

    /**
     * @param array<string, mixed> $plan
     *
     * @return list<array<string, int|string>>
     */
    private static function noticesOf(array $plan, string $code): array
    {
        $params = [];
        foreach ($plan['notices'] as $notice) {
            if ($notice['code'] === $code) {
                $params[] = $notice['params'];
            }
        }

        return $params;
    }
}
