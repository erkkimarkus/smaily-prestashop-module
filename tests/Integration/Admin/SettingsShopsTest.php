<?php
declare(strict_types=1);

namespace Tests\Integration\Admin;

use PrestaShop\Module\SmailyForPrestaShop\Admin\Settings\LayoutChange;
use PrestaShop\Module\SmailyForPrestaShop\Admin\Settings\SettingsScope;
use PrestaShop\Module\SmailyForPrestaShop\Admin\Settings\ShopOverrides;
use PrestaShop\Module\SmailyForPrestaShop\Admin\Settings\TabSaver;
use PrestaShop\Module\SmailyForPrestaShop\Admin\Setup\SetupPanels;
use PrestaShop\Module\SmailyForPrestaShop\Admin\Setup\SetupScope;
use PrestaShop\Module\SmailyForPrestaShop\Automation\MappingRepository;
use PrestaShop\Module\SmailyForPrestaShop\Automation\Router;
use PrestaShop\Module\SmailyForPrestaShop\Config\AutomationSettings;
use PrestaShop\Module\SmailyForPrestaShop\Config\ContactsSettings;
use PrestaShop\Module\SmailyForPrestaShop\Config\EngineSettings;
use PrestaShop\Module\SmailyForPrestaShop\Database\SqlException;
use PrestaShop\Module\SmailyForPrestaShop\Engine\Connections;
use PrestaShop\Module\SmailyForPrestaShop\Engine\Disconnector;
use PrestaShop\Module\SmailyForPrestaShop\Engine\History\HistoryJobs;
use PrestaShop\Module\SmailyForPrestaShop\Engine\LayoutConnections;
use PrestaShop\Module\SmailyForPrestaShop\Http\Transport;
use PrestaShop\Module\SmailyForPrestaShop\Queue\Skipped;
use PrestaShop\Module\SmailyForPrestaShop\Runtime\Services;
use PrestaShop\Module\SmailyForPrestaShop\Scope\AccountResolver;
use PrestaShop\Module\SmailyForPrestaShop\Scope\StoreLayout;
use PrestaShop\Module\SmailyForPrestaShop\Smaily\Account;
use PrestaShop\Module\SmailyForPrestaShop\Smaily\AccountStore;
use Tests\Integration\Fixtures\FailingQueries;
use Tests\Integration\Fixtures\SmailyMockServer;
use Tests\Integration\IntegrationTestCase;
use Tests\Integration\MultistoreShop;

require_once __DIR__ . '/../Fixtures/SmailyMockServer.php';
require_once __DIR__ . '/../MultistoreShop.php';
require_once __DIR__ . '/../Fixtures/FailingQueries.php';

/**
 * Settings on a multishop store: a shop's switch "Use its own … on this shop", and the change of
 * how the shops are run in both directions. Every shop reads the same Smaily account, Contacts
 * settings and workflows before and after a change, and no account is lost.
 */
final class SettingsShopsTest extends IntegrationTestCase
{
    use MultistoreShop;

    private const USERNAME = 'api-user';

    private const PASSWORD = 'not-a-real-password';

    private const ACCOUNT_TABLE = 'smaily_connect_account';

    private const MAPPING_TABLE = 'smaily_connect_automation_mapping';

    private const KEYS = [
        ContactsSettings::ENABLED,
        ContactsSettings::CONFIRMED,
        ContactsSettings::MODE,
        ContactsSettings::GUESTS,
        ContactsSettings::FIELDS,
        ContactsSettings::CHECKOUT_CHECKBOX,
        StoreLayout::SEPARATE_STORES,
        StoreLayout::LANGUAGE_MODE,
        StoreLayout::FALLBACK_LANGUAGE,
        AutomationSettings::WELCOME_ENABLED,
        AutomationSettings::FIRST_ORDER_ENABLED,
        AutomationSettings::CART_ENABLED,
        AutomationSettings::CART_DELAY_MINUTES,
    ];

    private Services $services;

    private AccountStore $accounts;

    private MappingRepository $mappings;

    private int $shopA;

    private int $shopB;

    private int $shopC;

    /** @var array<string, list<array<string, mixed>>> */
    private array $savedRows = [];

    protected function setUp(): void
    {
        parent::setUp();
        $mock = SmailyMockServer::start();
        $mock->acceptCredentials(self::USERNAME, self::PASSWORD);
        $mock->setWorkflows(array_map(
            static fn (int $id): array => ['id' => $id, 'title' => 'Workflow ' . $id, 'trigger_type' => 'form_submitted', 'is_enabled' => true],
            [42, 43]
        ));
        $transport = $mock->transport();
        $this->services = Services::fromProviders(Services::PROVIDERS);
        $this->services->set(Transport::class, static fn (): Transport => $transport);
        $this->accounts = $this->services->get(AccountStore::class);
        $this->mappings = $this->services->get(MappingRepository::class);

        foreach ([self::ACCOUNT_TABLE, self::MAPPING_TABLE] as $table) {
            $this->savedRows[$table] = self::db()->executeS('SELECT * FROM `' . _DB_PREFIX_ . $table . '`', true, false) ?: [];
            self::db()->delete($table);
        }
        foreach (self::KEYS as $key) {
            $this->savedRows[$key] = (array) self::db()->executeS('SELECT * FROM `' . _DB_PREFIX_ . 'configuration` WHERE `name` = \'' . pSQL($key) . '\'');
            \Configuration::deleteByName($key);
        }

        $this->openMultistore();
        $this->shopA = $this->defaultShopId();
        $group = $this->addShopGroup(false);
        $this->shopB = $this->addShop($group);
        $this->shopC = $this->addShop($group);
    }

