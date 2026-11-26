<?php
declare(strict_types=1);

namespace Tests\Integration\Automation;

use PrestaShop\Module\SmailyForPrestaShop\Admin\Dashboard\EntityLabel;
use PrestaShop\Module\SmailyForPrestaShop\Automation\MappingRepository;
use PrestaShop\Module\SmailyForPrestaShop\Automation\Trigger;
use PrestaShop\Module\SmailyForPrestaShop\Automation\Welcome\WelcomeTrigger;
use PrestaShop\Module\SmailyForPrestaShop\Config\AutomationSettings;
use PrestaShop\Module\SmailyForPrestaShop\Config\ContactsSettings;
use PrestaShop\Module\SmailyForPrestaShop\Contacts\Capture\ContactEvents;
use PrestaShop\Module\SmailyForPrestaShop\Contacts\ContactSyncMode;
use PrestaShop\Module\SmailyForPrestaShop\Hook\Dispatcher;
use PrestaShop\Module\SmailyForPrestaShop\Migration\Upgrade300;
use PrestaShop\Module\SmailyForPrestaShop\Http\Transport;
use PrestaShop\Module\SmailyForPrestaShop\Queue\ContactEntity;
use PrestaShop\Module\SmailyForPrestaShop\Queue\EventQueue;
use PrestaShop\Module\SmailyForPrestaShop\Runtime\Provider\QueueProvider;
use PrestaShop\Module\SmailyForPrestaShop\Runtime\Services;
use PrestaShop\Module\SmailyForPrestaShop\Scheduler\Lock;
use PrestaShop\Module\SmailyForPrestaShop\Smaily\Account;
use PrestaShop\Module\SmailyForPrestaShop\Smaily\AccountStore;
use Tests\Integration\Contacts\ContactRows;
use Tests\Integration\Fixtures\SmailyMockServer;
use Tests\Integration\IntegrationTestCase;
use Tests\Integration\Migration\LegacyStore;

require_once __DIR__ . '/../Migration/LegacyStore.php';
require_once __DIR__ . '/../Fixtures/SmailyMockServer.php';
require_once __DIR__ . '/../Contacts/ContactRows.php';

/**
 * The welcome automation follows the shopper's own newsletter consent in the storefront: the capture
 * hooks go through Hook\Dispatcher to the runtime's listeners, the request's end writes the rows and
 * flushes them to the Smaily mock. A consent that the back office writes starts no welcome, and one
 * consent gives one welcome.
 */
final class WelcomeAutomationTest extends IntegrationTestCase
{
    use ContactRows;

    private const PASSWORD = 'not-a-real-password';

    private SmailyMockServer $mock;

    private Services $services;

    /** @var list<callable> */
    private array $shutdown = [];

    private int $shop;

    private int $idLang;

    private string $language;

    protected function setUp(): void
    {
        parent::setUp();
        LegacyStore::clear();
        $this->mock = SmailyMockServer::start();
        $this->mock->acceptCredentials('welcome-user', self::PASSWORD);
        $this->shop = (int) \Configuration::get('PS_SHOP_DEFAULT');
        $this->idLang = (int) \Configuration::get('PS_LANG_DEFAULT');
        $this->language = strtolower((string) \Language::getIsoById($this->idLang));
        \Configuration::updateGlobalValue(ContactsSettings::ENABLED, '1');
        \Configuration::updateGlobalValue(ContactsSettings::CONFIRMED, '1');
        \Configuration::updateGlobalValue(ContactsSettings::MODE, ContactSyncMode::SUBSCRIBERS);
        \Configuration::updateGlobalValue(ContactsSettings::GUESTS, '1');
        \Configuration::updateGlobalValue(ContactsSettings::FIELDS, 'store,first_name,last_name');
        (new AutomationSettings())->setEnabled(Trigger::WELCOME, true, 0);
        (new MappingRepository())->upsert(0, Trigger::WELCOME, '', MappingRepository::ACCOUNT_DEFAULT, 201);
        LegacyStore::reloadConfiguration();
        $this->services = $this->services();
        self::assertTrue($this->services->get(AccountStore::class)->save(new Account(0, '', 'welcome-store', 'welcome-user', self::PASSWORD)));
        \Context::getContext()->controller = $this->storefront();
    }

