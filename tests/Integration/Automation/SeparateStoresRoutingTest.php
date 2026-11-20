<?php
declare(strict_types=1);

namespace Tests\Integration\Automation;

use PrestaShop\Module\SmailyForPrestaShop\Automation\MappingRepository;
use PrestaShop\Module\SmailyForPrestaShop\Automation\Router;
use PrestaShop\Module\SmailyForPrestaShop\Automation\Trigger;
use PrestaShop\Module\SmailyForPrestaShop\Automation\TriggerSender;
use PrestaShop\Module\SmailyForPrestaShop\Config\AutomationSettings;
use PrestaShop\Module\SmailyForPrestaShop\Queue\Skipped;
use PrestaShop\Module\SmailyForPrestaShop\Runtime\Services;
use PrestaShop\Module\SmailyForPrestaShop\Scope\StoreLayout;
use PrestaShop\Module\SmailyForPrestaShop\Smaily\Account;
use PrestaShop\Module\SmailyForPrestaShop\Smaily\AccountStore;
use Tests\Integration\Fixtures\SmailyMockServer;
use Tests\Integration\IntegrationTestCase;
use Tests\Integration\Migration\LegacyStore;
use Tests\Integration\MultistoreShop;

require_once __DIR__ . '/../Migration/LegacyStore.php';
require_once __DIR__ . '/../MultistoreShop.php';
require_once __DIR__ . '/../Fixtures/SmailyMockServer.php';

/**
 * Acceptance A on a real two-shop store: in separate stores each shop's automation goes through its
 * own account and its own workflow, read from the shop's settings, the mapping table and the account
 * table, and never through another shop's or the shared ones.
 */
final class SeparateStoresRoutingTest extends IntegrationTestCase
{
    use MultistoreShop;

    private int $shopA;

    private int $shopB;

    protected function setUp(): void
    {
        parent::setUp();
        LegacyStore::clear();
        LegacyStore::reloadConfiguration();
        $this->openMultistore();
        $this->shopA = $this->defaultShopId();
        $this->shopB = $this->addShop((int) \Shop::getGroupFromShop($this->shopA));

        \Configuration::updateGlobalValue(StoreLayout::SEPARATE_STORES, '1');
        $settings = new AutomationSettings();
        $accounts = Services::instance()->get(AccountStore::class);
        $mappings = new MappingRepository();
        $accounts->save(new Account(0, '', 'leftover', 'leftover-user', 'not-a-real-password'));
        $mappings->upsert(0, Trigger::WELCOME, '', 'default', 999);
        foreach (['a' => $this->shopA, 'b' => $this->shopB] as $name => $idShop) {
            $settings->setEnabled(Trigger::WELCOME, true, $idShop);
            $accounts->save(new Account($idShop, '', 'shop' . $name, 'user-' . $name, 'not-a-real-password'));
            $mappings->upsert($idShop, Trigger::WELCOME, '', 'default', 100 * $idShop + 1);
        }
        // Shop B branches on language; shop A keeps one workflow.
        \Configuration::updateValue(StoreLayout::LANGUAGE_MODE, StoreLayout::MODE_WORKFLOW_PER_LANGUAGE, false, (int) \Shop::getGroupFromShop($this->shopB), $this->shopB);
        $mappings->upsert($this->shopB, Trigger::WELCOME, 'et', 'default', 100 * $this->shopB + 2);
        LegacyStore::reloadConfiguration();
    }

    protected function tearDown(): void
    {
        if (defined('_PS_ROOT_DIR_')) {
            self::db()->delete('configuration', '`id_shop` = ' . (int) $this->shopB . ' AND `name` LIKE \'SMAILY\\\\_%\'');
            $this->closeMultistore();
            LegacyStore::restore();
        }
        parent::tearDown();
    }

    public function testEachShopsAutomationGoesThroughItsOwnAccountAndWorkflow(): void
    {
        $a = $this->router()->resolve(Trigger::WELCOME, $this->shopA, 'et');
        $b = $this->router()->resolve(Trigger::WELCOME, $this->shopB, 'et');
        $bOther = $this->router()->resolve(Trigger::WELCOME, $this->shopB, 'fi');

        self::assertSame([100 * $this->shopA + 1, 'shopa'], [$a->workflowId(), $a->account()->subdomain()]);
        self::assertSame([100 * $this->shopB + 2, 'shopb'], [$b->workflowId(), $b->account()->subdomain()]);
        self::assertSame([100 * $this->shopB + 1, 'shopb'], [$bOther->workflowId(), $bOther->account()->subdomain()]);

        $mock = SmailyMockServer::start();
        $mock->acceptCredentials('user-b', 'not-a-real-password');
        self::assertTrue((new TriggerSender($this->router(), $mock->transport()))->send(Trigger::WELCOME, $this->shopB, 'et', 'shopper1@example.test', []));
        self::assertSame('user-b', $mock->requests()[0]['username']);
        self::assertSame(100 * $this->shopB + 2, $mock->requests()[0]['body']['autoresponder']);
    }

    public function testAShopWithoutItsOwnRowsOrAccountNeverUsesAnotherShopsOrTheShared(): void
    {
        self::db()->delete('smaily_connect_automation_mapping', '`id_shop` = ' . $this->shopB);
        self::assertEquals(new Skipped(Router::SKIPPED_NO_WORKFLOW), $this->router()->route(Trigger::WELCOME, $this->shopB, 'et'));

        (new MappingRepository())->upsert($this->shopB, Trigger::WELCOME, '', 'default', 100 * $this->shopB + 1);
        self::db()->delete('smaily_connect_account', '`id_shop` = ' . $this->shopB);
        self::assertEquals(new Skipped(Router::SKIPPED_NO_ACCOUNT), $this->router()->route(Trigger::WELCOME, $this->shopB, 'et'));

        self::assertSame('shopa', $this->router()->resolve(Trigger::WELCOME, $this->shopA, 'et')->account()->subdomain());
    }

    public function testAShopWhoseAutomationIsOffSendsNothing(): void
    {
        (new AutomationSettings())->setEnabled(Trigger::WELCOME, false, $this->shopB);

        self::assertEquals(new Skipped(Router::SKIPPED_AUTOMATION_OFF), $this->router()->route(Trigger::WELCOME, $this->shopB, 'et'));
        self::assertNotNull($this->router()->resolve(Trigger::WELCOME, $this->shopA, 'et'));
    }

    private function router(): Router
    {
        return Services::fromProviders(Services::PROVIDERS)->get(Router::class);
    }
}
