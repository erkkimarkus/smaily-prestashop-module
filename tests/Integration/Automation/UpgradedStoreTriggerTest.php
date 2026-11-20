<?php
declare(strict_types=1);

namespace Tests\Integration\Automation;

use PrestaShop\Module\SmailyForPrestaShop\Automation\Router;
use PrestaShop\Module\SmailyForPrestaShop\Automation\Trigger;
use PrestaShop\Module\SmailyForPrestaShop\Automation\TriggerSender;
use PrestaShop\Module\SmailyForPrestaShop\Automation\WorkflowList;
use PrestaShop\Module\SmailyForPrestaShop\Migration\Upgrade300;
use PrestaShop\Module\SmailyForPrestaShop\Queue\Failure;
use PrestaShop\Module\SmailyForPrestaShop\Queue\Skipped;
use PrestaShop\Module\SmailyForPrestaShop\Runtime\Services;
use PrestaShop\Module\SmailyForPrestaShop\Smaily\Account;
use Tests\Integration\Fixtures\SmailyMockServer;
use Tests\Integration\IntegrationTestCase;
use Tests\Integration\Migration\LegacyStore;

require_once __DIR__ . '/../Migration/LegacyStore.php';
require_once __DIR__ . '/../Fixtures/SmailyMockServer.php';

/**
 * A store that 2.x or 1.6.x left starts the same workflows after the upgrade: the mapping rows the
 * upgrade seeds resolve to the workflow 2.x sent, or to the generic opt-in trigger where 2.x had no
 * workflow, and the trigger goes to the Smaily mock through the migrated account, in 2.x's request
 * shapes, never forcing an opt-in.
 */
final class UpgradedStoreTriggerTest extends IntegrationTestCase
{
    private const ADDRESS = 'shopper1@example.test';

    private SmailyMockServer $mock;

    private Services $services;

    private int $idShop;

    protected function setUp(): void
    {
        parent::setUp();
        LegacyStore::clear();
        $this->mock = SmailyMockServer::start();
        $this->mock->acceptCredentials('api-user', 'not-a-real-password');
        $this->idShop = (int) \Configuration::get('PS_SHOP_DEFAULT');
    }

    protected function tearDown(): void
    {
        if (defined('_PS_ROOT_DIR_')) {
            LegacyStore::restore();
            $this->recordModuleVersion('3.0.0');
        }
        parent::tearDown();
    }

    /**
     * Acceptance B and C: the configured 2.2.0 store.
     */
    public function testA220StoreTriggersItsWelcomeAndCartWorkflowsThroughItsAccount(): void
    {
        $this->upgrade('2.2.0-configured.sql', '2.2.0');

        $welcome = $this->router()->resolve(Trigger::WELCOME, $this->idShop, 'en');
        self::assertNotNull($welcome);
        self::assertSame([101, 'example-store', 'default'], [$welcome->workflowId(), $welcome->account()->subdomain(), $welcome->accountKey()]);
        self::assertSame(102, $this->router()->resolve(Trigger::ABANDONED_CART, $this->idShop, 'en')->workflowId());

        self::assertTrue($this->sender()->send(Trigger::WELCOME, $this->idShop, 'en', self::ADDRESS, []));
        self::assertTrue($this->sender()->send(Trigger::ABANDONED_CART, $this->idShop, 'en', self::ADDRESS, ['is_abandoned_cart' => 'true', 'force_opt_in' => false]));

        $requests = $this->mock->requests();
        self::assertCount(2, $requests);
        foreach ($requests as $request) {
            self::assertSame(['POST', '/api/autoresponder.php', true, 'api-user'], [$request['method'], $request['path'], $request['authorized'], $request['username']]);
        }
        self::assertSame(['autoresponder' => 101, 'addresses' => [['email' => self::ADDRESS]], 'force_opt_in' => false], $requests[0]['body']);
        self::assertSame(
            ['autoresponder' => 102, 'addresses' => [['email' => self::ADDRESS, 'is_abandoned_cart' => 'true', 'force_opt_in' => false]], 'force_opt_in' => false],
            $requests[1]['body']
        );
    }

    /**
     * Acceptance B and C, decision 5: a 1.6.x store whose opt-in had no workflow ("any") keeps the
     * generic opt-in trigger.
     */
    public function testA162StoreWithoutAnOptInWorkflowKeepsTheGenericOptIn(): void
    {
        LegacyStore::loadFixture('1.6.2-configured.sql');
        self::db()->execute('UPDATE `' . _DB_PREFIX_ . 'configuration` SET `value` = \'\' WHERE `name` = \'SMAILY_OPTIN_AUTORESPONDER\'');
        $this->upgrade(null, '1.6.2');

        $welcome = $this->router()->resolve(Trigger::WELCOME, $this->idShop, 'en');
        self::assertNotNull($welcome);
        self::assertTrue($welcome->isOptInTrigger());
        self::assertSame('examplestore', $welcome->account()->subdomain());

        self::assertTrue($this->sender()->send(Trigger::WELCOME, $this->idShop, 'en', self::ADDRESS, []));

        self::assertSame(['addresses' => [['email' => self::ADDRESS]], 'force_opt_in' => false], $this->mock->requests()[0]['body']);
        self::assertSame('api-user', $this->mock->requests()[0]['username']);
    }

