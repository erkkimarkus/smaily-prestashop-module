<?php
declare(strict_types=1);

namespace Tests\Integration\Admin;

use PrestaShop\Module\SmailyForPrestaShop\Admin\Setup\AutomationsPanel;
use PrestaShop\Module\SmailyForPrestaShop\Admin\Setup\SetupPanels;
use PrestaShop\Module\SmailyForPrestaShop\Admin\Setup\SetupScope;
use PrestaShop\Module\SmailyForPrestaShop\Automation\MappingRepository;
use PrestaShop\Module\SmailyForPrestaShop\Automation\Router;
use PrestaShop\Module\SmailyForPrestaShop\Config\AutomationSettings;
use PrestaShop\Module\SmailyForPrestaShop\Http\Response;
use PrestaShop\Module\SmailyForPrestaShop\Http\Transport;
use PrestaShop\Module\SmailyForPrestaShop\Queue\Skipped;
use PrestaShop\Module\SmailyForPrestaShop\Runtime\Services;
use PrestaShop\Module\SmailyForPrestaShop\Scope\StoreLayout;
use PrestaShop\Module\SmailyForPrestaShop\Smaily\Account;
use PrestaShop\Module\SmailyForPrestaShop\Smaily\AccountStore;
use Tests\Integration\IntegrationTestCase;

/**
 * The Automations panel in the shop (Initial setup step 3 and Settings > Automations, one class):
 * what it saves and reads back, what it refuses, and what it keeps when Smaily's list does not
 * offer a stored workflow or cannot be asked. Smaily is a transport of the test that answers each
 * subdomain's workflow list: `demo` lists 11, 12 and 13, `demo-et` lists 21, and `down` answers
 * HTTP 503.
 */
final class AutomationsPanelTest extends IntegrationTestCase
{
    private const ACCOUNT_TABLE = 'smaily_connect_account';

    private const MAPPING_TABLE = 'smaily_connect_automation_mapping';

    private const KEYS = [
        StoreLayout::SEPARATE_STORES,
        StoreLayout::LANGUAGE_MODE,
        StoreLayout::FALLBACK_LANGUAGE,
        AutomationSettings::WELCOME_ENABLED,
        AutomationSettings::FIRST_ORDER_ENABLED,
        AutomationSettings::CART_ENABLED,
        AutomationSettings::CART_DELAY_MINUTES,
        AutomationSettings::WELCOME_DISCOUNT_ENABLED,
        AutomationSettings::WELCOME_DISCOUNT_RULE,
        AutomationSettings::WELCOME_DISCOUNT_DAYS,
        AutomationSettings::FIRST_ORDER_DISCOUNT_ENABLED,
        AutomationSettings::FIRST_ORDER_DISCOUNT_RULE,
        AutomationSettings::FIRST_ORDER_DISCOUNT_DAYS,
        AutomationSettings::CART_DISCOUNT_ENABLED,
        AutomationSettings::CART_DISCOUNT_RULE,
        AutomationSettings::CART_DISCOUNT_DAYS,
    ];

    private const NO_DISCOUNT = ['enabled' => false, 'rule' => 0, 'days' => 7];

    private const LISTS = [
        'demo' => [11 => 'Welcome', 12 => 'First order', 13 => 'Cart'],
        'demo-et' => [21 => 'Tere'],
    ];

    private Services $services;

    private AccountStore $accounts;

    private MappingRepository $mappings;

    /** @var list<string> the hosts that were asked for a workflow list */
    private array $asked = [];