    protected function tearDown(): void
    {
        if (defined('_PS_ROOT_DIR_')) {
            $this->closeMultistore();
            foreach ([self::ACCOUNT_TABLE, self::MAPPING_TABLE] as $table) {
                self::db()->delete($table);
                // Db::insert() would store '' as NULL: the rows go back as they were.
                foreach ($this->savedRows[$table] as $row) {
                    self::db()->execute('INSERT INTO `' . _DB_PREFIX_ . $table . '` (`' . implode('`, `', array_keys($row)) . '`) VALUES ('
                        . implode(', ', array_map(static fn ($value) => $value === null ? 'NULL' : '\'' . pSQL((string) $value) . '\'', $row)) . ')');
                }
            }
            foreach (self::KEYS as $key) {
                \Configuration::deleteByName($key);
                foreach ($this->savedRows[$key] as $row) {
                    self::db()->insert('configuration', $row, true);
                }
            }
            \Configuration::resetStaticCache();
            \Configuration::loadConfiguration();
        }
        parent::tearDown();
    }

    public function testAShopUsesItsOwnContactsSettingsAndGoesBackToTheSharedOnes(): void
    {
        $this->oneStoreConfigured();
        $saver = $this->saver();
        $context = $this->context($this->shopB);
        $overrides = $this->overrides();

        $saved = $saver->saveContacts($context, ['mode' => 'all_customers', 'fields' => ['first_name'], 'guests' => false, 'enabled' => true], true);
        self::assertSame(['errors' => [], 'returned' => false], $saved);
        self::assertTrue($overrides->hasOwn(ShopOverrides::CONTACTS, $this->shopB));
        self::assertSame('all_customers', (new ContactsSettings())->mode($this->shopB)->mode());
        self::assertSame('subscribers', (new ContactsSettings())->mode($this->shopC)->mode(), 'the other shops keep the shared settings');
        self::assertSame(['shop' => 6, 'global' => 6], $this->contactRows($this->shopB), 'the five own values and the shop\'s confirmation');

        self::assertSame(['errors' => [], 'returned' => true], $saver->saveContacts($context, [], false));
        self::assertFalse($overrides->hasOwn(ShopOverrides::CONTACTS, $this->shopB));
        self::assertSame('subscribers', (new ContactsSettings())->mode($this->shopB)->mode());
        self::assertSame(['shop' => 1, 'global' => 6], $this->contactRows($this->shopB), 'only the confirmation stays at the shop: it is not an own value');
        self::assertSame(0, (int) self::db()->getValue('SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'configuration` WHERE `id_shop` = ' . $this->shopB . ' AND `value` IS NULL', false), 'nothing was written as NULL');
    }

    public function testAShopKeepsTheSharedPasswordWhenItTakesTheSameAccountAsItsOwn(): void
    {
        $this->oneStoreConfigured();
        $saver = $this->saver();
        $context = $this->context($this->shopB);

        $own = $saver->saveConnection($context, ['subdomain' => 'demo', 'username' => self::USERNAME, 'password' => '', 'language_mode' => 'single'], true);

        self::assertSame([], $own['errors']);
        self::assertTrue($own['connected'], 'the shared password was kept');
        self::assertFalse($own['own_account_started'], 'the same account keeps the shared automations');
        self::assertSame('demo', $this->accounts->find($this->shopB, '')->subdomain());
        self::assertSame(42, $this->whatShopsRead()[$this->shopB]['welcome'][1], 'the shop keeps the shared workflows');
    }

    /**
     * A shop on its own Smaily account saves its Connection tab, as its own or back to the shared
     * setup. A read of the shop's own account that fails (facts file 6.11) is not "the shop has
     * none": read so, the save copied the shared account over the shop's own. The save throws,
     * which the endpoint answers as an error, and the shop's own account stays.
     */
    public function testASaveThatCouldNotReadTheShopsOwnAccountWritesNothing(): void
    {
        $this->oneStoreConfigured();
        $this->accounts->save(new Account($this->shopB, '', 'other', self::USERNAME, self::PASSWORD));
        $before = [$this->accountRows(), $this->contactRows($this->shopB), $this->automationRows($this->shopB)];
        $saver = $this->saver();
        $context = $this->context($this->shopB);
        $failing = sprintf('/FROM `[^`]+smaily_connect_account` WHERE `id_shop` = %d /', $this->shopB);

        foreach ([true, false] as $own) {
            try {
                FailingQueries::during($failing, static function () use ($saver, $context, $own): void {
                    $saver->saveConnection($context, ['subdomain' => 'other', 'username' => self::USERNAME, 'password' => '', 'language_mode' => 'single'], $own);
                });
                self::fail('The save went on without the shop\'s own account.');
            } catch (SqlException $error) {
                self::assertSame($before, [$this->accountRows(), $this->contactRows($this->shopB), $this->automationRows($this->shopB)]);
                self::assertSame('other', $this->accounts->find($this->shopB, '')->subdomain());
            }
        }
    }

    /**
     * @return array<string, array{string}>
     */
    public function ownReads(): array
    {
        return [
            'the shop\'s own settings' => ['/^SELECT `name`, `value` FROM `[^`]+configuration` .*`id_shop` = %d$/'],
            'the shop\'s own account' => ['/FROM `[^`]+smaily_connect_account` WHERE `id_shop` = %d /'],
        ];
    }

    /**
     * One store becomes separate stores: every shop gets the shared values it does not hold. A
     * read of what a shop holds that fails is not "nothing": read so, the shared consent mode and
     * the shared account replaced the shop's own. The change stops, the store stays one store,
     * and every shop reads what it read before.
     *
     * @dataProvider ownReads
     */
    public function testALayoutChangeThatCouldNotReadAShopsOwnValuesKeepsThem(string $failing): void
    {
        $this->oneStoreConfigured();
        $this->accounts->save(new Account($this->shopC, '', 'other', self::USERNAME, self::PASSWORD));
        \Configuration::updateValue(ContactsSettings::MODE, 'checkout_optin', false, null, $this->shopC);
        $before = $this->whatShopsRead();
        $change = $this->layoutChange();
        $shop = $this->shopA;

        try {
            FailingQueries::during(sprintf($failing, $this->shopC), static function () use ($change, $shop): void {
                $change->toSeparateStores($shop);
            });
            self::fail('The change went on without the shop\'s own values.');
        } catch (SqlException $error) {
            self::assertSame($before, $this->whatShopsRead());
            self::assertFalse((new StoreLayout())->separateStores());
            self::assertSame('other', $this->accounts->find($this->shopC, '')->subdomain());
        }

        $change->toSeparateStores($this->shopA);
        self::assertSame($before, $this->whatShopsRead());
        self::assertTrue((new StoreLayout())->separateStores());
    }

    public function testAShopOnAnotherAccountLosesItsOwnAutomationsBothWays(): void
    {
        $this->oneStoreConfigured();
        $this->mappings->upsert($this->shopB, 'welcome', '', 'default', 99);
        $saver = $this->saver();
        $context = $this->context($this->shopB);

        $refused = $saver->saveConnection($context, ['subdomain' => 'other', 'username' => self::USERNAME, 'password' => '', 'language_mode' => 'single'], true);
        self::assertSame('password', $refused['errors'][0]['field']);
        self::assertNull($this->accounts->find($this->shopB, ''), 'a refused save leaves no copy behind');

        $other = $saver->saveConnection($context, ['subdomain' => 'other', 'username' => self::USERNAME, 'password' => self::PASSWORD, 'language_mode' => 'single'], true);
        self::assertTrue($other['own_account_started']);
        self::assertSame(0, $this->mappingCount($this->shopB), 'workflows picked from the shared account go');
        $this->mappings->upsert($this->shopB, 'welcome', '', 'default', 7);

        $back = $saver->saveConnection($context, [], false);
        self::assertTrue($back['returned']);
        self::assertNull($this->accounts->find($this->shopB, ''));
        self::assertSame(0, $this->mappingCount($this->shopB), 'workflows picked from the other account go');
        self::assertSame('demo', $this->resolver()->for($this->shopB, 'en')->subdomain());
    }

    public function testAShopUsesItsOwnAutomationsAndGoesBackToTheSharedOnes(): void
    {
        $this->oneStoreConfigured();
        $saver = $this->saver();
        $context = $this->context($this->shopB);
        $overrides = $this->overrides();
        $automations = new AutomationSettings();
        $data = [
            'triggers' => ['welcome' => ['enabled' => true, 'workflow' => '42'], 'abandoned_cart' => ['enabled' => true, 'workflow' => '43']],
            'cart_delay' => '20',
        ];

        self::assertSame(['errors' => [], 'returned' => false], $saver->saveAutomations($context, $data, true));
        self::assertTrue($overrides->hasOwn(ShopOverrides::AUTOMATIONS, $this->shopB));
        self::assertSame(['shop' => 4, 'mappings' => 2], $this->automationRows($this->shopB), 'its own switches and delay, the shared welcome workflow as its own, and its cart workflow');
        self::assertSame([true, 20], [$automations->enabled('abandoned_cart', $this->shopB), $automations->cartDelayMinutes($this->shopB)]);
        self::assertSame([false, 30], [$automations->enabled('abandoned_cart', $this->shopC), $automations->cartDelayMinutes($this->shopC)], 'the other shops keep the shared automations');
        self::assertSame(['shop' => 0, 'mappings' => 1], $this->automationRows(0), 'the shared rows are untouched');
        $values = $this->setupPanels()->automations()->values($context->automationsScope($overrides, true));
        self::assertSame(['42', '43', 20], [$values['triggers']['welcome']['workflow'], $values['triggers']['abandoned_cart']['workflow'], $values['cart_delay']]);

        self::assertSame(['errors' => [], 'returned' => true], $saver->saveAutomations($context, $data, false));
        self::assertFalse($overrides->hasOwn(ShopOverrides::AUTOMATIONS, $this->shopB));
        self::assertSame(['shop' => 0, 'mappings' => 0], $this->automationRows($this->shopB));
        self::assertSame(0, (int) self::db()->getValue('SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'configuration` WHERE `id_shop` = ' . $this->shopB . ' AND `value` IS NULL', false), 'nothing was written as NULL');
        self::assertSame([false, 30], [(new AutomationSettings())->enabled('abandoned_cart', $this->shopB), (new AutomationSettings())->cartDelayMinutes($this->shopB)]);

        // Unticked on a shop without own values: the tab saves the shared values it shows.
        self::assertSame(['errors' => [], 'returned' => false], $saver->saveAutomations($context, $data, false));
        self::assertSame(['shop' => 0, 'mappings' => 0], $this->automationRows($this->shopB));
        self::assertSame(['shop' => 0, 'mappings' => 2], $this->automationRows(0));
        self::assertSame(20, (new AutomationSettings())->cartDelayMinutes($this->shopC));
    }

    /**
     * In one store the Initial setup sets up all shops (Magento's wizard, CON-179). With a shop
     * selected that holds its own values, it once showed that shop's values and saved them as the
     * shared ones, which widened the audience of every shop without its own.
     */
    public function testTheInitialSetupWithAShopSelectedShowsAndSavesTheSharedValues(): void
    {
        $this->oneStoreConfigured();
        $layout = new StoreLayout();
        $layout->setLanguageMode('b', 0);
        // Shop B's own values: every customer and guests, sync switched off, another language mode.
        $contacts = new ContactsSettings();
        $contacts->setMode(new \PrestaShop\Module\SmailyForPrestaShop\Contacts\ContactSyncMode('all_customers', true), $this->shopB);
        $contacts->setFields(['first_name'], $this->shopB);
        $contacts->setEnabled(false, $this->shopB);
        $layout->setLanguageMode('c', $this->shopB);
        \Configuration::resetStaticCache();
        \Configuration::loadConfiguration();
        $shared = $this->whatShopsRead()[$this->shopC];
        $own = $this->whatShopsRead()[$this->shopB];

        self::assertSame(0, SetupScope::current($layout, $this->shopB)->targetShop(), 'the setup page of shop B saves for all shops');
        $scope = new SetupScope(true, false, true, $this->shopB, ['en' => 'English', 'et' => 'Eesti']);
        $panels = $this->setupPanels();

        $shown = $panels->contacts()->values($scope);
        self::assertSame(
            ['subscribers', false, ['first_name', 'last_name'], true],
            [$shown['mode'], $shown['guests'], $shown['fields'], $shown['active']],
            'the values all shops share, not shop B\'s own'
        );
        self::assertSame('b', $panels->connection()->values($scope)['language_mode']);

        // Continue saves what the step shows.
        self::assertSame([], $panels->contacts()->save($scope, [
            'mode' => $shown['mode'],
            'fields' => $shown['fields'],
            'guests' => $shown['guests'],
            'checkout_checkbox' => $shown['checkout_checkbox'],
        ]));

        $read = $this->whatShopsRead();
        self::assertSame($shared, $read[$this->shopC], 'the shared audience is as it was');
        self::assertSame($own, $read[$this->shopB], 'and shop B keeps its own values');
        self::assertSame('subscribers', \Configuration::getGlobalValue(ContactsSettings::MODE));
        self::assertSame('0', (string) \Configuration::getGlobalValue(ContactsSettings::GUESTS));
    }

    /**
     * A shop with its own automations sends what its page shows. Cleared to "-- Not Selected --",
     * an inherited workflow once kept starting: the shop held no row to remove, and the Router
     * still read the shared one.
     */
    public function testAShopThatClearsAnInheritedWorkflowStartsNothingUntilItReturnsToTheSharedOnes(): void
    {
        $this->oneStoreConfigured();
        $saver = $this->saver();
        $context = $this->context($this->shopB);
        $overrides = $this->overrides();
        /** @var Router $router */
        $router = $this->services->get(Router::class);
        $cleared = ['triggers' => ['welcome' => ['enabled' => true, 'workflow' => '']], 'cart_delay' => '30'];
        $shown = fn (): string => $this->setupPanels()->automations()->values($context->automationsScope($overrides, true))['triggers']['welcome']['workflow'];

        self::assertSame(['errors' => [], 'returned' => false], $saver->saveAutomations($context, $cleared, true));

        self::assertSame('', $shown(), 'the page shows no workflow');
        self::assertEquals(new Skipped(Router::SKIPPED_NO_WORKFLOW), $router->route('welcome', $this->shopB, ''), 'and the shop starts none');
        self::assertSame(42, $router->resolve('welcome', $this->shopC, '')->workflowId(), 'a shop on the shared setup still sends');
        self::assertSame(['shop' => 0, 'mappings' => 1], $this->automationRows(0), 'the shared row is untouched');
        self::assertSame([null, false, false], array_values(array_intersect_key($this->mappings->rows($this->shopB)[0], ['workflow_id' => 0, 'optin_trigger' => 0, 'is_default_fallback' => 0])), 'the shop\'s own row says "no workflow"');

        // Saved again unchanged, the shop still starts nothing.
        self::assertSame(['errors' => [], 'returned' => false], $saver->saveAutomations($context, $cleared, true));
        self::assertSame(['shop' => 4, 'mappings' => 1], $this->automationRows($this->shopB));
        self::assertEquals(new Skipped(Router::SKIPPED_NO_WORKFLOW), $router->route('welcome', $this->shopB, ''));

        // A workflow of its own takes the row's place.
        $picked = ['triggers' => ['welcome' => ['enabled' => true, 'workflow' => '43']], 'cart_delay' => '30'];
        self::assertSame(['errors' => [], 'returned' => false], $saver->saveAutomations($context, $picked, true));
        self::assertSame([43, 1], [$router->resolve('welcome', $this->shopB, '')->workflowId(), $this->mappingCount($this->shopB)]);
        self::assertSame(['errors' => [], 'returned' => false], $saver->saveAutomations($context, $cleared, true));
        self::assertEquals(new Skipped(Router::SKIPPED_NO_WORKFLOW), $router->route('welcome', $this->shopB, ''));

        // The switch off: the shop reads the shared workflows again.
        self::assertSame(['errors' => [], 'returned' => true], $saver->saveAutomations($context, $cleared, false));
        self::assertSame(['shop' => 0, 'mappings' => 0], $this->automationRows($this->shopB));
        self::assertSame(42, $router->resolve('welcome', $this->shopB, '')->workflowId());
        self::assertSame(0, (int) self::db()->getValue('SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'configuration` WHERE `id_shop` = ' . $this->shopB . ' AND `value` IS NULL', false), 'nothing was written as NULL');

        // The shared scope holds no such row: a cleared shared workflow is removed.
        self::assertSame(['errors' => [], 'returned' => false], $saver->saveAutomations($this->context(0), $cleared, false));
        self::assertSame(0, $this->mappingCount(0));
    }

    /**
     * To one store, the promoted shop's "no workflow" wins as its workflows do: the shared row of
     * that key goes, and no shared row says "no workflow".
     */
    public function testAPromotedShopsRowOfNoWorkflowRemovesTheSharedRow(): void
    {
        $this->oneStoreConfigured();
        (new AutomationSettings())->setEnabled('first_order', true, 0);
        $this->mappings->upsert(0, 'first_order', '', 'default', 43);
        $this->layoutChange()->toSeparateStores($this->shopA);
        $this->mappings->setNone($this->shopA, 'welcome', '', 'default');
        // A shared row that an upgrade left behind.
        $this->mappings->upsert(0, 'welcome', '', 'default', 42);
        /** @var Router $router */
        $router = $this->services->get(Router::class);

        $this->layoutChange()->toOneStore($this->shopA);

        self::assertSame([['first_order', 43]], array_map(static fn (array $row): array => [$row['trigger'], $row['workflow_id']], $this->mappings->rows(0)));
        self::assertSame(0, $this->mappingCount($this->shopA));
        foreach ([$this->shopA, $this->shopB] as $shop) {
            self::assertEquals(new Skipped(Router::SKIPPED_NO_WORKFLOW), $router->route('welcome', $shop, ''));
            self::assertSame(43, $router->resolve('first_order', $shop, '')->workflowId());
        }
    }

    /**
     * As Magento: a change of the language mode and a replaced Smaily account keep the workflow
     * numbers of the automations (ARCHITECTURE 14: a known limit). Mode `a` stores the fallback
     * account as the account of no language, and a save that leaves mode `a` removes the
     * languages' accounts.
     */
    public function testAChangeOfTheLanguageModeOrTheAccountKeepsTheWorkflowsAsMagento(): void
    {
        $this->oneStoreConfigured();
        $panel = $this->setupPanels()->connection();
        $scope = new SetupScope(false, false, true, 0, ['en' => 'English', 'et' => 'Eesti']);
        /** @var Router $router */
        $router = $this->services->get(Router::class);
        $account = static fn (string $subdomain): array => ['subdomain' => $subdomain, 'username' => self::USERNAME, 'password' => self::PASSWORD];
        $rows = fn (): array => array_map(static fn (array $row): array => [$row['language'], $row['account_key'], $row['workflow_id']], $this->mappings->rows(0));
        $stored = function (): array {
            $subdomains = [];
            foreach (['', 'en', 'et'] as $language) {
                $found = $this->accounts->find(0, $language);
                $subdomains[$language] = $found !== null ? $found->subdomain() : null;
            }

            return $subdomains;
        };

        // single -> a: the fallback account takes the place of the single one; its workflow stays.
        $saved = $panel->save($scope, ['language_mode' => 'a', 'fallback_language' => 'en', 'accounts' => ['en' => $account('other-en'), 'et' => $account('demo-et')]]);
        self::assertSame([], $saved['errors']);
        self::assertSame(['' => 'other-en', 'en' => 'other-en', 'et' => 'demo-et'], $stored());
        self::assertSame([['', 'default', 42]], $rows());
        $this->mappings->upsert(0, 'welcome', 'en', 'en', 43);
        $this->mappings->upsert(0, 'welcome', 'et', 'et', 44);

        // a -> single: the fallback account stays the single one, the languages' accounts go, and
        // every workflow row stays. The row of no language is read again, through that account.
        $saved = $panel->save($scope, $account('other-en') + ['language_mode' => 'single']);
        self::assertSame([], $saved['errors']);
        self::assertSame(['' => 'other-en', 'en' => null, 'et' => null], $stored());
        self::assertCount(3, $rows());
        $match = $router->resolve('welcome', $this->shopB, 'et');
        self::assertSame([42, 'other-en'], [$match->workflowId(), $match->account()->subdomain()]);

        // Another Smaily account in the same mode: the workflow number stays as well.
        $saved = $panel->save($scope, $account('demo-two') + ['language_mode' => 'single']);
        self::assertSame([], $saved['errors']);
        self::assertCount(3, $rows());
        $match = $router->resolve('welcome', $this->shopB, 'et');
        self::assertSame([42, 'demo-two'], [$match->workflowId(), $match->account()->subdomain()]);
    }

    /**
     * Magento CON-190: a shop on another Smaily account than the shared one always holds its own
     * automations, starts from no workflow, and never reads or writes the shared ones.
     */
    public function testAShopOnAnotherAccountAlwaysSavesItsOwnAutomations(): void
    {
        $this->oneStoreConfigured();
        $this->accounts->save(new Account($this->shopC, '', 'other', self::USERNAME, self::PASSWORD));
        $overrides = $this->overrides();
        $context = $this->context($this->shopC);

        self::assertTrue($overrides->keepsOwnAutomations($this->shopC));
        self::assertTrue($overrides->needsOwnWorkflows($this->shopC), 'the page tells the merchant to pick its workflows');
        self::assertFalse($overrides->keepsOwnAutomations($this->shopB));
        self::assertFalse($overrides->needsOwnWorkflows($this->shopB));
        $scope = $context->automationsScope($overrides, false);
        self::assertSame($this->shopC, $scope->targetShop(), 'its own automations, whatever its switch says');
        self::assertSame('', $this->setupPanels()->automations()->values($scope)['triggers']['welcome']['workflow'], 'the shared workflow is not its own');

        $saved = $this->saver()->saveAutomations($context, ['triggers' => ['welcome' => ['enabled' => true, 'workflow' => '43']], 'cart_delay' => '30'], false);

        self::assertSame(['errors' => [], 'returned' => false], $saved);
        self::assertSame(['shop' => 4, 'mappings' => 1], $this->automationRows($this->shopC));
        self::assertSame(['shop' => 0, 'mappings' => 1], $this->automationRows(0));
        self::assertFalse($overrides->needsOwnWorkflows($this->shopC));
        $read = $this->whatShopsRead();
        self::assertSame([[true, 43], [true, 42]], [$read[$this->shopC]['welcome'], $read[$this->shopB]['welcome']]);

        // A shop with its own account per language (mode `a`) keeps its own automations as well.
        \Configuration::updateValue(StoreLayout::LANGUAGE_MODE, 'a', false, null, $this->shopB);
        \Configuration::resetStaticCache();
        \Configuration::loadConfiguration();
        self::assertTrue($overrides->keepsOwnAutomations($this->shopB));
    }

    public function testOneStoreToSeparateStoresKeepsWhatEveryShopReads(): void
    {
        $this->oneStoreConfigured();
        $this->accounts->save(new Account($this->shopC, '', 'other', self::USERNAME, self::PASSWORD));
        \Configuration::updateValue(ContactsSettings::MODE, 'checkout_optin', false, null, $this->shopC);
        $before = $this->whatShopsRead();

        $this->layoutChange()->toSeparateStores($this->shopA);

        self::assertTrue((new StoreLayout())->separateStores());
        self::assertSame($before, $this->whatShopsRead());
        self::assertSame(0, (int) self::db()->getValue('SELECT COUNT(*) FROM `' . _DB_PREFIX_ . self::ACCOUNT_TABLE . '` WHERE `id_shop` = 0', false), 'no shared account is left');
        self::assertSame(['shop' => 0, 'global' => 1], $this->contactRows(0), 'only the confirmation stays shared');
        self::assertSame(1, $this->mappingCount($this->shopA));
        self::assertSame(0, $this->mappingCount($this->shopC), 'a shop on another account never read the shared workflows');
        self::assertSame(0, $this->mappingCount(0));
        self::assertNotSame(
            self::db()->getValue('SELECT `password_enc` FROM `' . _DB_PREFIX_ . self::ACCOUNT_TABLE . '` WHERE `id_shop` = ' . $this->shopA, false),
            self::PASSWORD,
            'the copied password stays encrypted'
        );
    }

    /**
     * The owner's decision of 2026-11-23: the Campaign Intelligence connection of the shop that the
     * merchant looks at is carried over, in both directions (Engine\LayoutConnections).
     */
    public function testALayoutChangeCarriesTheCampaignIntelligenceConnectionOfTheChosenShop(): void
    {
        $this->oneStoreConfigured();
        $connections = $this->services->get(Connections::class);
        try {
            $shared = $connections->save(0, 'demo', '550e8400-e29b-41d4-a716-446655440000', 'invented-key', []);

            $this->layoutChange()->toSeparateStores($this->shopB);
            self::assertNull($connections->find(0));
            self::assertSame($shared->id(), $connections->find($this->shopB)->id(), 'to separate stores it becomes the chosen shop\'s own');

            $this->layoutChange()->toOneStore($this->shopB);
            self::assertNull($connections->find($this->shopB));
            self::assertSame($shared->id(), $connections->find(0)->id(), 'to one store it serves all shops again');
        } finally {
            self::db()->delete('smaily_connect_connection');
            \Configuration::deleteByName(EngineSettings::CONSENT_CONFIRMED);
        }
    }

    public function testALayoutChangeIsRefusedForAShopThatTheStoreDoesNotHave(): void
    {
        $this->oneStoreConfigured();

        try {
            $this->layoutChange()->toSeparateStores(990);
            self::fail('The change was made.');
        } catch (\InvalidArgumentException $error) {
            self::assertFalse((new StoreLayout())->separateStores());
        }
    }

    public function testSeparateStoresToOneStoreRemovesEveryShopsOwnValues(): void
    {
        $this->oneStoreConfigured();
        // An account of a language that an earlier mode `a` left: every shop gets it as its own.
        $this->accounts->save(new Account(0, 'et', 'demo-et', self::USERNAME, self::PASSWORD));
        $this->layoutChange()->toSeparateStores($this->shopA);
        // In separate stores, shop C takes another account, per-language accounts and a workflow.
        $this->accounts->save(new Account($this->shopC, '', 'other', self::USERNAME, self::PASSWORD));
        $this->accounts->save(new Account($this->shopC, 'en', 'other', self::USERNAME, self::PASSWORD));
        \Configuration::updateValue(StoreLayout::LANGUAGE_MODE, 'a', false, null, $this->shopC);
        \Configuration::updateValue(StoreLayout::FALLBACK_LANGUAGE, 'en', false, null, $this->shopC);
        $this->mappings->upsert($this->shopC, 'welcome', '', 'default', 7);
        // Shop B differs in one Contacts value and has confirmed its Contacts settings.
        \Configuration::updateValue(ContactsSettings::GUESTS, '1', false, null, $this->shopB);
        (new ContactsSettings())->confirm($this->shopB);
        $before = $this->whatShopsRead();
        self::assertSame('other', $before[$this->shopC]['account'][0]);
        self::assertCount(7, $this->accountRows(), 'three shops with two accounts each, and shop C\'s own of a language');

        $this->layoutChange()->toOneStore($this->shopA);

        self::assertFalse((new StoreLayout())->separateStores());
        $after = $this->whatShopsRead();
        $overrides = $this->overrides();
        foreach ([$this->shopA, $this->shopB, $this->shopC] as $shop) {
            self::assertSame($before[$this->shopA], $after[$shop], 'every shop reads what the promoted shop read');
            foreach (array_keys(ShopOverrides::KEYS) as $section) {
                self::assertFalse($overrides->hasOwn($section, $shop), 'no shop holds own values of ' . $section);
            }
            self::assertSame(0, $this->mappingCount($shop));
            self::assertSame(0, (int) self::db()->getValue('SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'configuration` WHERE `id_shop` = ' . $shop . ' AND `value` IS NULL', false), 'nothing was written as NULL');
        }
        self::assertSame([], $this->accountRows(), 'no shop holds an account row, differing or not');
        self::assertSame(1, (int) self::db()->getValue('SELECT COUNT(*) FROM `' . _DB_PREFIX_ . self::ACCOUNT_TABLE . '` WHERE `id_shop` = 0', false), 'the shared account alone is left');
        self::assertSame('demo', $this->accounts->find(0, '')->subdomain());
        self::assertSame(1, $this->mappingCount(0));
        self::assertSame(['shop' => 1, 'global' => 6], $this->contactRows($this->shopB), 'only the confirmation stays at the shop: it is not an own value');
        self::assertSame(['shop' => 0, 'global' => 6], $this->contactRows($this->shopC));
    }

    /**
     * The owner's decision of 2026-11-20: the layout change treats the workflow rows as Magento's
     * promoteToStoreWide() and copyMappingRows() do. The promoted shop's rows join the shared rows
     * that an upgrade left, its own winning; a row of a language's account is never shared.
     */
    public function testSeparateStoresToOneStoreMergesTheWorkflowRowsAsMagentoDoes(): void
    {
        $this->oneStoreConfigured();
        $this->layoutChange()->toSeparateStores($this->shopA);
        // Shared rows that an upgrade left behind in separate stores.
        $this->mappings->upsert(0, 'first_order', '', 'default', 50);
        $this->mappings->upsert(0, 'welcome', '', 'default', 60);
        $this->mappings->upsert(0, 'welcome', 'en', 'default', 61, false, true);
        // The promoted shop: its welcome workflow is its default fallback; one row of a language's account.
        $this->mappings->upsert($this->shopA, 'welcome', '', 'default', 42, false, true);
        $this->mappings->upsert($this->shopA, 'welcome', 'et', 'et', 70, false, true);
        $this->mappings->upsert($this->shopB, 'abandoned_cart', '', 'default', 80);

        $this->layoutChange()->toOneStore($this->shopA);

        $shared = array_map(
            static fn (array $row): string => $row['trigger'] . '|' . $row['language'] . '|' . $row['account_key'] . ' ' . $row['workflow_id'] . ($row['is_default_fallback'] ? ' fallback' : ''),
            $this->mappings->rows(0)
        );
        sort($shared);
        self::assertSame(
            ['first_order||default 50', 'welcome|en|default 61', 'welcome||default 42 fallback'],
            $shared,
            'the left shared rows stay, the promoted shop\'s row wins and takes over the default fallback, and no other shop\'s row or row of a language\'s account is shared'
        );
        foreach ([$this->shopA, $this->shopB, $this->shopC] as $shop) {
            self::assertSame(0, $this->mappingCount($shop));
        }
    }

    /**
     * One store to separate stores, as Magento's copyStoreWideToWebsite(): a shop gets the shared
     * rows it holds none for, and a copied default fallback only where it holds none of that kind.
     */
    public function testOneStoreToSeparateStoresCopiesTheSharedRowsAShopLacks(): void
    {
        $this->oneStoreConfigured();
        $this->mappings->upsert(0, 'welcome', '', 'default', 42, false, true);
        $this->mappings->upsert(0, 'first_order', '', 'default', 50);
        $this->mappings->upsert($this->shopB, 'welcome', '', 'default', 43);
        $this->mappings->upsert($this->shopB, 'welcome', 'et', 'et', 70, false, true);

        $this->layoutChange()->toSeparateStores($this->shopA);

        $rows = static fn (array $rows): array => array_map(
            static fn (array $row): string => $row['trigger'] . '|' . $row['language'] . '|' . $row['account_key'] . ' ' . $row['workflow_id'] . ($row['is_default_fallback'] ? ' fallback' : ''),
            $rows
        );
        self::assertEqualsCanonicalizing(['welcome||default 43', 'welcome|et|et 70 fallback', 'first_order||default 50'], $rows($this->mappings->rows($this->shopB)), 'its own rows stay, and the shared row it lacked comes');
        self::assertEqualsCanonicalizing(['welcome||default 42 fallback', 'first_order||default 50'], $rows($this->mappings->rows($this->shopC)));
        self::assertSame(0, $this->mappingCount(0));
    }

    public function testSeparateStoresToOneStoreNeverSharesAnAccountPerLanguage(): void
    {
        $this->oneStoreConfigured();
        $this->layoutChange()->toSeparateStores($this->shopA);
        $this->accounts->save(new Account($this->shopA, 'en', 'demo', self::USERNAME, self::PASSWORD));
        \Configuration::updateValue(StoreLayout::LANGUAGE_MODE, 'a', false, null, $this->shopA);
        \Configuration::updateValue(StoreLayout::FALLBACK_LANGUAGE, 'en', false, null, $this->shopA);

        $this->layoutChange()->toOneStore($this->shopA);

        self::assertSame('single', (new StoreLayout())->languageMode(0), 'an account per language is never shared');
        self::assertSame('single', (new StoreLayout())->languageMode($this->shopA), 'the promoted shop reads the shared mode');
        self::assertNull($this->accounts->find($this->shopA, 'en'), 'its account of a language goes with its own values');
        self::assertNull($this->accounts->find(0, 'en'));
        self::assertSame('demo', $this->accounts->find(0, '')->subdomain());
    }

    /**
     * One store, set up for all shops: the shared account `demo`, Contacts settings and one
     * welcome workflow.
     */
    private function oneStoreConfigured(): void
    {
        $layout = new StoreLayout();
        $layout->setSeparateStores(false);
        $layout->setLanguageMode('single', 0);
        $this->accounts->save(new Account(0, '', 'demo', self::USERNAME, self::PASSWORD));
        $contacts = new ContactsSettings();
        $contacts->setMode(new \PrestaShop\Module\SmailyForPrestaShop\Contacts\ContactSyncMode('subscribers', false), 0);
        $contacts->setFields(['first_name', 'last_name'], 0);
        $contacts->setCheckoutCheckbox(true, 0);
        $contacts->setEnabled(true, 0);
        $contacts->confirm(0);
        (new AutomationSettings())->setEnabled('welcome', true, 0);
        $this->mappings->upsert(0, 'welcome', '', 'default', 42);
        \Configuration::resetStaticCache();
        \Configuration::loadConfiguration();
    }

    /**
     * What each shop reads now: its Smaily account per language, its Contacts settings and its
     * welcome workflow.
     *
     * @return array<int, array<string, mixed>>
     */
    private function whatShopsRead(): array
    {
        \Configuration::resetStaticCache();
        \Configuration::loadConfiguration();
        $contacts = new ContactsSettings();
        $automations = new AutomationSettings();
        $read = [];
        foreach ([$this->shopA, $this->shopB, $this->shopC] as $shop) {
            $account = $this->resolver()->for($shop, 'en');
            $mode = $contacts->mode($shop);
            $match = $this->services->get(\PrestaShop\Module\SmailyForPrestaShop\Automation\Router::class)->resolve('welcome', $shop, 'en');
            $read[$shop] = [
                'account' => $account === null ? null : [$account->subdomain(), $account->username(), $account->password() === self::PASSWORD],
                'sync' => $contacts->syncActive($shop),
                'mode' => [$mode->mode(), $mode->includesGuests()],
                'fields' => $contacts->fields($shop),
                'welcome' => [$automations->enabled('welcome', $shop), $match === null ? null : $match->workflowId()],
            ];
        }

        return $read;
    }

    /**
     * @return list<string> "shop:language:subdomain" of every account row, sorted
     */
    private function accountRows(): array
    {
        $rows = array_map(
            static fn (array $row): string => $row['id_shop'] . ':' . $row['language'] . ':' . $row['subdomain'],
            self::db()->executeS('SELECT `id_shop`, `language`, `subdomain` FROM `' . _DB_PREFIX_ . self::ACCOUNT_TABLE . '` WHERE `id_shop` > 0', true, false) ?: []
        );
        sort($rows);

        return $rows;
    }

    /**
     * @return array{shop: int, global: int} the Contacts rows at the shop's scope and the global scope
     */
    private function contactRows(int $idShop): array
    {
        $names = "'" . implode("','", [ContactsSettings::ENABLED, ContactsSettings::CONFIRMED, ContactsSettings::MODE, ContactsSettings::GUESTS, ContactsSettings::FIELDS, ContactsSettings::CHECKOUT_CHECKBOX]) . "'";
        $count = static fn (string $where): int => (int) self::db()->getValue('SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'configuration` WHERE `name` IN (' . $names . ') AND ' . $where, false);

        return [
            'shop' => $idShop > 0 ? $count('`id_shop` = ' . $idShop) : 0,
            'global' => $count('`id_shop` IS NULL AND `id_shop_group` IS NULL'),
        ];
    }

    private function mappingCount(int $idShop): int
    {
        return count($this->mappings->rows($idShop));
    }

    /**
     * @return array{shop: int, mappings: int} the automation keys stored at exactly the shop's scope, and its mapping rows
     */
    private function automationRows(int $idShop): array
    {
        $names = "'" . implode("','", ShopOverrides::KEYS[ShopOverrides::AUTOMATIONS]) . "'";

        return [
            'shop' => $idShop > 0 ? (int) self::db()->getValue('SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'configuration` WHERE `name` IN (' . $names . ') AND `id_shop` = ' . $idShop, false) : 0,
            'mappings' => $this->mappingCount($idShop),
        ];
    }

    private function context(int $selectedShop): SettingsScope
    {
        return SettingsScope::current(new StoreLayout(), $selectedShop);
    }

    private function saver(): TabSaver
    {
        return new TabSaver($this->setupPanels(), $this->overrides());
    }

    private function setupPanels(): SetupPanels
    {
        return new SetupPanels($this->services, \Context::getContext()->getTranslator());
    }

    private function overrides(): ShopOverrides
    {
        return new ShopOverrides($this->accounts, $this->mappings);
    }

    private function layoutChange(): LayoutChange
    {
        $shops = [$this->shopA, $this->shopB, $this->shopC];

        return new LayoutChange(new StoreLayout(), $this->overrides(), static fn (): array => $shops, new LayoutConnections(
            $this->services->get(Connections::class),
            $this->services->get(Disconnector::class),
            $this->services->get(EngineSettings::class),
            $this->services->get(HistoryJobs::class)
        ));
    }

    private function resolver(): AccountResolver
    {
        return new AccountResolver(new StoreLayout(), [$this->accounts, 'find']);
    }
}
