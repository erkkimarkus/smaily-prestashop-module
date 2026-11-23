<?php
declare(strict_types=1);

namespace Tests\Integration\Contacts;

use PrestaShop\Module\SmailyForPrestaShop\Config\ContactsSettings;
use PrestaShop\Module\SmailyForPrestaShop\Contacts\ContactSyncMode;
use PrestaShop\Module\SmailyForPrestaShop\Http\Response;
use PrestaShop\Module\SmailyForPrestaShop\Http\Transport;
use PrestaShop\Module\SmailyForPrestaShop\Queue\EventQueue;
use PrestaShop\Module\SmailyForPrestaShop\Queue\Flusher;
use PrestaShop\Module\SmailyForPrestaShop\Queue\Handler\ContactSyncHandler;
use PrestaShop\Module\SmailyForPrestaShop\Runtime\Provider\QueueProvider;
use PrestaShop\Module\SmailyForPrestaShop\Runtime\Services;
use PrestaShop\Module\SmailyForPrestaShop\Scope\StoreLayout;
use PrestaShop\Module\SmailyForPrestaShop\Smaily\Account;
use PrestaShop\Module\SmailyForPrestaShop\Smaily\AccountStore;
use Tests\Integration\Fixtures\SmailyMockServer;
use Tests\Integration\IntegrationTestCase;
use Tests\Integration\MultistoreShop;

require_once __DIR__ . '/../Fixtures/SmailyMockServer.php';
require_once __DIR__ . '/../MultistoreShop.php';

/**
 * End to end in the shop: a `contact.sync` row that the event queue holds is flushed by the
 * runtime's Queue\Flusher through the contact sync handler to the Smaily mock, through the account
 * of its shop and language, and ends `sent`; a row of a shop whose contact sync is off ends
 * `skipped`. No `last_error` and no log line names the contact.
 *
 * Separate stores of two shops: the default shop, with an account for its language and one for no
 * language, and an added shop with an account of its own.
 */
final class ContactSyncFlushTest extends IntegrationTestCase
{
    use MultistoreShop;

    private const PASSWORD = 'not-a-real-password';

    private const ACCOUNT_TABLE = 'smaily_connect_account';

    private const KEYS = [
        ContactsSettings::ENABLED,
        ContactsSettings::CONFIRMED,
        ContactsSettings::MODE,
        ContactsSettings::GUESTS,
        ContactsSettings::FIELDS,
        StoreLayout::SEPARATE_STORES,
        StoreLayout::LANGUAGE_MODE,
        StoreLayout::FALLBACK_LANGUAGE,
    ];

    private SmailyMockServer $mock;

    /** @var list<array<string, mixed>> */
    private array $savedAccounts = [];

    /** @var \ArrayObject<int, string> the first DNS label of the host of each request */
    private \ArrayObject $accountsCalled;

    private int $defaultShop;

    private int $shop;

    private int $idLang;

    private string $language;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mock = SmailyMockServer::start();
        $this->mock->acceptCredentials('api-user', self::PASSWORD);
        $this->accountsCalled = new \ArrayObject();
        $this->savedAccounts = self::db()->executeS('SELECT * FROM `' . _DB_PREFIX_ . self::ACCOUNT_TABLE . '`', true, false) ?: [];
        self::db()->delete(self::ACCOUNT_TABLE);
        self::db()->delete(EventQueue::TABLE);
        $this->deleteKeys();
        $this->openMultistore();
        $this->defaultShop = $this->defaultShopId();
        $this->shop = $this->addShop((int) \Shop::getGroupFromShop($this->defaultShop));
        $this->idLang = (int) \Configuration::get('PS_LANG_DEFAULT');
        $this->language = strtolower((string) \Language::getIsoById($this->idLang));

