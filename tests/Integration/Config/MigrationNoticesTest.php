<?php
declare(strict_types=1);

namespace Tests\Integration\Config;

use PrestaShop\Module\SmailyForPrestaShop\Config\MigrationNotices;
use Tests\Integration\IntegrationTestCase;

/**
 * The notices that the migration leaves for the admin are data: a stable code and its parameters,
 * stored in the shop, listed in the order they came, and gone once dismissed. The admin owns the
 * wording.
 */
final class MigrationNoticesTest extends IntegrationTestCase
{
    private ?string $saved = null;

    protected function setUp(): void
    {
        parent::setUp();
        $saved = \Configuration::getGlobalValue(MigrationNotices::KEY);
        $this->saved = $saved === false ? null : (string) $saved;
        \Configuration::deleteByName(MigrationNotices::KEY);
    }

    protected function tearDown(): void
    {
        if (defined('_PS_ROOT_DIR_')) {
            \Configuration::deleteByName(MigrationNotices::KEY);
            if ($this->saved !== null) {
                \Configuration::updateGlobalValue(MigrationNotices::KEY, $this->saved);
            }
        }
        parent::tearDown();
    }

    public function testAShopWithoutNoticesListsNone(): void
    {
        self::assertSame([], (new MigrationNotices())->all());
    }

    public function testNoticesPersistInTheOrderTheyCame(): void
    {
        $notices = new MigrationNotices();
        $notices->add('settings_migrated');
        $notices->add('cron_token_replaced', ['key' => 'SMAILY_CART_CRON_TOKEN', 'id_shop' => 0]);

        \Configuration::resetStaticCache();
        \Configuration::loadConfiguration();
        $listed = (new MigrationNotices())->all();

        self::assertSame(['settings_migrated', 'cron_token_replaced'], array_column($listed, 'code'));
        self::assertSame([[], ['key' => 'SMAILY_CART_CRON_TOKEN', 'id_shop' => 0]], array_column($listed, 'params'));
    }

    public function testTheSameNoticeIsStoredOnce(): void
    {
        $notices = new MigrationNotices();
        $notices->add('welcome_left_off', ['id_shop' => 2, 'reason' => 'no_account']);
        $notices->add('welcome_left_off', ['id_shop' => 2, 'reason' => 'no_account']);
        $notices->add('welcome_left_off', ['id_shop' => 3, 'reason' => 'no_account']);

        self::assertCount(2, $notices->all());
    }

    public function testADismissedNoticeIsNoLongerListed(): void
    {
        $notices = new MigrationNotices();
        $notices->add('settings_migrated');
        $notices->add('newsletter_block_removed');

        $notices->dismiss($notices->all()[0]['id']);

        self::assertSame(['newsletter_block_removed'], array_column($notices->all(), 'code'));
    }
}