    protected function tearDown(): void
    {
        if (defined('_PS_ROOT_DIR_')) {
            \Context::getContext()->controller = null;
            $this->removeContactRows();
            LegacyStore::restore();
            self::db()->execute('UPDATE `' . _DB_PREFIX_ . 'module` SET `version` = \'3.0.0\' WHERE `name` = \'smailyforprestashop\'');
        }
        parent::tearDown();
    }

    /**
     * Proof 1: a registration with the box gives one welcome through the shop's account and
     * workflow, with the marker and never forcing an opt-in; the contact row goes first.
     */
    public function testARegistrationWithTheBoxSendsOneWelcome(): void
    {
        $this->fire('actionCustomerAccountAdd', ['newCustomer' => $this->customer('welcome1@example.test', true)]);
        $this->endRequest();

        $rows = $this->rows();
        self::assertSame(['contact.sync', WelcomeTrigger::EVENT_TYPE], array_column($rows, 'event_type'));
        self::assertSame([EventQueue::STATUS_SENT, EventQueue::STATUS_SENT], array_column($rows, 'status'));
        self::assertSame([$this->shop, 'welcome1@example.test', $this->language], [(int) $rows[1]['id_shop'], $rows[1]['entity_id'], $rows[1]['language']]);

        $welcome = $this->autoresponderRequests();
        self::assertCount(1, $welcome);
        self::assertSame('welcome-user', $welcome[0]['username']);
        self::assertSame(201, $welcome[0]['body']['autoresponder']);
        self::assertFalse($welcome[0]['body']['force_opt_in']);
        $address = $welcome[0]['body']['addresses'][0];
        self::assertMatchesRegularExpression('/^\d{4}-\d\d-\d\d \d\d:\d\d:\d\d$/', $address['welcome_automation_at']);
        unset($address['welcome_automation_at']);
        self::assertSame([
            'email' => 'welcome1@example.test',
            'store' => (string) \ShopUrl::getMainShopDomain($this->shop),
            'first_name' => 'Testfirst',
            'last_name' => 'Testlast',
        ], $address);
    }

    /**
     * An address longer than the queue's entity column (ARCHITECTURE 2.2): the contact row and the
     * welcome row carry its keyed hash, which the Log shows as 12 characters, and the address
     * itself still reaches Smaily.
     */
    public function testALongAddressIsStoredUnderItsKeyedHashOnBothRows(): void
    {
        $address = 'shopper.having.a.very.long.invented.name@a-long-subdomain.example.test';
        self::assertSame(70, strlen($address));
        $this->fire('actionCustomerAccountAdd', ['newCustomer' => $this->customer(strtoupper($address), true)]);
        $this->endRequest();

        $rows = $this->rows();
        self::assertSame(['contact.sync', WelcomeTrigger::EVENT_TYPE], array_column($rows, 'event_type'));
        self::assertSame([EventQueue::STATUS_SENT, EventQueue::STATUS_SENT], array_column($rows, 'status'));
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', (string) $rows[0]['entity_id']);
        self::assertSame([ContactEntity::of($address), ContactEntity::of($address)], array_column($rows, 'entity_id'));
        self::assertSame(substr((string) $rows[0]['entity_id'], 0, 12) . '…', EntityLabel::forDisplay($rows[0]['entity_id']));
        self::assertSame($address, $this->autoresponderRequests()[0]['body']['addresses'][0]['email']);
    }

    public function testARegistrationWithoutTheBoxSendsNoWelcome(): void
    {
        $this->fire('actionCustomerAccountAdd', ['newCustomer' => $this->customer('welcome1@example.test', false)]);
        $this->endRequest();

        self::assertSame(['contact.sync'], array_column($this->rows(), 'event_type'));
        self::assertSame([], $this->autoresponderRequests());
    }

