<?php
declare(strict_types=1);

namespace Tests\Integration\Contacts;

use PrestaShop\Module\SmailyForPrestaShop\Config\ContactsSettings;
use PrestaShop\Module\SmailyForPrestaShop\Contacts\ContactSyncMode;
use PrestaShop\Module\SmailyForPrestaShop\Contacts\Import\ContactImport;
use PrestaShop\Module\SmailyForPrestaShop\Contacts\Import\ContactSource;
use PrestaShop\Module\SmailyForPrestaShop\Contacts\Import\JobStore;
use PrestaShop\Module\SmailyForPrestaShop\Contacts\SubscriberPayloadBuilder;
use PrestaShop\Module\SmailyForPrestaShop\Database\SqlException;
use PrestaShop\Module\SmailyForPrestaShop\Http\Response;
use PrestaShop\Module\SmailyForPrestaShop\Http\Transport;
use PrestaShop\Module\SmailyForPrestaShop\Queue\EventQueue;
use PrestaShop\Module\SmailyForPrestaShop\Runtime\Services;
use PrestaShop\Module\SmailyForPrestaShop\Scheduler\Deadline;
use PrestaShop\Module\SmailyForPrestaShop\Scope\ScopeResolver;
use PrestaShop\Module\SmailyForPrestaShop\Scope\StoreLayout;
use PrestaShop\Module\SmailyForPrestaShop\Smaily\Account;
use PrestaShop\Module\SmailyForPrestaShop\Smaily\AccountStore;
use Tests\Integration\Fixtures\FailingQueries;
use Tests\Integration\Fixtures\SmailyMockServer;
use Tests\Integration\IntegrationTestCase;
use Tests\Integration\MultistoreShop;

require_once __DIR__ . '/../Fixtures/SmailyMockServer.php';
require_once __DIR__ . '/../MultistoreShop.php';
require_once __DIR__ . '/ContactRows.php';
require_once __DIR__ . '/../Fixtures/FailingQueries.php';

/**
 * The first import and the daily refresh in the shop, against the Smaily mock: they send the
 * consent mode's audience once, through the account of each contact's shop, never with a
 * subscription status, in pages that the tick's deadline bounds, and resume from their cursor.
 *
 * Separate stores: two added shops, A and B, each with an account of its own. The demo shop's
 * contact sync is off, so its demo customers are no part of any audience here.
 */
