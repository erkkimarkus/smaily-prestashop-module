<?php
declare(strict_types=1);

namespace Tests\Integration\Admin;

use PrestaShop\Module\SmailyForPrestaShop\Admin\Setup\SetupPanels;
use PrestaShop\Module\SmailyForPrestaShop\Admin\Setup\SetupScope;
use PrestaShop\Module\SmailyForPrestaShop\Config\AdminSettings;
use PrestaShop\Module\SmailyForPrestaShop\Config\ContactsSettings;
use PrestaShop\Module\SmailyForPrestaShop\Contacts\ContactSyncMode;
use PrestaShop\Module\SmailyForPrestaShop\Http\Transport;
use PrestaShop\Module\SmailyForPrestaShop\Queue\EventQueue;
use PrestaShop\Module\SmailyForPrestaShop\Queue\Handler\ContactSyncHandler;
use PrestaShop\Module\SmailyForPrestaShop\Runtime\Provider\QueueProvider;
use PrestaShop\Module\SmailyForPrestaShop\Runtime\Services;
use PrestaShop\Module\SmailyForPrestaShop\Scope\StoreLayout;
use PrestaShop\Module\SmailyForPrestaShop\Smaily\AccountStore;
use Tests\Integration\Fixtures\SmailyMockServer;
use Tests\Integration\IntegrationTestCase;

require_once __DIR__ . '/../Fixtures/SmailyMockServer.php';

/**
 * The Initial setup's panels in the shop, against the Smaily mock: the Connection panel reduces a
 * pasted URL, keeps a stored password only for its own account, saves a refused account as "Not
 * connected", saves an account per language with a fallback, and never hands a password back; the
 * Contacts panel's save is what starts contact sync on a new install; the setup reaches its steps
 * from what was saved, and Finish marks it done.
 */
final class SetupPanelsTest extends IntegrationTestCase
{
    private const USERNAME = 'api-user';

    private const PASSWORD = 'not-a-real-password';