        \Configuration::updateGlobalValue(StoreLayout::SEPARATE_STORES, '1');
        $this->writeShopValue(StoreLayout::LANGUAGE_MODE, StoreLayout::MODE_ACCOUNT_PER_LANGUAGE, $this->defaultShop);
        \Configuration::updateGlobalValue(ContactsSettings::ENABLED, '1');
        \Configuration::updateGlobalValue(ContactsSettings::CONFIRMED, '1');
        \Configuration::updateGlobalValue(ContactsSettings::MODE, ContactSyncMode::SUBSCRIBERS);
        \Configuration::updateGlobalValue(ContactsSettings::GUESTS, '1');
        \Configuration::updateGlobalValue(ContactsSettings::FIELDS, 'store,first_name,last_name');
        $this->saveAccount(0, '', 'globalleftover', self::PASSWORD);
        $this->saveAccount($this->defaultShop, $this->language, 'defaultshoplanguage', self::PASSWORD);
        $this->saveAccount($this->defaultShop, '', 'defaultshop', self::PASSWORD);
        $this->saveAccount($this->shop, '', 'addedshop', self::PASSWORD);
    }

    protected function tearDown(): void
    {
        if (defined('_PS_ROOT_DIR_')) {
            self::db()->delete(EventQueue::TABLE);
            self::db()->delete(self::ACCOUNT_TABLE);
            foreach ($this->savedAccounts as $row) {
                self::db()->insert(self::ACCOUNT_TABLE, array_map(static fn ($value) => pSQL((string) $value), $row));
            }
            $this->deleteKeys();
            $this->closeMultistore();
        }
        parent::tearDown();
    }

    public function testARowGoesThroughTheAccountOfItsShopAndLanguageAndEndsSent(): void
    {
        $queue = new EventQueue();
        $first = $queue->enqueue(ContactSyncHandler::EVENT_TYPE, $this->defaultShop, $this->payload('shopper1@example.test'), null, $this->language);
        $second = $queue->enqueue(ContactSyncHandler::EVENT_TYPE, $this->shop, $this->payload('shopper2@example.test'), null, $this->language);

        self::assertSame(2, $this->flusher()->flush());

        self::assertSame([EventQueue::STATUS_SENT, EventQueue::STATUS_SENT], [$this->status($first), $this->status($second)]);
        self::assertSame(['defaultshoplanguage', 'addedshop'], $this->accountsCalled->getArrayCopy());
        $requests = $this->mock->requests();
        self::assertCount(2, $requests);
        self::assertSame(['POST', '/api/contact.php', true], [$requests[0]['method'], $requests[0]['path'], $requests[0]['authorized']]);
        self::assertSame([[
            'email' => 'shopper1@example.test',
            'is_unsubscribed' => 0,
            'store' => (string) \ShopUrl::getMainShopDomain($this->defaultShop),
            'language' => $this->language,
            'first_name' => 'Testfirst',
            'last_name' => 'Testlast',
        ]], $requests[0]['body']);
        self::assertSame('shop' . $this->shop . '.example.invalid', $requests[1]['body'][0]['store']);
    }

    public function testARowOfAShopWhoseContactSyncIsOffEndsSkipped(): void
    {
        $this->writeShopValue(ContactsSettings::ENABLED, '0', $this->shop);
        $queue = new EventQueue();
        $off = $queue->enqueue(ContactSyncHandler::EVENT_TYPE, $this->shop, $this->payload('shopper2@example.test'), null, $this->language);
        $on = $queue->enqueue(ContactSyncHandler::EVENT_TYPE, $this->defaultShop, $this->payload('shopper1@example.test'), null, $this->language);

        $this->flusher()->flush();

        self::assertSame(EventQueue::STATUS_SKIPPED, $this->status($off));
        self::assertSame(ContactSyncHandler::SKIPPED_SYNC_OFF, $this->row($off)['last_error']);
        self::assertSame(EventQueue::STATUS_SENT, $this->status($on));
        self::assertSame(['defaultshoplanguage'], $this->accountsCalled->getArrayCopy());
    }

    /**
     * Acceptance 6: what the queue and the shop's log keep of a failure names no contact and quotes
     * no request.
     */
    public function testNoLastErrorOrLogLineNamesTheContact(): void
    {
        $this->saveAccount($this->shop, '', 'addedshop', 'a-wrong-fake-password');
        $lastLog = (int) self::db()->getValue('SELECT MAX(`id_log`) FROM `' . _DB_PREFIX_ . 'log`', false);
        $queue = new EventQueue();
        $ids = [
            $queue->enqueue(ContactSyncHandler::EVENT_TYPE, $this->shop, $this->payload('shopper2@example.test')),
            $queue->enqueue(ContactSyncHandler::EVENT_TYPE, $this->defaultShop, $this->payload('shopper1@example.test', ['subscribed' => false, 'consent_changed' => false])),
            $queue->enqueue(ContactSyncHandler::EVENT_TYPE, $this->defaultShop, ['email' => 'shopper1@example.test', 'customer' => ['firstname' => 'Testfirst']]),
        ];

        $this->flusher()->flush();

        $errors = array_map(fn (int $id): string => (string) $this->row($id)['last_error'], $ids);
        self::assertSame(['permanent_http_401: Smaily API credentials were rejected', ContactSyncHandler::SKIPPED_NOT_IN_AUDIENCE, ContactSyncHandler::FAILED_MALFORMED_PAYLOAD], $errors);
        $logs = array_column(self::db()->executeS('SELECT `message` FROM `' . _DB_PREFIX_ . 'log` WHERE `id_log` > ' . $lastLog, true, false) ?: [], 'message');
        foreach (array_merge($errors, $logs) as $text) {
            foreach (['shopper', 'example.test', 'Testfirst', 'Testlast', '{'] as $quoted) {
                self::assertStringNotContainsString($quoted, $text);
            }
        }
    }

    private function flusher(): Flusher
    {
        $mock = $this->mock->transport();
        $recorded = $this->accountsCalled;
        $services = Services::fromProviders(Services::PROVIDERS);
        $services->set(Transport::class, static fn (): Transport => new class($mock, $recorded) implements Transport {
            private Transport $mock;

            /** @var \ArrayObject<int, string> */
            private \ArrayObject $recorded;

            /**
             * @param \ArrayObject<int, string> $recorded
             */
            public function __construct(Transport $mock, \ArrayObject $recorded)
            {
                $this->mock = $mock;
                $this->recorded = $recorded;
            }

            public function request(string $method, string $url, array $options = []): Response
            {
                $this->recorded[] = explode('.', (string) parse_url($url, PHP_URL_HOST))[0];

                return $this->mock->request($method, $url, $options);
            }
        });

        return $services->get(QueueProvider::FLUSHER);
    }

    /**
     * A customer's opt-in, in the shape that Story 2.3 enqueues.
     *
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private function payload(string $email, array $overrides = []): array
    {
        return $overrides + [
            'email' => $email,
            'source' => 'customer',
            'subscribed' => true,
            'consent_changed' => true,
            'checkout' => false,
            'id_lang' => $this->idLang,
            'customer' => ['firstname' => 'Testfirst', 'lastname' => 'Testlast', 'birthday' => '0000-00-00', 'website' => ''],
        ];
    }

    private function saveAccount(int $idShop, string $language, string $subdomain, string $password): void
    {
        $store = Services::fromProviders(Services::PROVIDERS)->get(AccountStore::class);
        self::assertTrue($store->save(new Account($idShop, $language, $subdomain, 'api-user', $password)));
    }

    private function writeShopValue(string $key, string $value, int $idShop): void
    {
        \Configuration::updateValue($key, $value, false, (int) \Shop::getGroupFromShop($idShop), $idShop);
    }

    private function deleteKeys(): void
    {
        foreach (self::KEYS as $key) {
            \Configuration::deleteByName($key);
        }
    }

    private function status(int $id): string
    {
        return (string) $this->row($id)['status'];
    }

    /**
     * @return array<string, string|null>
     */
    private function row(int $id): array
    {
        $row = self::db()->getRow('SELECT * FROM `' . _DB_PREFIX_ . EventQueue::TABLE . '` WHERE `id` = ' . $id, false);
        self::assertIsArray($row);

        return $row;
    }
}