    public function testTheAccountPagesSwitchToSubscribedSendsOneWelcome(): void
    {
        $customer = $this->customer('welcome1@example.test', false);
        $this->switchConsent($customer, true);
        $this->fire('actionCustomerAccountUpdate', ['customer' => $customer]);
        $this->endRequest();

        self::assertCount(1, $this->welcomeRows(), 'the three hooks of one edit give one welcome');
    }

    /**
     * Proof 3: the back office (the customer form or the grid's switch) writes the consent; the
     * contact goes to Smaily, no welcome starts.
     */
    public function testABackOfficeSwitchToSubscribedSendsNoWelcome(): void
    {
        \Context::getContext()->controller = null;
        $customer = $this->customer('welcome1@example.test', false);
        $this->switchConsent($customer, true);
        $this->endRequest();

        self::assertSame(['contact.sync'], array_column($this->rows(), 'event_type'));
        self::assertSame([], $this->autoresponderRequests());
    }

    public function testASaveThatKeepsTheConsentSendsNoWelcome(): void
    {
        $customer = $this->customer('welcome1@example.test', true);
        $this->switchConsent($customer, true);
        $this->endRequest();

        self::assertSame([], $this->welcomeRows());
    }

    /**
     * A shopper who unsubscribed and subscribes again in the storefront gets the welcome again: one
     * welcome per storefront subscribe, as the siblings send it.
     */
    public function testAResubscribeInTheStorefrontSendsTheWelcomeAgain(): void
    {
        $customer = $this->customer('welcome1@example.test', true);
        $this->switchConsent($customer, false);
        $this->endRequest();
        $this->switchConsent($customer, true);
        $this->endRequest();

        self::assertCount(1, $this->welcomeRows());
    }

    public function testANewsletterBlockSubscribeOfAGuestSendsOneWelcome(): void
    {
        $this->addNewsletterRow($this->shop, 'welcome2@example.test', true, $this->idLang);
        $this->fire('actionNewsletterRegistrationAfter', ['email' => 'welcome2@example.test', 'action' => '0', 'module' => 'ps_emailsubscription']);
        $this->endRequest();

        $welcome = $this->autoresponderRequests();
        self::assertCount(1, $welcome);
        $address = $welcome[0]['body']['addresses'][0];
        unset($address['welcome_automation_at']);
        self::assertSame(['email' => 'welcome2@example.test', 'store' => (string) \ShopUrl::getMainShopDomain($this->shop)], $address);
    }

    /**
     * With the verification email on, the block stores an inactive row at the submit and fires the
     * hook only from the verification link (facts file 1.10): the welcome comes from the link.
     */
    public function testABlockSubscribeWaitingForItsVerificationSendsNoWelcomeUntilTheLink(): void
    {
        $row = $this->addNewsletterRow($this->shop, 'welcome2@example.test', false, $this->idLang);
        $this->fire('actionNewsletterRegistrationAfter', ['email' => 'welcome2@example.test', 'action' => '0', 'module' => 'ps_emailsubscription']);
        $this->endRequest();
        self::assertSame([], $this->welcomeRows());

        self::db()->update('emailsubscription', ['active' => 1], '`id` = ' . $row);
        $this->fire('actionNewsletterRegistrationAfter', ['email' => 'welcome2@example.test', 'action' => 0, 'module' => 'ps_emailsubscription']);
        $this->endRequest();

        self::assertCount(1, $this->welcomeRows());
    }

