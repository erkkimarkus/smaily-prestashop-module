<?php
declare(strict_types=1);

namespace Tests\Unit\Migration;

use PHPUnit\Framework\TestCase;
use PrestaShop\Module\SmailyForPrestaShop\Migration\LegacyConfigMapper;
use PrestaShop\Module\SmailyForPrestaShop\Migration\MigrationPlan;

require_once __DIR__ . '/LegacyFixture.php';

/**
 * Each legacy fixture maps to the plan pinned here. The plaintext password is checked apart from the
 * pinned array, which never holds it, so a failing comparison cannot print it.
 */
final class LegacyConfigMapperFixturesTest extends TestCase
{
    private const LEGACY_KEYS_TO_DELETE = [
        'SMAILY_SUBDOMAIN',
        'SMAILY_USERNAME',
        'SMAILY_PASSWORD',
        'SMAILY_ENABLE_CUSTOMER_SYNC',
        'SMAILY_ENABLE_CRON',
        'SMAILY_SYNCRONIZE_ADDITIONAL',
        'SMAILY_OPTIN_ENABLED',
        'SMAILY_OPTIN_AUTORESPONDER',
        'SMAILY_ENABLE_ABANDONED_CART',
        'SMAILY_CART_AUTORESPONDER',
        'SMAILY_ABANDONED_CART_TIME',
        'SMAILY_CART_SYNCRONIZE_ADDITIONAL',
        'SMAILY_RSS_CATEGORY_ID',
        'SMAILY_RSS_LIMIT',
        'SMAILY_RSS_SORT_BY',
        'SMAILY_RSS_SORT_ORDER',
    ];

    public function testA220StoreRightAfterInstallMapsToANewSetupWithEverythingOff(): void
    {
        $plan = (new LegacyConfigMapper())->map(LegacyFixture::state('2.2.0-fresh-install.sql', '2.2.0'));

        self::assertSame([
            'settings' => [
                0 => [
                    'SMAILY_CONNECT_CART_DELAY_MINUTES' => '15',
                    'SMAILY_CONNECT_CART_ENABLED' => '0',
                    'SMAILY_CONNECT_CART_FIELDS' => '',
                    'SMAILY_CONNECT_CHECKOUT_CHECKBOX' => '0',
                    'SMAILY_CONNECT_CONTACTS_CONFIRMED' => '0',
                    'SMAILY_CONNECT_CONTACTS_ENABLED' => '0',
                    'SMAILY_CONNECT_CONTACTS_FIELDS' => 'store',
                    'SMAILY_CONNECT_CONTACTS_GUESTS' => '1',
                    'SMAILY_CONNECT_CONTACTS_MODE' => 'subscribers',
                    'SMAILY_CONNECT_FIRST_ORDER_ENABLED' => '0',
                    'SMAILY_CONNECT_LANGUAGE_MODE' => 'single',
                    'SMAILY_CONNECT_RSS_LIMIT' => '50',
                    'SMAILY_CONNECT_RSS_SORT_BY' => 'date_upd',
                    'SMAILY_CONNECT_RSS_SORT_ORDER' => 'desc',
                    'SMAILY_CONNECT_SEPARATE_STORES' => '0',
                    'SMAILY_CONNECT_SETUP_STATE' => 'new',
                    'SMAILY_CONNECT_WELCOME_ENABLED' => '0',
                ],
            ],
            'accounts' => [],
            'mappings' => [],
            'tokens_to_generate' => [],
            'copy_sent_carts' => false,
            'legacy_keys_to_delete' => self::LEGACY_KEYS_TO_DELETE,
            'notices' => [],
        ], $plan->toArray());
    }

    public function testAConfigured220StoreKeepsEveryFeatureItSent(): void
    {
        $plan = (new LegacyConfigMapper())->map(LegacyFixture::state('2.2.0-configured.sql', '2.2.0'));

        self::assertSame([
            'settings' => [
                0 => [
                    'SMAILY_CONNECT_CART_DELAY_MINUTES' => '45',
                    'SMAILY_CONNECT_CART_ENABLED' => '1',
                    'SMAILY_CONNECT_CART_FIELDS' => 'first_name,last_name,name,description,sku,price,base_price,quantity',
                    'SMAILY_CONNECT_CHECKOUT_CHECKBOX' => '0',
                    'SMAILY_CONNECT_CONTACTS_CONFIRMED' => '1',
                    'SMAILY_CONNECT_CONTACTS_ENABLED' => '1',
                    'SMAILY_CONNECT_CONTACTS_FIELDS' => 'store,first_name,last_name,birthday,website',
                    'SMAILY_CONNECT_CONTACTS_GUESTS' => '1',
                    'SMAILY_CONNECT_CONTACTS_MODE' => 'subscribers',
                    'SMAILY_CONNECT_FIRST_ORDER_ENABLED' => '0',
                    'SMAILY_CONNECT_LANGUAGE_MODE' => 'single',
                    'SMAILY_CONNECT_RSS_CATEGORY' => '3',
                    'SMAILY_CONNECT_RSS_LIMIT' => '100',
                    'SMAILY_CONNECT_RSS_SORT_BY' => 'price',
                    'SMAILY_CONNECT_RSS_SORT_ORDER' => 'asc',
                    'SMAILY_CONNECT_SEPARATE_STORES' => '0',
                    'SMAILY_CONNECT_SETUP_STATE' => 'upgraded',
                    'SMAILY_CONNECT_WELCOME_ENABLED' => '1',
                ],
            ],
            'accounts' => [
                ['id_shop' => 0, 'language' => '', 'subdomain' => 'example-store', 'username' => 'api-user'],
            ],
            'mappings' => [
                LegacyFixture::mapping(0, 'welcome', 101),
                LegacyFixture::mapping(0, 'abandoned_cart', 102),
            ],
            'tokens_to_generate' => [],
            'copy_sent_carts' => true,
            'legacy_keys_to_delete' => self::LEGACY_KEYS_TO_DELETE,
            'notices' => [
                ['code' => MigrationPlan::NOTICE_SETTINGS_MIGRATED, 'params' => []],
                ['code' => MigrationPlan::NOTICE_EMAILSUBSCRIPTION_EMAILS_CHANGED, 'params' => []],
            ],
        ], $plan->toArray());
        self::assertTrue($plan->passwordToEncrypt(0) === 'not-a-real-password', 'the account carries the legacy password');
    }

