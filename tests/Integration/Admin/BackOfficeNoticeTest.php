<?php
declare(strict_types=1);

namespace Tests\Integration\Admin;

use PrestaShop\Module\SmailyForPrestaShop\Admin\BackOfficeNotice;
use PrestaShop\Module\SmailyForPrestaShop\Config\MigrationNotices;
use PrestaShop\Module\SmailyForPrestaShop\Hook\Dispatcher;
use PrestaShop\Module\SmailyForPrestaShop\Install\Schema;
use PrestaShop\Module\SmailyForPrestaShop\Migration\MigrationPlan;
use Tests\Integration\IntegrationTestCase;

/**
 * The other back-office pages carry one line while the upgrade is not finished or has left
 * notices, and none once the notices are dismissed; the line also comes while the schema is not
 * current, when no other listener runs.
 */
final class BackOfficeNoticeTest extends IntegrationTestCase
{
    private ?\Employee $employee = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->employee = \Context::getContext()->employee;
        \Context::getContext()->employee = new \Employee(1);
        if (!\Context::getContext()->link) {
            \Context::getContext()->link = new \Link();
        }
        \Configuration::deleteByName(MigrationNotices::KEY);
    }

    protected function tearDown(): void
    {
        if (defined('_PS_ROOT_DIR_')) {
            \Configuration::deleteByName(MigrationNotices::KEY);
            Schema::markCurrent();
            \Context::getContext()->employee = $this->employee;
        }
        parent::tearDown();
    }

    public function testNothingIsShownWhileTheUpgradeIsDoneAndLeftNoNotice(): void
    {
        self::assertNull(Dispatcher::dispatch('displayBackOfficeHeader'));
    }

    public function testANoticeShowsALineUntilItIsDismissed(): void
    {
        $notices = new MigrationNotices();
        $notices->add(MigrationPlan::NOTICE_SETTINGS_MIGRATED);

        self::assertStringContainsString('the upgrade left notices for you', (string) Dispatcher::dispatch('displayBackOfficeHeader'));

        $notices->dismiss($notices->all()[0]['id']);
        self::assertNull((new BackOfficeNotice($notices))([]));
    }

    public function testAnUnfinishedUpgradeShowsALineWhileTheRuntimeIsInert(): void
    {
        \Configuration::deleteByName(Schema::VERSION_KEY);
        self::assertFalse(Schema::isCurrent());

        self::assertStringContainsString('the upgrade to version 3.0.0 is not finished', (string) Dispatcher::dispatch('displayBackOfficeHeader'));
    }

    public function testNothingIsShownWithoutAnEmployee(): void
    {
        (new MigrationNotices())->add(MigrationPlan::NOTICE_SETTINGS_MIGRATED);
        \Context::getContext()->employee = null;

        self::assertNull((new BackOfficeNotice(new MigrationNotices()))([]));
    }
}