    public function testANoMatchEndsSkippedWithItsReasonAndSendsNothing(): void
    {
        $this->upgrade('2.2.0-configured.sql', '2.2.0');

        self::assertEquals(new Skipped(Router::SKIPPED_AUTOMATION_OFF), $this->sender()->send(Trigger::FIRST_ORDER, $this->idShop, 'en', self::ADDRESS, []));

        self::db()->delete('smaily_connect_automation_mapping', '`trigger` = \'welcome\'');
        self::assertEquals(new Skipped(Router::SKIPPED_NO_WORKFLOW), $this->sender()->send(Trigger::WELCOME, $this->idShop, 'en', self::ADDRESS, []));

        self::db()->delete('smaily_connect_account');
        self::assertEquals(new Skipped(Router::SKIPPED_NO_ACCOUNT), $this->sender()->send(Trigger::ABANDONED_CART, $this->idShop, 'en', self::ADDRESS, []));

        self::assertSame([], $this->mock->requests());
    }

    /**
     * Acceptance D: the workflows come from the account that is asked, with Magento's call, and a
     * failure is a code. A workflow with another trigger or switched off is not offered.
     */
    public function testListsTheWorkflowsOfTheAccountThatIsAsked(): void
    {
        $this->upgrade('2.2.0-configured.sql', '2.2.0');
        $this->mock->setWorkflows([
            ['id' => 101, 'title' => 'Welcome', 'trigger_type' => 'form_submitted', 'is_enabled' => true],
            ['id' => 102, 'title' => 'Abandoned cart', 'trigger_type' => 'form_submitted', 'is_enabled' => true],
            ['id' => 103, 'title' => 'Switched off', 'trigger_type' => 'form_submitted', 'is_enabled' => false],
            ['id' => 104, 'title' => 'Another trigger', 'trigger_type' => 'subscriber_joined', 'is_enabled' => true],
        ]);
        $account = Services::fromProviders(Services::PROVIDERS)->get(Router::class)->accountFor($this->idShop, 'default');

        self::assertSame(
            ['workflows' => [['id' => 101, 'name' => 'Welcome'], ['id' => 102, 'name' => 'Abandoned cart']], 'error' => null],
            (new WorkflowList($this->mock->transport()))->of($account)
        );
        self::assertSame(['GET', '/api/workflows.php', ['trigger_type' => 'form_submitted'], 'api-user'], [
            $this->mock->requests()[0]['method'],
            $this->mock->requests()[0]['path'],
            $this->mock->requests()[0]['query'],
            $this->mock->requests()[0]['username'],
        ]);

        self::assertSame(
            ['workflows' => [], 'error' => WorkflowList::ERROR_AUTHENTICATION],
            (new WorkflowList($this->mock->transport()))->of(new Account(2, '', 'other-store', 'other-api-user', 'not-a-real-password-either'))
        );
    }

    /**
     * Acceptance E: a failed or skipped trigger leaves no line in the shop's log and no outcome that
     * names the contact.
     */
    public function testNoLogLineOrOutcomeNamesTheContact(): void
    {
        $this->upgrade('2.2.0-configured.sql', '2.2.0');
        $logs = (int) self::db()->getValue('SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'log`', false);

        $failed = $this->sender()->send(Trigger::WELCOME, $this->idShop, 'en', '', ['first_name' => 'Testfirst']);
        $this->mock->acceptCredentials('api-user', 'another-fake-password');
        $refused = $this->sender()->send(Trigger::WELCOME, $this->idShop, 'en', self::ADDRESS, ['first_name' => 'Testfirst']);
        $skipped = $this->sender()->send(Trigger::FIRST_ORDER, $this->idShop, 'en', self::ADDRESS, ['first_name' => 'Testfirst']);

        self::assertInstanceOf(Failure::class, $failed);
        self::assertInstanceOf(Failure::class, $refused);
        self::assertInstanceOf(Skipped::class, $skipped);
        foreach ([$failed, $refused, $skipped] as $outcome) {
            foreach (['shopper1', 'example.test', 'Testfirst', 'not-a-real-password'] as $quoted) {
                self::assertStringNotContainsString($quoted, $outcome->reason());
            }
        }
        self::assertSame(0, (int) self::db()->getValue(
            'SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'log` WHERE `message` LIKE \'%shopper1%\' OR `message` LIKE \'%Testfirst%\'',
            false
        ));
        self::assertSame($logs, (int) self::db()->getValue('SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'log`', false), 'the sender writes no log line');
    }

    private function upgrade(?string $fixture, string $from): void
    {
        if ($fixture !== null) {
            LegacyStore::loadFixture($fixture);
        }
        $this->recordModuleVersion($from);
        self::assertTrue(Services::fromProviders(Services::PROVIDERS)->get(Upgrade300::class)->runPending());
    }

    private function router(): Router
    {
        return Services::fromProviders(Services::PROVIDERS)->get(Router::class);
    }

    private function sender(): TriggerSender
    {
        return new TriggerSender($this->router(), $this->mock->transport());
    }

    private function recordModuleVersion(string $version): void
    {
        self::db()->execute(
            'UPDATE `' . _DB_PREFIX_ . 'module` SET `version` = \'' . pSQL($version) . '\' WHERE `name` = \'smailyforprestashop\''
        );
    }
}