    /** @var array<string, list<array<string, mixed>>> */
    private array $savedRows = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->asked = [];
        $asked = &$this->asked;
        $transport = new class($asked) implements Transport {
            /** @var list<string> */
            private array $asked;

            /**
             * @param list<string> $asked
             */
            public function __construct(array &$asked)
            {
                $this->asked = &$asked;
            }

            public function request(string $method, string $url, array $options = []): Response
            {
                $subdomain = explode('.', (string) parse_url($url, PHP_URL_HOST))[0];
                $this->asked[] = $subdomain . ' ' . basename((string) parse_url($url, PHP_URL_PATH)) . '?' . http_build_query($options['query'] ?? []);
                if (!isset(AutomationsPanelTest::lists()[$subdomain])) {
                    return new Response(503, '');
                }
                $workflows = [];
                foreach (AutomationsPanelTest::lists()[$subdomain] as $id => $title) {
                    $workflows[] = ['id' => $id, 'title' => $title, 'trigger_type' => 'form_submitted', 'is_enabled' => true];
                }

                return new Response(200, (string) json_encode($workflows));
            }
        };
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
        \Configuration::loadConfiguration();
        $this->accounts->save(new Account(0, '', 'demo', 'api-user', 'not-a-real-password'));
    }

    protected function tearDown(): void
    {
        if (defined('_PS_ROOT_DIR_')) {
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

    /**
     * @return array<string, array<int, string>>
     */
    public static function lists(): array
    {
        return self::LISTS;
    }

    public function testASaveReadsBackThroughAnotherPanelAndAsksEachListOnce(): void
    {
        $scope = $this->scope(['en' => 'English']);
        $before = $this->panel()->values($scope);
        self::assertSame(['single', 30], [$before['mode'], $before['cart_delay']]);
        self::assertSame(['enabled' => false, 'workflow' => '', 'languages' => [], 'fallback' => '', 'discount' => self::NO_DISCOUNT], $before['triggers']['welcome']);

        $errors = $this->panel()->save($scope, [
            'triggers' => [
                'welcome' => ['enabled' => true, 'workflow' => '11'],
                'first_order' => ['enabled' => true, 'workflow' => '12'],
                'abandoned_cart' => ['enabled' => true, 'workflow' => '13'],
            ],
            'cart_delay' => '45',
        ]);

        self::assertSame([], $errors);
        self::assertSame(['demo workflows.php?trigger_type=form_submitted'], $this->asked, 'one list read for the one account');
        $after = $this->panel()->values($scope);
        self::assertSame(45, $after['cart_delay']);
        self::assertSame(
            ['welcome' => [true, '11'], 'first_order' => [true, '12'], 'abandoned_cart' => [true, '13']],
            array_map(static fn (array $trigger): array => [$trigger['enabled'], $trigger['workflow']], $after['triggers'])
        );
        self::assertSame(['abandoned_cart||default 13', 'first_order||default 12', 'welcome||default 11'], $this->rows(0));
        $match = $this->router()->resolve('first_order', (int) \Configuration::get('PS_SHOP_DEFAULT'), 'en');
        self::assertSame([12, 'demo'], [$match->workflowId(), $match->account()->subdomain()]);
    }

    public function testAWorkflowThatTheAccountDoesNotListIsRefusedAndNothingIsWritten(): void
    {
        $errors = $this->panel()->save($this->scope(['en' => 'English']), [
            'triggers' => [
                'welcome' => ['enabled' => true, 'workflow' => '99'],
                'first_order' => ['enabled' => true, 'workflow' => '12'],
                'abandoned_cart' => ['enabled' => false, 'workflow' => 'optin'],
            ],
            'cart_delay' => '30',
        ]);

        self::assertSame(['welcome.workflow', 'abandoned_cart.workflow'], array_column($errors, 'field'));
        self::assertSame('This workflow is not in the list of your Smaily account. Press Refresh workflows and pick a workflow from the list.', $errors[0]['message']);
        self::assertSame([], $this->rows(0));
        self::assertFalse((new AutomationSettings())->enabled('first_order', 0), 'no switch was written either');
    }

    /**
     * @return array<string, array{mixed, bool}>
     */
    public function delays(): array
    {
        return [
            '9 is too short' => ['9', false],
            '10 is the shortest' => ['10', true],
            '1435 is the longest' => [1435, true],
            '1436 is too long' => ['1436', false],
            'not a number' => ['soon', false],
            'nothing' => ['', false],
        ];
    }

    /**
     * @dataProvider delays
     *
     * @param mixed $delay
     */
    public function testTheCartDelayIsFromTenTo1435Minutes($delay, bool $accepted): void
    {
        $scope = $this->scope(['en' => 'English']);
        $data = ['triggers' => ['abandoned_cart' => ['enabled' => true, 'workflow' => '13']], 'cart_delay' => $delay];

        $errors = $this->panel()->save($scope, $data);

        if ($accepted) {
            self::assertSame([], $errors);
            self::assertSame((int) $delay, (new AutomationSettings())->cartDelayMinutes(0));

            return;
        }
        self::assertSame([['field' => 'cart_delay', 'message' => 'The abandoned-cart wait must be from 10 to 1435 minutes. A longer wait would send no reminder.']], $errors);
        self::assertSame([], $this->rows(0), 'nothing is written with a field at fault');

        // A switched-off reminder hides the field: the value blocks nothing and is not stored.
        $data['triggers']['abandoned_cart']['enabled'] = false;
        self::assertSame([], $this->panel()->save($scope, $data));
        self::assertSame(30, (new AutomationSettings())->cartDelayMinutes(0));
    }

    public function testAnAutomationSwitchedOnWithoutAWorkflowIsSavedAndSendsNothing(): void
    {
        $errors = $this->panel()->save($this->scope(['en' => 'English']), [
            'triggers' => ['welcome' => ['enabled' => true, 'workflow' => '']],
            'cart_delay' => '30',
        ]);

        self::assertSame([], $errors);
        self::assertTrue((new AutomationSettings())->enabled('welcome', 0));
        self::assertEquals(new Skipped(Router::SKIPPED_NO_WORKFLOW), $this->router()->route('welcome', (int) \Configuration::get('PS_SHOP_DEFAULT'), 'en'));
        self::assertSame([], $this->asked, 'nothing to check, so Smaily is not asked');
    }

    public function testWhileSmailyCannotBeAskedTheSaveStandsAndNoNewWorkflowIsTaken(): void
    {
        $this->accounts->save(new Account(0, '', 'down', 'api-user', 'not-a-real-password'));
        $this->mappings->upsert(0, 'welcome', '', 'default', 11);
        $this->mappings->upsert(0, 'first_order', '', 'default', 12);
        $scope = $this->scope(['en' => 'English']);

        // The switches, the delay and the unchanged workflow are saved; an empty value keeps a
        // workflow that the page could not offer (Magento PRO-1286).
        $errors = $this->panel()->save($scope, [
            'triggers' => [
                'welcome' => ['enabled' => true, 'workflow' => '11'],
                'first_order' => ['enabled' => true, 'workflow' => ''],
                'abandoned_cart' => ['enabled' => true, 'workflow' => ''],
            ],
            'cart_delay' => '60',
        ]);
        self::assertSame([], $errors);
        self::assertSame(['first_order||default 12', 'welcome||default 11'], $this->rows(0));
        self::assertSame(60, (new AutomationSettings())->cartDelayMinutes(0));
        self::assertTrue((new AutomationSettings())->enabled('abandoned_cart', 0));

        $errors = $this->panel()->save($scope, ['triggers' => ['welcome' => ['enabled' => true, 'workflow' => '13']], 'cart_delay' => '60']);
        self::assertSame([['field' => 'welcome.workflow', 'message' => 'Could not load the workflow list: Smaily did not answer. Try again in a moment.']], $errors);
        self::assertSame(['first_order||default 12', 'welcome||default 11'], $this->rows(0));

        $list = $this->panel()->workflows($scope, 'default');
        self::assertSame(['workflows' => [], 'error' => 'Could not load the workflow list: Smaily did not answer. Try again in a moment.'], $list);
    }

    public function testAStoredWorkflowThatTheListNoLongerOffersIsKeptUntilAnotherIsPicked(): void
    {
        $this->mappings->upsert(0, 'welcome', '', 'default', 77);
        $scope = $this->scope(['en' => 'English']);

        self::assertSame([], $this->panel()->save($scope, ['triggers' => ['welcome' => ['enabled' => true, 'workflow' => '77']], 'cart_delay' => '30']));
        self::assertSame([], $this->panel()->save($scope, ['triggers' => ['welcome' => ['enabled' => true, 'workflow' => '']], 'cart_delay' => '30']));
        self::assertSame(['welcome||default 77'], $this->rows(0), 'posted unchanged, or not offered and posted empty: kept');

        self::assertSame([], $this->panel()->save($scope, ['triggers' => ['welcome' => ['enabled' => true, 'workflow' => '11']], 'cart_delay' => '30']));
        self::assertSame([], $this->panel()->save($scope, ['triggers' => ['welcome' => ['enabled' => true, 'workflow' => '']], 'cart_delay' => '30']));
        self::assertSame([], $this->rows(0), 'a listed workflow cleared to "-- Not Selected --" goes');
    }

    /**
     * Decision 5 and Story 4.1 criterion 3: the migrated row of 2.x's generic opt-in trigger.
     */
    public function testTheOptInTriggerOfAnUpgradedStoreStaysUntilAnotherWorkflowIsSaved(): void
    {
        // As the upgrade writes it: no workflow, the opt-in trigger, the default fallback.
        $this->mappings->upsert(0, 'welcome', '', 'default', null, true, true);
        (new AutomationSettings())->setEnabled('welcome', true, 0);
        $scope = $this->scope(['en' => 'English']);
        $shop = (int) \Configuration::get('PS_SHOP_DEFAULT');

        self::assertSame(AutomationsPanel::OPTIN, $this->panel()->values($scope)['triggers']['welcome']['workflow']);

        $kept = ['triggers' => ['welcome' => ['enabled' => true, 'workflow' => AutomationsPanel::OPTIN], 'first_order' => ['enabled' => true, 'workflow' => '12']], 'cart_delay' => '30'];
        self::assertSame([], $this->panel()->save($scope, $kept));
        self::assertTrue($this->router()->resolve('welcome', $shop, 'en')->isOptInTrigger(), 'a save of another card keeps it sending');
        self::assertSame(AutomationsPanel::OPTIN, $this->panel()->values($scope)['triggers']['welcome']['workflow']);

        $picked = ['triggers' => ['welcome' => ['enabled' => true, 'workflow' => '11'], 'first_order' => ['enabled' => true, 'workflow' => '12']], 'cart_delay' => '30'];
        self::assertSame([], $this->panel()->save($scope, $picked));
        self::assertSame('11', $this->panel()->values($scope)['triggers']['welcome']['workflow']);
        self::assertSame(11, $this->router()->resolve('welcome', $shop, 'en')->workflowId());

        self::assertSame(['welcome.workflow'], array_column($this->panel()->save($scope, $kept), 'field'), 'no save picks the opt-in trigger again');
    }

    public function testAWorkflowPerLanguageSavesEachLanguageAndTheDefaultFallback(): void
    {
        (new StoreLayout())->setLanguageMode(StoreLayout::MODE_WORKFLOW_PER_LANGUAGE, 0);
        // A row that an earlier mode `a` left: a save in mode `b` keeps it (Magento CON-188).
        $this->mappings->upsert(0, 'welcome', 'et', 'et', 21);
        // A row of a language that the store no longer has.
        $this->mappings->upsert(0, 'welcome', 'fi', 'default', 13, false, true);
        $scope = $this->scope(['en' => 'English', 'et' => 'Eesti']);
        $shop = (int) \Configuration::get('PS_SHOP_DEFAULT');
        $data = [
            'triggers' => ['welcome' => ['enabled' => true, 'workflow' => '11', 'languages' => ['en' => '12', 'et' => '13'], 'fallback' => 'et']],
            'cart_delay' => '30',
        ];

        self::assertSame([], $this->panel()->save($scope, $data));

        self::assertSame(['welcome|en|default 12', 'welcome|et|default 13 fallback', 'welcome|et|et 21', 'welcome||default 11'], $this->rows(0));
        $values = $this->panel()->values($scope);
        self::assertSame('b', $values['mode']);
        self::assertSame(['enabled' => true, 'workflow' => '11', 'languages' => ['en' => '12', 'et' => '13'], 'fallback' => 'et', 'discount' => self::NO_DISCOUNT], $values['triggers']['welcome']);
        $router = $this->router();
        self::assertSame([12, 13, 13], [
            $router->resolve('welcome', $shop, 'en')->workflowId(),
            $router->resolve('welcome', $shop, 'et')->workflowId(),
            $router->resolve('welcome', $shop, 'lv')->workflowId(),
        ], 'each language its own, and an unmapped language the default fallback');

        // A post without a fallback (as the page posted it before CON-460) leaves the row of no
        // language to serve the languages without a workflow.
        $data['triggers']['welcome']['fallback'] = '';
        $data['triggers']['welcome']['languages']['en'] = '';
        self::assertSame([], $this->panel()->save($scope, $data));
        self::assertSame(['welcome|et|default 13', 'welcome|et|et 21', 'welcome||default 11'], $this->rows(0));
        self::assertSame(11, $this->router()->resolve('welcome', $shop, 'en')->workflowId(), 'the workflow of no language serves English');
        $values = $this->panel()->values($scope)['triggers']['welcome'];
        self::assertSame([['en' => '11', 'et' => '13'], 'en'], [$values['languages'], $values['fallback']], 'the page shows it on the row it serves');
    }

    /**
     * CON-460 criterion 3: mode `b` draws no workflow of no language and no "None". A store that
     * saved a workflow of no language with no default fallback keeps sending the same workflows
     * through a save of what the page shows, the 2.x opt-in trigger included.
     */
    public function testAStoredModeBWithADefaultWorkflowAndNoFallbackSendsTheSameAfterASaveOfThePage(): void
    {
        (new StoreLayout())->setLanguageMode(StoreLayout::MODE_WORKFLOW_PER_LANGUAGE, 0);
        $settings = new AutomationSettings();
        $settings->setEnabled('welcome', true, 0);
        $settings->setEnabled('first_order', true, 0);
        // As a mode-`b` save stored it before: a workflow of no language, "None" as the fallback.
        $this->mappings->upsert(0, 'first_order', '', 'default', 11);
        $this->mappings->upsert(0, 'first_order', 'en', 'default', 12);
        $this->mappings->upsert(0, 'welcome', '', 'default', null, true);
        $scope = $this->scope(['en' => 'English', 'et' => 'Eesti']);
        $shop = (int) \Configuration::get('PS_SHOP_DEFAULT');
        $sent = function () use ($shop): array {
            $router = $this->router();
            $workflow = static fn ($match): string => $match->isOptInTrigger() ? 'optin' : (string) $match->workflowId();

            return [
                $workflow($router->resolve('first_order', $shop, 'en')),
                $workflow($router->resolve('first_order', $shop, 'et')),
                $workflow($router->resolve('first_order', $shop, 'lv')),
                $workflow($router->resolve('welcome', $shop, 'en')),
                $workflow($router->resolve('welcome', $shop, 'et')),
            ];
        };
        $before = $sent();
        self::assertSame(['12', '11', '11', 'optin', 'optin'], $before);

        $values = $this->panel()->values($scope)['triggers'];
        self::assertSame([['en' => '12', 'et' => '11'], 'et'], [$values['first_order']['languages'], $values['first_order']['fallback']], 'Estonian, which the workflow of no language serves, shows it and is the fallback');
        self::assertSame([['en' => AutomationsPanel::OPTIN, 'et' => ''], 'en'], [$values['welcome']['languages'], $values['welcome']['fallback']]);

        // What the page posts in mode `b`: each language's select and the fallback radio.
        $data = ['cart_delay' => '30', 'triggers' => []];
        foreach (['welcome', 'first_order'] as $trigger) {
            $data['triggers'][$trigger] = ['enabled' => true, 'languages' => $values[$trigger]['languages'], 'fallback' => $values[$trigger]['fallback']];
        }
        self::assertSame([], $this->panel()->save($scope, $data));

        self::assertSame([
            'first_order|en|default 12',
            'first_order|et|default 11 fallback',
            'first_order||default 11',
            'welcome|en|default optin fallback',
            'welcome||default optin',
        ], $this->rows(0), 'the workflow of no language stays for the modes single and c');
        self::assertSame($before, $sent());
        self::assertSame($values, $this->panel()->values($scope)['triggers']);
    }

    public function testAnAccountPerLanguageChecksEachLanguageAgainstItsOwnAccountsList(): void
    {
        $layout = new StoreLayout();
        $layout->setLanguageMode(StoreLayout::MODE_ACCOUNT_PER_LANGUAGE, 0);
        $layout->setFallbackLanguage('en', 0);
        $this->accounts->save(new Account(0, 'en', 'demo', 'api-user', 'not-a-real-password'));
        $this->accounts->save(new Account(0, 'et', 'demo-et', 'api-user', 'not-a-real-password'));
        $this->mappings->upsert(0, 'welcome', '', 'default', 11);
        $scope = $this->scope(['en' => 'English', 'et' => 'Eesti', 'lv' => 'Latviešu']);
        $shop = (int) \Configuration::get('PS_SHOP_DEFAULT');

        self::assertSame([21], array_column($this->panel()->workflows($scope, 'et')['workflows'], 'id'));
        self::assertSame(['workflows' => [], 'error' => null], $this->panel()->workflows($scope, 'lv'), 'a language without its own account has nothing to list');

        $data = ['triggers' => ['welcome' => ['enabled' => true, 'languages' => ['en' => '12', 'et' => '12', 'lv' => ''], 'fallback' => '']], 'cart_delay' => '30'];
        self::assertSame(['welcome.languages.et'], array_column($this->panel()->save($scope, $data), 'field'), 'workflow 12 is not in the Estonian account\'s list');

        $data['triggers']['welcome']['languages']['et'] = '21';
        self::assertSame([], $this->panel()->save($scope, $data));
        self::assertSame(['welcome|en|en 12', 'welcome|et|et 21', 'welcome||default 11'], $this->rows(0), 'the card\'s own workflow is left as it is');
        $match = $this->router()->resolve('welcome', $shop, 'et');
        self::assertSame([21, 'demo-et'], [$match->workflowId(), $match->account()->subdomain()]);
        self::assertSame(['en' => '12', 'et' => '21', 'lv' => ''], $this->panel()->values($scope)['triggers']['welcome']['languages']);
        // The warning "Some languages have no workflow" reads the rows as the Router does (CON-445).
        self::assertSame('en', $this->panel()->values($scope)['account_fallback']);
    }

    /**
     * Mode `a` draws the cards of mode `b` (WooCommerce's and Shopify's): no "None". A store that
     * saved its mode-`a` rows with "None" (no default fallback) keeps sending the same workflows
     * through a save of what the page shows: the radio stands on the fallback account's language,
     * whose row the Router took before, or, without one among the languages, on a language without
     * a workflow, which sends nothing as "None" did.
     *
     * @dataProvider accountFallbacks
     *
     * @param array<string, string> $fallbacks the radio of each automation
     * @param list<string> $expected what is sent to en, et and lv, before and after
     */
    public function testAStoredModeAWithNoneAsTheFallbackSendsTheSameAfterASaveOfThePage(string $accountFallback, array $fallbacks, array $expected): void
    {
        $layout = new StoreLayout();
        $layout->setLanguageMode(StoreLayout::MODE_ACCOUNT_PER_LANGUAGE, 0);
        if ($accountFallback !== '') {
            $layout->setFallbackLanguage($accountFallback, 0);
        }
        $this->accounts->save(new Account(0, 'en', 'demo', 'api-user', 'not-a-real-password'));
        $this->accounts->save(new Account(0, 'et', 'demo-et', 'api-user', 'not-a-real-password'));
        $settings = new AutomationSettings();
        $settings->setEnabled('welcome', true, 0);
        $settings->setEnabled('first_order', true, 0);
        // As a mode-`a` save stored it before: a workflow per language, "None" as the fallback.
        $this->mappings->upsert(0, 'welcome', 'en', 'en', 12);
        $this->mappings->upsert(0, 'first_order', 'et', 'et', 21);
        $this->mappings->upsert(0, 'first_order', '', 'default', 11);
        $scope = $this->scope(['en' => 'English', 'et' => 'Eesti', 'lv' => 'Latviešu']);
        $shop = (int) \Configuration::get('PS_SHOP_DEFAULT');
        $sent = function () use ($shop): array {
            $router = $this->router();
            $sent = [];
            foreach (['welcome', 'first_order'] as $trigger) {
                foreach (['en', 'et', 'lv'] as $language) {
                    $match = $router->resolve($trigger, $shop, $language);
                    $sent[] = $match === null ? '-' : $match->workflowId() . '@' . $match->account()->subdomain();
                }
            }

            return $sent;
        };
        $before = $sent();
        self::assertSame($expected, $before);

        $values = $this->panel()->values($scope)['triggers'];
        self::assertSame($fallbacks, ['welcome' => $values['welcome']['fallback'], 'first_order' => $values['first_order']['fallback']]);
        self::assertSame(['en' => '12', 'et' => '', 'lv' => ''], $values['welcome']['languages']);

        // What the page posts in mode `a`: each language's select and the fallback radio.
        $data = ['cart_delay' => '30', 'triggers' => []];
        foreach (['welcome', 'first_order'] as $trigger) {
            $data['triggers'][$trigger] = ['enabled' => true, 'languages' => $values[$trigger]['languages'], 'fallback' => $values[$trigger]['fallback']];
        }
        self::assertSame([], $this->panel()->save($scope, $data));

        self::assertSame($before, $sent());
        self::assertContains('first_order||default 11', $this->rows(0), 'the workflow of no language stays for the modes single and c');
        self::assertSame($values, $this->panel()->values($scope)['triggers']);
    }

    /**
     * @return iterable<string, array{string, array<string, string>, list<string>}>
     */
    public static function accountFallbacks(): iterable
    {
        yield 'the fallback account is English' => ['en', ['welcome' => 'en', 'first_order' => 'en'], ['12@demo', '12@demo', '12@demo', '-', '21@demo-et', '-']];
        yield 'no fallback account' => ['', ['welcome' => 'et', 'first_order' => 'en'], ['12@demo', '-', '-', '-', '21@demo-et', '-']];
    }

    public function testOutsideAnAccountPerLanguageNoFallbackAccountIsNamed(): void
    {
        (new StoreLayout())->setFallbackLanguage('en', 0);

        $values = $this->panel()->values($this->scope(['en' => 'English', 'et' => 'Eesti']));

        self::assertSame('', $values['account_fallback']);
        self::assertIsString($values['cart_rule_url']);
    }

    public function testAnAutomationsDiscountCodeSavesItsSwitchRuleAndDaysAndReadsBack(): void
    {
        $scope = $this->scope(['en' => 'English']);
        $data = [
            'triggers' => [
                'welcome' => ['enabled' => true, 'workflow' => '11', 'discount' => ['enabled' => true, 'rule' => '41', 'days' => '14']],
                'first_order' => ['enabled' => false, 'discount' => ['enabled' => true, 'rule' => '', 'days' => '0']],
                'abandoned_cart' => ['enabled' => true, 'discount' => ['enabled' => false, 'rule' => '42', 'days' => '900']],
            ],
            'cart_delay' => '30',
        ];

        self::assertSame([], $this->panel()->save($scope, $data), 'a hidden block (its card or its switch off) refuses nothing');

        $triggers = $this->panel()->values($scope)['triggers'];
        self::assertSame(['enabled' => true, 'rule' => 41, 'days' => 14], $triggers['welcome']['discount']);
        self::assertSame(['enabled' => true, 'rule' => 0, 'days' => 7], $triggers['first_order']['discount'], 'the switch is kept; the days at fault are not saved');
        self::assertSame(['enabled' => false, 'rule' => 42, 'days' => 7], $triggers['abandoned_cart']['discount']);

        // A save that posts no discount block leaves the stored one as it is.
        self::assertSame([], $this->panel()->save($scope, ['triggers' => ['welcome' => ['enabled' => true, 'workflow' => '11']], 'cart_delay' => '30']));
        self::assertSame(['enabled' => true, 'rule' => 41, 'days' => 14], $this->panel()->values($scope)['triggers']['welcome']['discount']);
    }

    /**
     * @return array<string, array{array<string, mixed>, list<string>}>
     */
    public function refusedDiscounts(): array
    {
        return [
            'the switch on without a rule' => [['enabled' => true, 'rule' => '', 'days' => '7'], ['welcome.discount.rule']],
            'rule 0' => [['enabled' => true, 'rule' => '0', 'days' => '7'], ['welcome.discount.rule']],
            '0 days' => [['enabled' => true, 'rule' => '41', 'days' => '0'], ['welcome.discount.days']],
            '366 days' => [['enabled' => true, 'rule' => '41', 'days' => '366'], ['welcome.discount.days']],
            'no days' => [['enabled' => true, 'rule' => '41', 'days' => ''], ['welcome.discount.days']],
            'neither' => [['enabled' => true, 'rule' => 'x', 'days' => '1.5'], ['welcome.discount.rule', 'welcome.discount.days']],
        ];
    }

    /**
     * @dataProvider refusedDiscounts
     *
     * @param array<string, mixed> $discount
     * @param list<string> $fields
     */
    public function testADiscountCodeThatIsOnWithoutARuleOrWithDaysOutsideOneTo365IsRefused(array $discount, array $fields): void
    {
        $scope = $this->scope(['en' => 'English']);

        $errors = $this->panel()->save($scope, ['triggers' => ['welcome' => ['enabled' => true, 'workflow' => '11', 'discount' => $discount]], 'cart_delay' => '30']);

        self::assertSame($fields, array_column($errors, 'field'));
        self::assertNotContains('', array_column($errors, 'message'));
        $values = $this->panel()->values($scope)['triggers']['welcome'];
        self::assertSame([false, '', self::NO_DISCOUNT], [$values['enabled'], $values['workflow'], $values['discount']], 'nothing was written');
    }

    public function testTheDiscountPickerListsTheRulesOfTheScopesShop(): void
    {
        $rule = new \CartRule();
        $rule->name = array_fill_keys(array_map('intval', \Language::getIDs(false)), 'Panel picker rule');
        $rule->date_from = date('Y-m-d H:i:s', time() - 3600);
        $rule->date_to = date('Y-m-d H:i:s', time() + 86400);
        $rule->reduction_percent = 10;
        $rule->active = false;
        self::assertTrue($rule->add());
        try {
            $listed = $this->panel()->discountRules($this->scope(['en' => 'English']))['rules'];
            self::assertContains(['id' => (int) $rule->id, 'name' => 'Panel picker rule'], $listed);
            self::assertSame(['rules' => []], $this->panel()->discountRules(new SetupScope(true, true, true, 0, ['en' => 'English'])), 'separate stores without a shop list nothing');
        } finally {
            $rule->delete();
        }
    }

    public function testSeparateStoresWithAllShopsSelectedSaveNothing(): void
    {
        $scope = new SetupScope(true, true, true, 0, ['en' => 'English']);

        self::assertNull($this->panel()->values($scope));
        self::assertSame('', $this->panel()->save($scope, ['triggers' => [], 'cart_delay' => '30'])[0]['field']);
        self::assertSame(['workflows' => [], 'error' => null], $this->panel()->workflows($scope, 'default'));
    }

    private function panel(): AutomationsPanel
    {
        return (new SetupPanels($this->services, \Context::getContext()->getTranslator()))->automations();
    }

    private function router(): Router
    {
        \Configuration::resetStaticCache();
        \Configuration::loadConfiguration();

        return $this->services->get(Router::class);
    }

    /**
     * One shop, its languages as given.
     *
     * @param array<string, string> $languages
     */
    private function scope(array $languages): SetupScope
    {
        return new SetupScope(false, false, true, 0, $languages);
    }

    /**
     * @return list<string> "trigger|language|account workflow[ fallback]" of a shop's rows, sorted
     */
    private function rows(int $idShop): array
    {
        $rows = array_map(
            static fn (array $row): string => $row['trigger'] . '|' . $row['language'] . '|' . $row['account_key'] . ' '
                . ($row['workflow_id'] ?? 'optin') . ($row['is_default_fallback'] ? ' fallback' : ''),
            $this->mappings->rows($idShop)
        );
        sort($rows);

        return $rows;
    }
}