final class ContactImportTest extends IntegrationTestCase
{
    use ContactRows;
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
    ];

    private SmailyMockServer $mock;

    /** @var list<array<string, mixed>> */
    private array $savedAccounts = [];

    /** @var \ArrayObject<int, string> the first DNS label of the host of each request */
    private \ArrayObject $accountsCalled;

    /** @var \ArrayObject<string, int> HTTP status to answer instead of the mock, by account */
    private \ArrayObject $outages;

    private int $shopA;

    private int $shopB;

    private int $idLang;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mock = SmailyMockServer::start();
        $this->mock->acceptCredentials('api-user', self::PASSWORD);
        $this->accountsCalled = new \ArrayObject();
        $this->outages = new \ArrayObject();
        $this->savedAccounts = self::db()->executeS('SELECT * FROM `' . _DB_PREFIX_ . self::ACCOUNT_TABLE . '`', true, false) ?: [];
        self::db()->delete(self::ACCOUNT_TABLE);
        self::db()->delete(EventQueue::TABLE);
        $this->deleteJobs();
        $this->deleteKeys();
        $this->openMultistore();
        $group = (int) \Shop::getGroupFromShop($this->defaultShopId());
        $this->shopA = $this->addShop($group);
        $this->shopB = $this->addShop($group);
        $this->idLang = (int) \Configuration::get('PS_LANG_DEFAULT');

        \Configuration::updateGlobalValue(StoreLayout::SEPARATE_STORES, '1');
        \Configuration::updateGlobalValue(ContactsSettings::ENABLED, '1');
        \Configuration::updateGlobalValue(ContactsSettings::CONFIRMED, '1');
        \Configuration::updateGlobalValue(ContactsSettings::MODE, ContactSyncMode::SUBSCRIBERS);
        \Configuration::updateGlobalValue(ContactsSettings::GUESTS, '1');
        \Configuration::updateGlobalValue(ContactsSettings::FIELDS, 'store,first_name');
        $this->writeShopValue(ContactsSettings::ENABLED, '0', $this->defaultShopId());
        $this->saveAccount($this->shopA, 'shopa', self::PASSWORD);
        $this->saveAccount($this->shopB, 'shopb', self::PASSWORD);
    }

    protected function tearDown(): void
    {
        if (defined('_PS_ROOT_DIR_')) {
            $this->removeContactRows();
            $this->deleteJobs();
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

    /**
     * Acceptance 3 and 4: exactly the mode's audience, and no row carries a subscription status.
     *
     * @dataProvider audiences
     *
     * @param list<string> $expected
     */
    public function testTheFirstImportSendsExactlyTheModesAudience(string $mode, bool $guests, array $expected): void
    {
        \Configuration::updateGlobalValue(ContactsSettings::MODE, $mode);
        \Configuration::updateGlobalValue(ContactsSettings::GUESTS, $guests ? '1' : '0');
        $this->addCustomer($this->shopA, 'subscriber@example.test', true, $this->idLang);
        $this->addCustomer($this->shopA, 'customer@example.test', false, $this->idLang);
        $this->addCustomer($this->shopB, 'guest-checkout@example.test', false, $this->idLang, ['is_guest' => 1]);
        $this->addCustomer($this->shopB, 'subscribed-guest@example.test', true, $this->idLang, ['is_guest' => 1]);
        $this->addCustomer($this->shopA, 'deleted@example.test', true, $this->idLang, ['deleted' => 1]);
        $this->addNewsletterRow($this->shopB, 'block@example.test', true, $this->idLang);
        $this->addNewsletterRow($this->shopA, 'inactive-block@example.test', false, $this->idLang);
        $import = $this->import();

        $id = $import->start();
        $this->tickUntilFinished($import);

        $sent = $this->sentContacts();
        $emails = array_column($sent, 'email');
        sort($emails);
        sort($expected);
        self::assertSame($expected, $emails);
        foreach ($sent as $contact) {
            self::assertArrayNotHasKey('is_unsubscribed', $contact);
        }
        $progress = $import->progress();
        self::assertSame(['id' => $id, 'status' => JobStore::STATUS_DONE, 'total' => count($expected), 'sent' => count($expected), 'failed' => 0, 'skipped' => 0], array_intersect_key($progress, array_flip(['id', 'status', 'total', 'sent', 'failed', 'skipped'])));
    }

    /**
     * @return array<string, array{string, bool, list<string>}>
     */
    public function audiences(): array
    {
        return [
            'subscribers only, with guests' => [ContactSyncMode::SUBSCRIBERS, true, ['subscriber@example.test', 'subscribed-guest@example.test', 'block@example.test']],
            'subscribers only, without guests' => [ContactSyncMode::SUBSCRIBERS, false, ['subscriber@example.test', 'block@example.test']],
            'all customers, with guests' => [ContactSyncMode::ALL_CUSTOMERS, true, ['subscriber@example.test', 'customer@example.test', 'guest-checkout@example.test', 'subscribed-guest@example.test', 'block@example.test']],
            'all customers, without guests' => [ContactSyncMode::ALL_CUSTOMERS, false, ['subscriber@example.test', 'customer@example.test', 'block@example.test']],
            'checkout opt-in only' =>[ContactSyncMode::CHECKOUT_OPTIN, true, []],
            'checkout opt-in only, without guests' => [ContactSyncMode::CHECKOUT_OPTIN, false, []],
        ];
    }

    public function testEachContactGoesThroughTheAccountOfItsShopWithTheContactSyncPayload(): void
    {
        $this->addCustomer($this->shopA, 'shopper1@example.test', true, $this->idLang);
        $this->addCustomer($this->shopB, 'shopper2@example.test', true, $this->idLang);
        $import = $this->import();

        $import->start();
        $this->tickUntilFinished($import);

        self::assertSame(['shopa', 'shopb'], $this->accountsCalled->getArrayCopy());
        $language = strtolower((string) \Language::getIsoById($this->idLang));
        self::assertSame(
            [
                ['email' => 'shopper1@example.test', 'store' => 'shop' . $this->shopA . '.example.invalid', 'language' => $language, 'first_name' => 'Testfirst'],
                ['email' => 'shopper2@example.test', 'store' => 'shop' . $this->shopB . '.example.invalid', 'language' => $language, 'first_name' => 'Testfirst'],
            ],
            $this->sentContacts()
        );
    }

    /**
     * Acceptance 7: a few thousand contacts span several ticks, survive a stop between ticks and
     * a second start, and each reaches Smaily once.
     */
    public function testAnImportOfThousandsSpansTicksSurvivesAStopAndSendsEachContactOnce(): void
    {
        for ($i = 1; $i <= 3000; ++$i) {
            $this->addCustomer($i % 2 === 0 ? $this->shopA : $this->shopB, sprintf('shopper%d@example.test', $i), true, $this->idLang);
        }
        $import = $this->import();

        $id = $import->start();
        $import->run($this->deadlineAfter(2));
        $afterFirstTick = $import->progress();
        self::assertSame(JobStore::STATUS_RUNNING, $afterFirstTick['status']);
        self::assertGreaterThan(0, $afterFirstTick['sent']);
        self::assertLessThan(3000, $afterFirstTick['sent']);

        self::assertTrue($import->stop());
        $import->run($this->deadlineAfter(2));
        self::assertSame(JobStore::STATUS_CANCELLED, $import->progress()['status']);
        self::assertSame($afterFirstTick['sent'], $import->progress()['sent']);

        self::assertSame($id, $import->start());
        $ticks = $this->tickUntilFinished($import);

        self::assertGreaterThan(1, $ticks);
        $emails = array_column($this->sentContacts(), 'email');
        self::assertCount(3000, $emails);
        self::assertCount(3000, array_unique($emails));
        self::assertSame(['status' => JobStore::STATUS_DONE, 'total' => 3000, 'sent' => 3000], array_intersect_key($import->progress(), array_flip(['status', 'total', 'sent'])));
        self::assertNotSame($id, $import->start(), 'A start after a finished import imports again from the first contact.');
    }

    /**
     * A start leaves an import that is queued or running as it is. A read of the newest import
     * that fails (facts file 6.11) is not "none ran": read so, a second import started beside
     * the first.
     */
    public function testAStartThatCouldNotReadTheRunningImportStartsNoSecondOne(): void
    {
        $this->addCustomer($this->shopA, 'shopper1@example.test', true, $this->idLang);
        $import = $this->import();
        $id = $import->start();

        try {
            FailingQueries::during('/SELECT \* FROM `[^`]+' . JobStore::TABLE . '` WHERE `type`/', static function () use ($import): void {
                $import->start();
            });
            self::fail('The start went on without the read.');
        } catch (SqlException $error) {
            self::assertSame('1', (string) self::db()->getValue('SELECT COUNT(*) FROM `' . _DB_PREFIX_ . JobStore::TABLE . '`', false));
        }

        self::assertSame($id, $import->start());
    }

    /**
     * Acceptance 8: no contact of a shop whose contact sync is off goes, and with no shop on the
     * import ends at once with a reason.
     */
    public function testNothingGoesForAShopWhoseContactSyncIsOff(): void
    {
        $this->writeShopValue(ContactsSettings::ENABLED, '0', $this->shopB);
        $this->addCustomer($this->shopA, 'shopper1@example.test', true, $this->idLang);
        $this->addCustomer($this->shopB, 'shopper2@example.test', true, $this->idLang);
        $import = $this->import();

        $import->start();
        $this->tickUntilFinished($import);

        self::assertSame(['shopper1@example.test'], array_column($this->sentContacts(), 'email'));
        self::assertSame(1, $import->progress()['total']);

        \Configuration::updateGlobalValue(ContactsSettings::ENABLED, '0');
        $requests = count($this->mock->requests());
        $import->start();
        self::assertSame(
            ['status' => JobStore::STATUS_DONE, 'total' => 0, 'last_error' => ContactImport::REASON_SYNC_OFF],
            array_intersect_key($import->progress(), array_flip(['status', 'total', 'last_error']))
        );
        $import->run($this->deadlineAfter(100));
        self::assertCount($requests, $this->mock->requests());
    }

    public function testAnImportStopsAtAnOutageAndResumesFromThePageThatFailed(): void
    {
        $this->addCustomer($this->shopA, 'shopper1@example.test', true, $this->idLang);
        $this->addCustomer($this->shopB, 'shopper2@example.test', true, $this->idLang);
        $this->outages['shopb'] = 503;
        $import = $this->import();

        $id = $import->start();
        $import->run($this->deadlineAfter(100));

        self::assertSame(
            ['status' => JobStore::STATUS_FAILED, 'sent' => 0, 'last_error' => 'Smaily API request failed with HTTP 503'],
            array_intersect_key($import->progress(), array_flip(['status', 'sent', 'last_error']))
        );

        unset($this->outages['shopb']);
        self::assertSame($id, $import->start());
        $this->tickUntilFinished($import);
        self::assertSame(['status' => JobStore::STATUS_DONE, 'sent' => 2], array_intersect_key($import->progress(), array_flip(['status', 'sent'])));
    }

    public function testTheDailyRefreshSendsTheAudienceAgainOncePerRun(): void
    {
        $this->addCustomer($this->shopA, 'shopper1@example.test', true, $this->idLang);
        $import = $this->import();

        $id = $import->scheduleRefresh();
        self::assertNotNull($id);
        self::assertNull($import->scheduleRefresh(), 'No second refresh while one is pending.');
        $this->tickUntilFinished($import);

        self::assertSame(['shopper1@example.test'], array_column($this->sentContacts(), 'email'));
        self::assertSame(JobStore::STATUS_DONE, $import->progress(0, ContactImport::TYPE_REFRESH)['status']);

        \Configuration::updateGlobalValue(ContactsSettings::MODE, ContactSyncMode::CHECKOUT_OPTIN);
        self::assertNull($import->scheduleRefresh(), 'No refresh when the audience is empty.');
    }

    /**
     * Acceptance 9: the job row, its error and the shop's log name no contact.
     */
    public function testNoJobStateOrLogLineNamesTheContact(): void
    {
        $this->saveAccount($this->shopB, 'shopb', 'a-wrong-fake-password');
        $this->addCustomer($this->shopA, 'shopper1@example.test', true, $this->idLang);
        $this->addCustomer($this->shopB, 'shopper2@example.test', true, $this->idLang);
        $lastLog = (int) self::db()->getValue('SELECT MAX(`id_log`) FROM `' . _DB_PREFIX_ . 'log`', false);
        $import = $this->import();

        $id = $import->start();
        $this->tickUntilFinished($import);

        $progress = $import->progress();
        self::assertSame(['sent' => 1, 'failed' => 1, 'last_error' => 'permanent_http_401: Smaily API credentials were rejected'], array_intersect_key($progress, array_flip(['sent', 'failed', 'last_error'])));
        $row = self::db()->getRow('SELECT * FROM `' . _DB_PREFIX_ . JobStore::TABLE . '` WHERE `id` = ' . $id, false);
        $logs = array_column(self::db()->executeS('SELECT `message` FROM `' . _DB_PREFIX_ . 'log` WHERE `id_log` > ' . $lastLog, true, false) ?: [], 'message');
        foreach (array_merge(array_map('strval', array_values((array) $row)), $logs, [(string) json_encode($progress)]) as $text) {
            foreach (['shopper', 'example.test', 'Testfirst', 'Testlast'] as $quoted) {
                self::assertStringNotContainsString($quoted, $text);
            }
        }
    }

    private function import(): ContactImport
    {
        $services = Services::fromProviders(Services::PROVIDERS);

        return new ContactImport(
            new JobStore(),
            new ContactSource(),
            $services->get(ScopeResolver::class),
            $services->get(ContactsSettings::class),
            $services->get(SubscriberPayloadBuilder::class),
            $this->transport()
        );
    }

    private function transport(): Transport
    {
        return new class($this->mock->transport(), $this->accountsCalled, $this->outages) implements Transport {
            private Transport $mock;

            /** @var \ArrayObject<int, string> */
            private \ArrayObject $recorded;

            /** @var \ArrayObject<string, int> */
            private \ArrayObject $outages;

            /**
             * @param \ArrayObject<int, string> $recorded
             * @param \ArrayObject<string, int> $outages
             */
            public function __construct(Transport $mock, \ArrayObject $recorded, \ArrayObject $outages)
            {
                $this->mock = $mock;
                $this->recorded = $recorded;
                $this->outages = $outages;
            }

            public function request(string $method, string $url, array $options = []): Response
            {
                $account = explode('.', (string) parse_url($url, PHP_URL_HOST))[0];
                if (isset($this->outages[$account])) {
                    return new Response($this->outages[$account], '');
                }
                $this->recorded[] = $account;

                return $this->mock->request($method, $url, $options);
            }
        };
    }

    /**
     * Runs ticks until no import job is left to run.
     *
     * @return int the ticks it took
     */
    private function tickUntilFinished(ContactImport $import): int
    {
        for ($ticks = 1; $ticks <= 50; ++$ticks) {
            $import->run($this->deadlineAfter(2));
            $pending = (int) self::db()->getValue(
                'SELECT COUNT(*) FROM `' . _DB_PREFIX_ . JobStore::TABLE . '` WHERE `status` IN (\'queued\', \'running\')',
                false
            );
            if ($pending === 0) {
                return $ticks;
            }
        }
        self::fail('The import did not finish in 50 ticks.');
    }

    /**
     * A deadline that passes at its $checks-th check, so a tick takes about that many pages.
     */
    private function deadlineAfter(int $checks): Deadline
    {
        $count = 0;

        return new Deadline((float) $checks, static function () use (&$count): float {
            return (float) ++$count;
        });
    }

    /**
     * @return list<array<string, mixed>> every contact posted to the mock, in order
     */
    private function sentContacts(): array
    {
        $contacts = [];
        foreach ($this->mock->requests() as $request) {
            if ($request['method'] === 'POST' && $request['path'] === '/api/contact.php' && $request['authorized']) {
                $contacts = array_merge($contacts, $request['body']);
            }
        }

        return $contacts;
    }

    private function saveAccount(int $idShop, string $subdomain, string $password): void
    {
        $store = Services::fromProviders(Services::PROVIDERS)->get(AccountStore::class);
        self::assertTrue($store->save(new Account($idShop, '', $subdomain, 'api-user', $password)));
    }

    private function writeShopValue(string $key, string $value, int $idShop): void
    {
        \Configuration::updateValue($key, $value, false, (int) \Shop::getGroupFromShop($idShop), $idShop);
    }

    private function deleteJobs(): void
    {
        self::db()->delete(JobStore::TABLE, '`type` IN (\'' . ContactImport::TYPE_IMPORT . '\', \'' . ContactImport::TYPE_REFRESH . '\')');
    }

    private function deleteKeys(): void
    {
        foreach (self::KEYS as $key) {
            \Configuration::deleteByName($key);
        }
    }
}