    private const ACCOUNT_TABLE = 'smaily_connect_account';

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
        AdminSettings::SETUP_STATE,
    ];

    private SmailyMockServer $mock;

    private Services $services;

    /** @var list<array<string, mixed>> */
    private array $savedAccounts = [];

    /** @var array<string, list<array<string, mixed>>> */
    private array $savedKeys = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->mock = SmailyMockServer::start();
        $this->mock->acceptCredentials(self::USERNAME, self::PASSWORD);
        $mock = $this->mock->transport();
        $this->services = Services::fromProviders(Services::PROVIDERS);
        $this->services->set(Transport::class, static fn (): Transport => $mock);
        $this->savedAccounts = self::db()->executeS('SELECT * FROM `' . _DB_PREFIX_ . self::ACCOUNT_TABLE . '`', true, false) ?: [];
        self::db()->delete(self::ACCOUNT_TABLE);
        self::db()->delete(EventQueue::TABLE);
        foreach (self::KEYS as $key) {
            $this->savedKeys[$key] = (array) self::db()->executeS('SELECT * FROM `' . _DB_PREFIX_ . 'configuration` WHERE `name` = \'' . pSQL($key) . '\'');
            \Configuration::deleteByName($key);
        }
    }

    protected function tearDown(): void
    {
        if (defined('_PS_ROOT_DIR_')) {
            self::db()->delete(EventQueue::TABLE);
            self::db()->delete(self::ACCOUNT_TABLE);
            foreach ($this->savedAccounts as $row) {
                self::db()->insert(self::ACCOUNT_TABLE, array_map(static fn ($value) => pSQL((string) $value), $row));
            }
            foreach (self::KEYS as $key) {
                \Configuration::deleteByName($key);
                foreach ($this->savedKeys[$key] as $row) {
                    self::db()->insert('configuration', $row, true);
                }
            }
            \Configuration::resetStaticCache();
            \Configuration::loadConfiguration();
        }
        parent::tearDown();
    }

    public function testConnectReducesAPastedUrlAndSavesARefusedAccountAsNotConnected(): void
    {
        $panel = $this->panels()->connection();
        $scope = $this->scope(['en' => 'English']);

        $refused = $panel->save($scope, ['subdomain' => 'https://Demo.sendsmaily.net/', 'username' => self::USERNAME, 'password' => 'a-wrong-password']);
        self::assertSame([], $refused['errors']);
        self::assertFalse($refused['connected']);
        self::assertSame('Smaily rejected these credentials. Check the subdomain, username and password.', $refused['error']);
        self::assertSame('demo', $this->account(0, '')['subdomain']);

        $accepted = $panel->save($scope, ['subdomain' => 'demo.sendsmaily.net', 'username' => self::USERNAME, 'password' => self::PASSWORD]);
        self::assertSame(['errors' => [], 'connected' => true, 'error' => null], $accepted);

        $values = $panel->values($scope);
        self::assertSame(['demo', self::USERNAME, true, 'single'], [$values['subdomain'], $values['username'], $values['has_password'], $values['language_mode']]);
        self::assertStringNotContainsString(self::PASSWORD, (string) json_encode($values));
        self::assertStringNotContainsString(self::PASSWORD, (string) json_encode($this->account(0, '')), 'stored encrypted');
    }

    public function testAnEmptyPasswordKeepsTheStoredOneOnlyForTheSameAccount(): void
    {
        $panel = $this->panels()->connection();
        $scope = $this->scope(['en' => 'English']);
        $panel->save($scope, ['subdomain' => 'demo', 'username' => self::USERNAME, 'password' => self::PASSWORD]);

        $kept = $panel->save($scope, ['subdomain' => 'DEMO', 'username' => self::USERNAME, 'password' => '']);
        self::assertTrue($kept['connected'], 'the stored password was kept');

        $changed = $panel->save($scope, ['subdomain' => 'other', 'username' => self::USERNAME, 'password' => '']);
        self::assertSame([['field' => 'password', 'message' => 'The subdomain or username changed — enter the password of this account.']], $changed['errors']);
        self::assertSame('demo', $this->account(0, '')['subdomain'], 'nothing was written');

        self::assertSame(
            [['field' => 'subdomain', 'message' => 'Subdomain and username are required.']],
            $panel->save($scope, ['subdomain' => '', 'username' => self::USERNAME])['errors']
        );
        self::assertSame(
            'The subdomain must be a plain Smaily subdomain such as "demo": letters, digits and hyphens only.',
            $panel->save($scope, ['subdomain' => 'de mo', 'username' => self::USERNAME, 'password' => 'x'])['errors'][0]['message']
        );
    }

    public function testTestConnectionAsksSmailyWithTheTypedValuesAndSavesNothing(): void
    {
        $panel = $this->panels()->connection();
        $scope = $this->scope(['en' => 'English']);

        self::assertSame(['errors' => [], 'connected' => true, 'error' => null], $panel->test($scope, ['subdomain' => 'https://demo.sendsmaily.net', 'username' => self::USERNAME, 'password' => self::PASSWORD]));
        self::assertNull($this->account(0, ''), 'a test saves nothing');
        self::assertSame('Smaily rejected these credentials. Check the subdomain, username and password.', $panel->test($scope, ['subdomain' => 'demo', 'username' => self::USERNAME, 'password' => 'wrong'])['error']);
        self::assertSame(
            ['subdomain', 'username', 'password'],
            array_column($panel->test($scope, ['subdomain' => '', 'username' => '', 'password' => ''])['errors'], 'field')
        );
        self::assertCount(2, $this->mock->requests(), 'empty fields ask nothing');
    }

    public function testAnAccountPerLanguageSavesEachLanguageAndTheFallback(): void
    {
        $panel = $this->panels()->connection();
        $scope = $this->scope(['en' => 'English', 'et' => 'Eesti']);
        self::assertSame(['single', 'a', 'b', 'c'], $scope->languageModes());

        $missingFallback = $panel->save($scope, [
            'language_mode' => 'a',
            'accounts' => ['en' => ['subdomain' => 'demo', 'username' => self::USERNAME, 'password' => self::PASSWORD]],
        ]);
        self::assertSame([['field' => 'fallback_language', 'message' => 'Pick the default fallback account.']], $missingFallback['errors']);

        $saved = $panel->save($scope, [
            'language_mode' => 'a',
            'fallback_language' => 'en',
            'accounts' => [
                'en' => ['subdomain' => 'demo', 'username' => self::USERNAME, 'password' => self::PASSWORD],
                'et' => ['subdomain' => 'demo-et', 'username' => self::USERNAME, 'password' => self::PASSWORD],
            ],
        ]);
        self::assertSame([], $saved['errors']);
        self::assertTrue($saved['connected']);
        self::assertSame('demo', $this->account(0, 'en')['subdomain']);
        self::assertSame('demo-et', $this->account(0, 'et')['subdomain']);
        self::assertSame('demo', $this->account(0, '')['subdomain'], 'the fallback is the account of no language');
        $layout = new StoreLayout();
        self::assertSame(['a', 'en'], [$layout->languageMode(0), $layout->fallbackLanguage(0)]);

        // A language left empty has no account of its own: the fallback takes its contacts.
        $panel->save($scope, [
            'language_mode' => 'a',
            'fallback_language' => 'en',
            'accounts' => ['en' => ['subdomain' => 'demo', 'username' => self::USERNAME, 'password' => ''], 'et' => []],
        ]);
        self::assertNull($this->account(0, 'et'));
    }

    public function testASaveForAllShopsOfAMultishopStoreDoesNotOfferAnAccountPerLanguage(): void
    {
        $oneStore = new SetupScope(true, false, true, 0, ['en' => 'English', 'et' => 'Eesti']);
        self::assertSame(['single', 'b', 'c'], $oneStore->languageModes());
        self::assertSame(0, $oneStore->targetShop());

        $separate = new SetupScope(true, true, true, 0, ['en' => 'English', 'et' => 'Eesti']);
        self::assertNull($separate->targetShop(), '"All shops" in separate stores saves nothing');
        self::assertSame(
            'These shops are set up as separate stores. Reload the page and set each shop up on its own.',
            $this->panels()->connection()->save($separate, ['subdomain' => 'demo', 'username' => self::USERNAME, 'password' => self::PASSWORD])['errors'][0]['message']
        );
        self::assertSame(['single', 'a', 'b', 'c'], (new SetupScope(true, true, true, 2, ['en' => 'English', 'et' => 'Eesti']))->languageModes());

        $unanswered = new SetupScope(true, false, false, 0, ['en' => 'English']);
        self::assertTrue($unanswered->asksLayoutFirst());
        self::assertSame('shape', $this->panels()->connection()->save($unanswered, [])['errors'][0]['field']);
    }

    public function testTheShopLayoutIsStoredOnceWhenFirstAsked(): void
    {
        $panel = $this->panels()->shape();
        self::assertNull($panel->values(new SetupScope(false, false, false, 0, [])), 'one shop: no question');
        $first = new SetupScope(true, false, false, 0, [], ['Shop A', 'Shop B']);
        self::assertSame(['shape' => 'one', 'first' => true, 'shops' => ['Shop A', 'Shop B']], $panel->values($first));

        self::assertSame([], $panel->save($first, ['shape' => 'separate']));
        $layout = new StoreLayout();
        self::assertTrue($layout->layoutChosen());
        self::assertTrue($layout->separateStores());
        self::assertSame('shape', $panel->save($first, ['shape' => 'sideways'])[0]['field']);
    }

    public function testContactSyncStartsOnlyWhenTheContactsStepIsSaved(): void
    {
        $panels = $this->panels();
        $scope = $this->scope(['en' => 'English']);
        $panels->connection()->save($scope, ['subdomain' => 'demo', 'username' => self::USERNAME, 'password' => self::PASSWORD]);
        $idShop = (int) \Configuration::get('PS_SHOP_DEFAULT');
        $queue = new EventQueue();

        $before = $queue->enqueue(ContactSyncHandler::EVENT_TYPE, $idShop, $this->payload('shopper1@example.test'));
        $this->services->get(QueueProvider::FLUSHER)->flush();
        self::assertSame([EventQueue::STATUS_SKIPPED, ContactSyncHandler::SKIPPED_SYNC_OFF], $this->queueRow($before));

        $values = $panels->contacts()->values($scope);
        self::assertSame(['subscribers', false, false, ['first_name', 'last_name', 'birthday', 'website'], true], [$values['mode'], $values['active'], $values['guests'], $values['fields'], $values['checkout_checkbox']]);
        self::assertSame('mode', $panels->contacts()->save($scope, ['mode' => 'everyone'])[0]['field']);
        self::assertSame([], $panels->contacts()->save($scope, ['mode' => 'all_customers', 'fields' => ['first_name'], 'guests' => true, 'checkout_checkbox' => false]));

        $settings = new ContactsSettings();
        self::assertTrue($settings->syncActive($idShop));
        self::assertSame(ContactSyncMode::ALL_CUSTOMERS, $settings->mode($idShop)->mode());
        self::assertTrue($settings->mode($idShop)->includesNewsletterGuests());
        self::assertSame(['first_name'], $settings->fields($idShop));
        self::assertFalse($settings->checkoutCheckbox($idShop));

        $after = $queue->enqueue(ContactSyncHandler::EVENT_TYPE, $idShop, $this->payload('shopper2@example.test'));
        $this->services->get(QueueProvider::FLUSHER)->flush();
        self::assertSame(EventQueue::STATUS_SENT, $this->queueRow($after)[0]);
    }

    public function testASwitchedOffSyncStaysOffUnlessTheMerchantTicksIt(): void
    {
        \Configuration::updateGlobalValue(ContactsSettings::MODE, ContactSyncMode::SUBSCRIBERS);
        \Configuration::updateGlobalValue(ContactsSettings::ENABLED, '0');
        \Configuration::updateGlobalValue(ContactsSettings::CONFIRMED, '0');
        $panel = $this->panels()->contacts();
        $scope = $this->scope(['en' => 'English']);

        $panel->save($scope, ['mode' => 'subscribers', 'enabled' => false]);
        self::assertFalse((new ContactsSettings())->syncActive(0));
        $panel->save($scope, ['mode' => 'subscribers', 'enabled' => true]);
        self::assertTrue((new ContactsSettings())->syncActive(0));
    }

    public function testTheSetupReachesItsStepsFromWhatWasSavedAndFinishMarksItDone(): void
    {
        $panels = $this->panels();
        $scope = $this->scope(['en' => 'English']);
        $progress = $panels->progress();
        self::assertSame(1, $progress->reachedStep($scope));

        $panels->connection()->save($scope, ['subdomain' => 'demo', 'username' => self::USERNAME, 'password' => self::PASSWORD]);
        self::assertSame(2, $progress->reachedStep($scope));
        $panels->contacts()->save($scope, ['mode' => 'subscribers']);
        self::assertSame(3, $progress->reachedStep($scope));

        self::assertTrue($progress->finish());
        self::assertSame(AdminSettings::SETUP_DONE, (new AdminSettings())->setupState());
        self::assertSame(5, $progress->reachedStep($scope));
    }

    private function panels(): SetupPanels
    {
        return new SetupPanels($this->services, \Context::getContext()->getTranslator());
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
     * @return array<string, string>|null
     */
    private function account(int $idShop, string $language): ?array
    {
        $row = self::db()->getRow('SELECT `subdomain`, `username`, `password_enc` FROM `' . _DB_PREFIX_ . self::ACCOUNT_TABLE . '` WHERE `id_shop` = ' . $idShop . ' AND `language` = \'' . pSQL($language) . '\'', false);

        return is_array($row) ? $row : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(string $email): array
    {
        return [
            'email' => $email,
            'source' => 'customer',
            'subscribed' => true,
            'consent_changed' => true,
            'id_lang' => (int) \Configuration::get('PS_LANG_DEFAULT'),
            'customer' => ['firstname' => 'Testfirst'],
        ];
    }

    /**
     * @return array{0: string, 1: ?string}
     */
    private function queueRow(int $id): array
    {
        $row = self::db()->getRow('SELECT `status`, `last_error` FROM `' . _DB_PREFIX_ . EventQueue::TABLE . '` WHERE `id` = ' . $id, false);

        return [(string) $row['status'], $row['last_error'] === null ? null : (string) $row['last_error']];
    }
}