    public function testTheWelcomeOffSendsNone(): void
    {
        (new AutomationSettings())->setEnabled(Trigger::WELCOME, false, 0);
        LegacyStore::reloadConfiguration();

        $this->fire('actionCustomerAccountAdd', ['newCustomer' => $this->customer('welcome1@example.test', true)]);
        $this->endRequest();

        self::assertSame([], $this->welcomeRows());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public function inactiveSync(): iterable
    {
        yield 'contact sync off' => [ContactsSettings::ENABLED];
        yield 'contacts not confirmed' => [ContactsSettings::CONFIRMED];
    }

    /**
     * The welcome follows the contact sync (owner, 2026-11-26; Linear CON-452): where the shop's
     * contact sync is not active, a storefront subscribe, of an account or in the newsletter block,
     * queues and sends nothing.
     *
     * @dataProvider inactiveSync
     */
    public function testTheWelcomeWaitsForTheContactSync(string $key): void
    {
        \Configuration::updateGlobalValue($key, '0');
        LegacyStore::reloadConfiguration();

        $this->fire('actionCustomerAccountAdd', ['newCustomer' => $this->customer('welcome1@example.test', true)]);
        $this->endRequest();
        $this->addNewsletterRow($this->shop, 'welcome2@example.test', true, $this->idLang);
        $this->fire('actionNewsletterRegistrationAfter', ['email' => 'welcome2@example.test', 'action' => '0', 'module' => 'ps_emailsubscription']);
        $this->endRequest();

        self::assertSame([], $this->rows());
        self::assertSame([], $this->autoresponderRequests());
    }

    /**
     * @return iterable<string, array{string, bool, bool, bool, int}>
     */
    public function audienceCases(): iterable
    {
        yield 'checkout opt-in only, a registration' => [ContactSyncMode::CHECKOUT_OPTIN, true, false, false, 0];
        yield 'checkout opt-in only, a registration at the order step' => [ContactSyncMode::CHECKOUT_OPTIN, true, true, false, 1];
        yield 'all customers, a registration' => [ContactSyncMode::ALL_CUSTOMERS, true, false, false, 1];
        yield 'subscribers only, a guest at the order step, include guests off' => [ContactSyncMode::SUBSCRIBERS, false, true, true, 1];
        yield 'subscribers only, a guest at the order step, include guests on' => [ContactSyncMode::SUBSCRIBERS, true, true, true, 1];
        yield 'all customers, a guest at the order step, include guests off' => [ContactSyncMode::ALL_CUSTOMERS, false, true, true, 1];
    }

    /**
     * The welcome goes to whom the shop's consent mode sends as a contact (ContactAudience), as the
     * siblings' welcome does: a guest who ticks the box at the order step in every mode, whatever
     * "include guests" says (owner, 2026-11-26).
     *
     * @dataProvider audienceCases
     */
    public function testTheWelcomeFollowsTheConsentModesAudience(string $mode, bool $includeGuests, bool $atOrderStep, bool $guest, int $welcomes): void
    {
        \Configuration::updateGlobalValue(ContactsSettings::MODE, $mode);
        \Configuration::updateGlobalValue(ContactsSettings::GUESTS, $includeGuests ? '1' : '0');
        LegacyStore::reloadConfiguration();
        if ($atOrderStep) {
            $controller = (new \ReflectionClass(\OrderController::class))->newInstanceWithoutConstructor();
            $controller->php_self = 'order';
            \Context::getContext()->controller = $controller;
        }

        $this->fire('actionCustomerAccountAdd', ['newCustomer' => $this->customer('welcome1@example.test', true, ['is_guest' => (int) $guest])]);
        $this->endRequest();

        self::assertCount($welcomes, $this->welcomeRows());
    }

    /**
     * @return iterable<string, array{string, bool, int}>
     */
    public function blockCases(): iterable
    {
        yield 'subscribers only, include guests off' => [ContactSyncMode::SUBSCRIBERS, false, 1];
        yield 'all customers, include guests off' => [ContactSyncMode::ALL_CUSTOMERS, false, 1];
        yield 'checkout opt-in only, include guests off' => [ContactSyncMode::CHECKOUT_OPTIN, false, 0];
        yield 'checkout opt-in only, include guests on' => [ContactSyncMode::CHECKOUT_OPTIN, true, 0];
    }

    /**
     * A newsletter-block subscriber without an account is welcomed where the mode syncs one,
     * whatever "include guests" says (owner, 2026-11-21, as Magento welcomes a newsletter-form
     * signup whatever its guest setting).
     *
     * @dataProvider blockCases
     */
    public function testABlockSubscribersWelcomeDoesNotDependOnIncludeGuests(string $mode, bool $includeGuests, int $welcomes): void
    {
        \Configuration::updateGlobalValue(ContactsSettings::MODE, $mode);
        \Configuration::updateGlobalValue(ContactsSettings::GUESTS, $includeGuests ? '1' : '0');
        LegacyStore::reloadConfiguration();

        $this->addNewsletterRow($this->shop, 'welcome2@example.test', true, $this->idLang);
        $this->fire('actionNewsletterRegistrationAfter', ['email' => 'welcome2@example.test', 'action' => '0', 'module' => 'ps_emailsubscription']);
        $this->endRequest();

        self::assertCount($welcomes, $this->welcomeRows());
        self::assertCount($welcomes, $this->autoresponderRequests());
    }

    /**
     * Proof 4: a store upgraded from 2.2.0 welcomes a storefront registration through the workflow
     * and the account that 2.x used.
     */
    public function testAStoreUpgradedFrom220WelcomesThroughItsMigratedWorkflow(): void
    {
        $this->upgrade('2.2.0-configured.sql', '2.2.0');

        $this->fire('actionCustomerAccountAdd', ['newCustomer' => $this->customer('welcome1@example.test', true)]);
        $this->endRequest();

        $welcome = $this->autoresponderRequests();
        self::assertCount(1, $welcome);
        self::assertSame(['api-user', 101, false], [$welcome[0]['username'], $welcome[0]['body']['autoresponder'], $welcome[0]['body']['force_opt_in']]);
        self::assertSame('welcome1@example.test', $welcome[0]['body']['addresses'][0]['email']);
        self::assertArrayHasKey('welcome_automation_at', $welcome[0]['body']['addresses'][0]);
    }

    /**
     * Proof 4, decision 5: a 1.6.x store whose opt-in had no workflow keeps Smaily's generic opt-in.
     */
    public function testAStoreUpgradedWithoutAnOptInWorkflowSendsTheGenericOptIn(): void
    {
        LegacyStore::clear();
        LegacyStore::loadFixture('1.6.2-configured.sql');
        self::db()->execute('UPDATE `' . _DB_PREFIX_ . 'configuration` SET `value` = \'\' WHERE `name` = \'SMAILY_OPTIN_AUTORESPONDER\'');
        $this->upgrade(null, '1.6.2');

        $this->addNewsletterRow($this->shop, 'welcome2@example.test', true, $this->idLang);
        $this->fire('actionNewsletterRegistrationAfter', ['email' => 'welcome2@example.test', 'action' => '0', 'module' => 'ps_emailsubscription']);
        $this->endRequest();

        $welcome = $this->autoresponderRequests();
        self::assertCount(1, $welcome);
        self::assertSame('api-user', $welcome[0]['username']);
        self::assertArrayNotHasKey('autoresponder', $welcome[0]['body']);
        self::assertFalse($welcome[0]['body']['force_opt_in']);
        self::assertSame('welcome2@example.test', $welcome[0]['body']['addresses'][0]['email']);
    }

    /**
     * A failed welcome leaves no line in the shop's log and no `last_error` that names the contact.
     */
    public function testAFailedWelcomeNamesNoContact(): void
    {
        $this->mock->acceptCredentials('welcome-user', 'another-fake-password');
        $logs = (int) self::db()->getValue('SELECT MAX(`id_log`) FROM `' . _DB_PREFIX_ . 'log`', false);

        $this->fire('actionCustomerAccountAdd', ['newCustomer' => $this->customer('welcome1@example.test', true)]);
        $this->endRequest();

        $errors = array_column($this->rows(), 'last_error');
        self::assertNotSame([null, null], $errors);
        foreach ($errors as $error) {
            self::assertStringNotContainsString('welcome1', (string) $error);
        }
        self::assertSame(0, (int) self::db()->getValue(
            'SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'log` WHERE `id_log` > ' . $logs . ' AND (`message` LIKE \'%welcome1%\' OR `message` LIKE \'%Testfirst%\')',
            false
        ));
    }

    /**
     * Upgrades the store from a legacy fixture, as Upgrade300 does, with the mock taking the
     * fixture's API user.
     */
    private function upgrade(?string $fixture, string $from): void
    {
        if ($fixture !== null) {
            LegacyStore::clear();
            LegacyStore::loadFixture($fixture);
        }
        self::db()->execute('UPDATE `' . _DB_PREFIX_ . 'module` SET `version` = \'' . pSQL($from) . '\' WHERE `name` = \'smailyforprestashop\'');
        self::assertTrue(Services::fromProviders(Services::PROVIDERS)->get(Upgrade300::class)->runPending());
        LegacyStore::reloadConfiguration();
        $this->mock->acceptCredentials('api-user', self::PASSWORD);
        $this->services = $this->services();
    }

    private function services(): Services
    {
        $services = Services::fromProviders(Services::PROVIDERS);
        $services->set(Transport::class, fn (): Transport => $this->mock->transport());
        $services->set(ContactEvents::class, function (Services $services): ContactEvents {
            return new ContactEvents(
                $services->get(QueueProvider::EVENT_QUEUE),
                $services->get(ContactsSettings::class),
                new Lock(),
                static fn () => $services->get(QueueProvider::FLUSHER),
                static function (): void {
                },
                function (callable $function): void {
                    $this->shutdown[] = $function;
                }
            );
        });

        return $services;
    }

    /**
     * @param array<string, mixed> $params
     */
    private function fire(string $hook, array $params): void
    {
        (new Dispatcher(static fn (): bool => true, fn (): Services => $this->services, static function (): void {
        }))->run($hook, $params);
    }

    /**
     * Runs what the request's shutdown runs: the rows are written, and the queue is flushed.
     */
    private function endRequest(): void
    {
        $shutdown = $this->shutdown;
        $this->shutdown = [];
        foreach ($shutdown as $function) {
            $function();
        }
    }

    /**
     * The customer form's save as PrestaShop runs it: the update hooks around the stored consent.
     */
    private function switchConsent(\Customer $customer, bool $subscribed): void
    {
        $this->fire('actionObjectCustomerUpdateBefore', ['object' => $customer]);
        self::db()->update('customer', ['newsletter' => (int) $subscribed], '`id_customer` = ' . (int) $customer->id);
        $customer->newsletter = $subscribed ? '1' : '0';
        $this->fire('actionObjectCustomerUpdateAfter', ['object' => $customer]);
    }

    /**
     * @param array<string, mixed> $columns
     */
    private function customer(string $email, bool $newsletter, array $columns = []): \Customer
    {
        return new \Customer($this->addCustomer($this->shop, $email, $newsletter, $this->idLang, $columns));
    }

    private function storefront(): \FrontController
    {
        $controller = (new \ReflectionClass(\IdentityController::class))->newInstanceWithoutConstructor();
        $controller->php_self = 'identity';

        return $controller;
    }

    /**
     * @return list<array<string, string|null>>
     */
    private function rows(): array
    {
        return self::db()->executeS(
            'SELECT `event_type`, `id_shop`, `entity_id`, `language`, `status`, `last_error` FROM `' . _DB_PREFIX_ . EventQueue::TABLE . '` ORDER BY `id`',
            true,
            false
        ) ?: [];
    }

    /**
     * @return list<array<string, string|null>>
     */
    private function welcomeRows(): array
    {
        return array_values(array_filter($this->rows(), static fn (array $row): bool => $row['event_type'] === WelcomeTrigger::EVENT_TYPE));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function autoresponderRequests(): array
    {
        return array_values(array_filter($this->mock->requests(), static fn (array $request): bool => $request['path'] === '/api/autoresponder.php'));
    }
}