    public function testA162StoreUnder162CodeKeepsEveryFeatureItSent(): void
    {
        $plan = (new LegacyConfigMapper())->map(LegacyFixture::state('1.6.2-configured.sql', '1.6.2'));

        self::assertSame([
            'settings' => [
                0 => [
                    'SMAILY_CONNECT_CART_DELAY_MINUTES' => '45',
                    'SMAILY_CONNECT_CART_ENABLED' => '1',
                    'SMAILY_CONNECT_CART_FIELDS' => 'first_name,last_name,name,description,sku,price,base_price,quantity',
                    'SMAILY_CONNECT_CHECKOUT_CHECKBOX' => '0',
                    'SMAILY_CONNECT_CONTACTS_CONFIRMED' => '1',
                    'SMAILY_CONNECT_CONTACTS_ENABLED' => '1',
                    'SMAILY_CONNECT_CONTACTS_FIELDS' => 'store,first_name,last_name,birthday,website',
                    'SMAILY_CONNECT_CONTACTS_GUESTS' => '1',
                    'SMAILY_CONNECT_CONTACTS_MODE' => 'subscribers',
                    'SMAILY_CONNECT_FIRST_ORDER_ENABLED' => '0',
                    'SMAILY_CONNECT_LANGUAGE_MODE' => 'single',
                    'SMAILY_CONNECT_RSS_CATEGORY' => '3',
                    'SMAILY_CONNECT_RSS_LIMIT' => '100',
                    'SMAILY_CONNECT_RSS_SORT_BY' => 'price',
                    'SMAILY_CONNECT_RSS_SORT_ORDER' => 'asc',
                    'SMAILY_CONNECT_SEPARATE_STORES' => '0',
                    'SMAILY_CONNECT_SETUP_STATE' => 'upgraded',
                    'SMAILY_CONNECT_WELCOME_ENABLED' => '1',
                ],
            ],
            'accounts' => [
                ['id_shop' => 0, 'language' => '', 'subdomain' => 'examplestore', 'username' => 'api-user'],
            ],
            'mappings' => [
                LegacyFixture::mapping(0, 'welcome', 101),
                LegacyFixture::mapping(0, 'abandoned_cart', 102),
            ],
            'tokens_to_generate' => [],
            'copy_sent_carts' => true,
            'legacy_keys_to_delete' => self::LEGACY_KEYS_TO_DELETE,
            'notices' => [
                ['code' => MigrationPlan::NOTICE_SETTINGS_MIGRATED, 'params' => []],
                ['code' => MigrationPlan::NOTICE_NEWSLETTER_BLOCK_REMOVED, 'params' => []],
            ],
        ], $plan->toArray());
        self::assertTrue($plan->passwordToEncrypt(0) === 'not-a-real-password', 'the account carries the legacy password');
    }

    /**
     * Decision 4: 2.x code on rows that 1.6.x left sent the opt-in, ran no contact sync (it reads
     * another switch) and sent no reminder (its cart cron stops on a serialized field list).
     */
    public function testA162StoreUnder220CodeKeepsOnlyTheWelcomeOnAndSaysWhyTheRestIsOff(): void
    {
        $plan = (new LegacyConfigMapper())->map(LegacyFixture::state('1.6.2-configured.sql', '2.2.0'));
        $plan = $plan->toArray();

        self::assertSame('1', $plan['settings'][0]['SMAILY_CONNECT_WELCOME_ENABLED']);
        self::assertSame('0', $plan['settings'][0]['SMAILY_CONNECT_CONTACTS_ENABLED']);
        self::assertSame('0', $plan['settings'][0]['SMAILY_CONNECT_CONTACTS_CONFIRMED']);
        self::assertSame('0', $plan['settings'][0]['SMAILY_CONNECT_CART_ENABLED']);
        self::assertSame([
            ['code' => MigrationPlan::NOTICE_SETTINGS_MIGRATED, 'params' => []],
            [
                'code' => MigrationPlan::NOTICE_CONTACT_SYNC_LEFT_OFF,
                'params' => ['id_shop' => 1, 'reason' => MigrationPlan::REASON_SWITCH_NOT_READ],
            ],
            [
                'code' => MigrationPlan::NOTICE_ABANDONED_CART_LEFT_OFF,
                'params' => ['id_shop' => 1, 'reason' => MigrationPlan::REASON_FIELD_LIST_NOT_JSON],
            ],
        ], $plan['notices']);
        // The settings a merchant chose stay, so switching a feature on later keeps them.
        self::assertSame(
            'store,first_name,last_name,birthday,website',
            $plan['settings'][0]['SMAILY_CONNECT_CONTACTS_FIELDS']
        );
        self::assertSame([LegacyFixture::mapping(0, 'welcome', 101), LegacyFixture::mapping(0, 'abandoned_cart', 102)], $plan['mappings']);
    }
}
