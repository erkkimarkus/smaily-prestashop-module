<?php
declare(strict_types=1);

namespace Tests\Integration\Contacts;

use PrestaShop\Module\SmailyForPrestaShop\Config\ContactsSettings;
use PrestaShop\Module\SmailyForPrestaShop\Contacts\ContactSyncMode;
use PrestaShop\Module\SmailyForPrestaShop\Contacts\Reconciler;
use PrestaShop\Module\SmailyForPrestaShop\Http\Response;
use PrestaShop\Module\SmailyForPrestaShop\Http\Transport;
use PrestaShop\Module\SmailyForPrestaShop\Queue\EventQueue;
use PrestaShop\Module\SmailyForPrestaShop\Runtime\Services;
use PrestaShop\Module\SmailyForPrestaShop\Scheduler\Deadline;
use PrestaShop\Module\SmailyForPrestaShop\Scope\ScopeResolver;
use PrestaShop\Module\SmailyForPrestaShop\Scope\StoreLayout;
use PrestaShop\Module\SmailyForPrestaShop\Smaily\Account;
use PrestaShop\Module\SmailyForPrestaShop\Smaily\AccountStore;
use Tests\Integration\Fixtures\SmailyMockServer;
use Tests\Integration\IntegrationTestCase;
use Tests\Integration\MultistoreShop;

require_once __DIR__ . '/../Fixtures/SmailyMockServer.php';
require_once __DIR__ . '/../MultistoreShop.php';
require_once __DIR__ . '/ContactRows.php';

/**
 * The reconcile in the shop, against the Smaily mock's action log: in subscribers-only mode a
 * Smaily unsubscribe switches the store's consent off and a Smaily re-subscribe switches the
 * customer's back on, with no event queued back to Smaily, only in the shops whose contacts go
 * through the account that reported the change.
 *
 * Two added shops, A and B, each with an account of its own. The mock serves the action log of
 * shop A's account; shop B's account answers an empty log.
 */
final class ReconcilerTest extends IntegrationTestCase
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
        StoreLayout::SEPARATE_STORES,
        Reconciler::CURSORS,
    ];

    private SmailyMockServer $mock;

    /** @var list<array<string, mixed>> */
    private array $savedAccounts = [];

    /** @var \ArrayObject<int, string> the first DNS label of the host of each request */
    private \ArrayObject $accountsCalled;

    private int $shopA;

    private int $shopB;

    private int $idLang;

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
        $group = (int) \Shop::getGroupFromShop($this->defaultShopId());
        $this->shopA = $this->addShop($group);
        $this->shopB = $this->addShop($group);
        $this->idLang = (int) \Configuration::get('PS_LANG_DEFAULT');

        \Configuration::updateGlobalValue(StoreLayout::SEPARATE_STORES, '1');
        \Configuration::updateGlobalValue(ContactsSettings::ENABLED, '1');
        \Configuration::updateGlobalValue(ContactsSettings::CONFIRMED, '1');
        \Configuration::updateGlobalValue(ContactsSettings::MODE, ContactSyncMode::SUBSCRIBERS);
        \Configuration::updateGlobalValue(ContactsSettings::GUESTS, '1');
        $this->saveAccount($this->shopA, 'shopa', self::PASSWORD);
        $this->saveAccount($this->shopB, 'shopb', self::PASSWORD);
    }

    protected function tearDown(): void
    {
        if (defined('_PS_ROOT_DIR_')) {
            $this->removeContactRows();
            self::db()->delete(EventQueue::TABLE);
            self::db()->delete(self::ACCOUNT_TABLE);
            foreach ($this->savedAccounts as $row) {
                self::db()->insert(self::ACCOUNT_TABLE, array_map(static fn ($value) => $value === null ? null : pSQL((string) $value), $row), true);
            }
            $this->deleteKeys();
            $this->closeMultistore();
        }
        parent::tearDown();
    }

    /**
     * Acceptance 1.
     */
    public function testAnUnsubscribeInSmailySwitchesTheStoresConsentOffAndQueuesNothing(): void
    {
        $this->storeCursors([$this->cursorKeyA() => 10]);
        $customer = $this->addCustomer($this->shopA, 'shopper1@example.test', true, $this->idLang);
        $guest = $this->addNewsletterRow($this->shopA, 'shopper2@example.test', true, $this->idLang);
        $complainer = $this->addCustomer($this->shopA, 'Shopper3@Example.test', true, $this->idLang);
        $this->mock->setHistory([
            ['seq_id' => 11, 'email' => 'shopper1@example.test', 'action' => 'optout'],
            ['seq_id' => 12, 'email' => 'shopper2@example.test', 'action' => 'delete'],
            ['seq_id' => 13, 'email' => 'shopper3@example.test', 'action' => 'complaint'],
        ]);

        self::assertSame(3, $this->reconciler()->run($this->deadline()));

        self::assertSame([0, 0, 0], [$this->newsletter($customer), $this->active($guest), $this->newsletter($complainer)]);
        self::assertSame(0, $this->queuedRows());
        $history = $this->historyRequests();
        self::assertCount(1, $history);
        self::assertSame(['since_seq_id' => '10', 'limit' => '10000', 'actions' => 'optin,optout,delete,complaint'], $history[0]['query']);
    }

    /**
     * The owner's decision of 2026-11-20: the store mirrors Smaily both ways.
     */
    public function testAReSubscribeInSmailySwitchesTheCustomersConsentBackOnAndQueuesNothing(): void
    {
        $this->storeCursors([$this->cursorKeyA() => 4]);
        $customer = $this->addCustomer($this->shopA, 'shopper1@example.test', false, $this->idLang);
        $guest = $this->addNewsletterRow($this->shopA, 'shopper2@example.test', false, $this->idLang);
        $this->mock->setHistory([
            ['seq_id' => 5, 'email' => 'shopper1@example.test', 'action' => 'optout'],
            ['seq_id' => 6, 'email' => 'shopper1@example.test', 'action' => 'optin'],
            ['seq_id' => 7, 'email' => 'shopper2@example.test', 'action' => 'optin'],
        ]);

        self::assertSame(1, $this->reconciler()->run($this->deadline()));

        self::assertSame(1, $this->newsletter($customer));
        self::assertSame(0, $this->active($guest), 'A re-subscribe changes no newsletter-block row.');
        self::assertSame(0, $this->queuedRows());
    }

    /**
     * The first run of an account (owner, 2026-11-20): 2.x never sent a newsletter-block
     * unsubscribe to Smaily, so the action log's opt-ins may belong to shoppers who have
     * unsubscribed in the store since. The first run takes its unsubscribes from Smaily's current
     * unsubscriber list instead, as 2.x did, and so also catches an unsubscribe older than the log.
     */
    public function testTheFirstRunTakesItsUnsubscribesFromTheUnsubscriberList(): void
    {
        $listed = $this->addCustomer($this->shopA, 'shopper1@example.test', true, $this->idLang);
        $listedGuest = $this->addNewsletterRow($this->shopA, 'shopper2@example.test', true, $this->idLang);
        $beforeTheLog = $this->addCustomer($this->shopA, 'Shopper3@Example.test', true, $this->idLang);
        $this->mock->setUnsubscribers(['shopper1@example.test', 'shopper2@example.test', 'shopper3@example.test']);
        $this->mock->setHistory([
            ['seq_id' => 7, 'email' => 'shopper1@example.test', 'action' => 'optout'],
            ['seq_id' => 8, 'email' => 'shopper2@example.test', 'action' => 'optout'],
        ]);

        self::assertSame(3, $this->reconciler()->run($this->deadline()));

        self::assertSame([0, 0, 0], [$this->newsletter($listed), $this->active($listedGuest), $this->newsletter($beforeTheLog)]);
        self::assertSame(0, $this->queuedRows());
        self::assertSame(8, $this->storedCursors()[$this->cursorKeyA()]);
    }

    /**
     * The first run applies nothing from the log: an optout followed by an opt-in leaves an address
     * that is not on the list as the store has it, and an opt-in marks nobody subscribed.
     */
    public function testTheFirstRunAppliesNothingFromTheActionLog(): void
    {
        $resubscribed = $this->addCustomer($this->shopA, 'shopper1@example.test', true, $this->idLang);
        $optedIn = $this->addCustomer($this->shopA, 'shopper2@example.test', false, $this->idLang);
        $this->mock->setHistory([
            ['seq_id' => 1, 'email' => 'shopper1@example.test', 'action' => 'optout'],
            ['seq_id' => 2, 'email' => 'shopper1@example.test', 'action' => 'optin'],
            ['seq_id' => 3, 'email' => 'shopper2@example.test', 'action' => 'optin'],
        ]);

        self::assertSame(0, $this->reconciler()->run($this->deadline()));

        self::assertSame([1, 0], [$this->newsletter($resubscribed), $this->newsletter($optedIn)]);
        self::assertSame(3, $this->storedCursors()[$this->cursorKeyA()]);
    }

    public function testAnOptinAfterTheFirstRunIsApplied(): void
    {
        $customer = $this->addCustomer($this->shopA, 'shopper1@example.test', false, $this->idLang);
        $backlog = [['seq_id' => 1, 'email' => 'shopper1@example.test', 'action' => 'optin']];
        $this->mock->setHistory($backlog);
        $this->reconciler()->run($this->deadline());
        self::assertSame(0, $this->newsletter($customer));

        $this->mock->setHistory(array_merge($backlog, [['seq_id' => 2, 'email' => 'shopper1@example.test', 'action' => 'optin']]));

        self::assertSame(1, $this->reconciler()->run($this->deadline()));
        self::assertSame(1, $this->newsletter($customer));
        self::assertSame(0, $this->queuedRows());
    }

    /**
     * A first run that the deadline stops goes on where it stopped: it reads the log to its end,
     * then pages through the unsubscriber list, and ends with the cursor at the log's end. A change
     * logged while it was in progress is applied by the next run.
     */
    public function testAFirstRunOverSeveralTicksResumesTheListAndEndsAtTheLogsEnd(): void
    {
        $first = $this->addCustomer($this->shopA, 'shopper1@example.test', true, $this->idLang);
        $second = $this->addCustomer($this->shopA, 'shopper2@example.test', true, $this->idLang);
        $later = $this->addCustomer($this->shopA, 'shopper3@example.test', false, $this->idLang);
        $this->mock->setUnsubscribers(['shopper1@example.test', 'shopper2@example.test']);
        $backlog = [
            ['seq_id' => 1, 'email' => 'shopper1@example.test', 'action' => 'optout'],
            ['seq_id' => 2, 'email' => 'shopper2@example.test', 'action' => 'optout'],
        ];
        $this->mock->setHistory($backlog);
        $calls = 0;
        // Passes at the fifth check: one before the account, three log pages, one list page.
        $afterOneListPage = new Deadline(5.0, static function () use (&$calls): float {
            return (float) ++$calls;
        });

        self::assertSame(1, $this->reconciler(1)->run($afterOneListPage));
        self::assertSame([0, 1], [$this->newsletter($first), $this->newsletter($second)]);
        self::assertSame(['seq_id' => 2, 'first_run' => true, 'list_page' => 1], $this->storedCursors()[$this->cursorKeyA()]);

        $this->mock->setHistory(array_merge($backlog, [['seq_id' => 3, 'email' => 'shopper3@example.test', 'action' => 'optin']]));
        self::assertSame(1, $this->reconciler(1)->run($this->deadline()));
        self::assertSame([0, 0, 0], [$this->newsletter($first), $this->newsletter($second), $this->newsletter($later)]);
        self::assertSame(2, $this->storedCursors()[$this->cursorKeyA()]);
        self::assertSame(['0', '1', '2'], array_map(static fn (array $request): string => $request['query']['offset'], $this->listRequests()));

        self::assertSame(1, $this->reconciler(1)->run($this->deadline()));
        self::assertSame(1, $this->newsletter($later));
    }

    public function testAnAccountAddedLaterStartsWithItsOwnFirstRun(): void
    {
        self::db()->delete(self::ACCOUNT_TABLE, '`id_shop` = ' . $this->shopB);
        $inA = $this->addCustomer($this->shopA, 'shopper1@example.test', false, $this->idLang);
        $inB = $this->addCustomer($this->shopB, 'shopper2@example.test', false, $this->idLang);
        $this->mock->setHistory([['seq_id' => 1, 'email' => 'shopper3@example.test', 'action' => 'optout']]);
        $this->reconciler(10000, false)->run($this->deadline());

        $this->saveAccount($this->shopB, 'shopb', self::PASSWORD);
        $this->mock->setHistory([
            ['seq_id' => 1, 'email' => 'shopper3@example.test', 'action' => 'optout'],
            ['seq_id' => 2, 'email' => 'shopper1@example.test', 'action' => 'optin'],
            ['seq_id' => 3, 'email' => 'shopper2@example.test', 'action' => 'optin'],
        ]);

        self::assertSame(1, $this->reconciler(10000, false)->run($this->deadline()));
        self::assertSame([1, 0], [$this->newsletter($inA), $this->newsletter($inB)]);
    }

    /**
     * A cursor stored before the first-run mark existed (a number) is a run that has read the log.
     */
    public function testACursorInTheOldShapeIsNotAFirstRun(): void
    {
        $this->storeCursors([$this->cursorKeyA() => 5]);
        $customer = $this->addCustomer($this->shopA, 'shopper1@example.test', false, $this->idLang);
        $this->mock->setHistory([['seq_id' => 6, 'email' => 'shopper1@example.test', 'action' => 'optin']]);

        self::assertSame(1, $this->reconciler()->run($this->deadline()));

        self::assertSame(1, $this->newsletter($customer));
        self::assertSame('5', $this->historyRequests()[0]['query']['since_seq_id']);
        self::assertSame(6, $this->storedCursors()[$this->cursorKeyA()]);
    }

    /**
     * A first run stored as {"seq_id": n, "first_run": true}, the shape before the unsubscriber
     * list was its source, goes on reading the log from n, then takes the list.
     */
    public function testAFirstRunStoredWithoutAListPageGoesOnFromItsSeqId(): void
    {
        $this->storeCursors([$this->cursorKeyA() => ['seq_id' => 1, 'first_run' => true]]);
        $logged = $this->addCustomer($this->shopA, 'shopper1@example.test', true, $this->idLang);
        $listed = $this->addCustomer($this->shopA, 'shopper2@example.test', true, $this->idLang);
        $this->mock->setUnsubscribers(['shopper2@example.test']);
        $this->mock->setHistory([
            ['seq_id' => 1, 'email' => 'shopper3@example.test', 'action' => 'optout'],
            ['seq_id' => 2, 'email' => 'shopper1@example.test', 'action' => 'optout'],
        ]);

        self::assertSame(1, $this->reconciler()->run($this->deadline()));

        self::assertSame([1, 0], [$this->newsletter($logged), $this->newsletter($listed)]);
        self::assertSame('1', $this->historyRequests()[0]['query']['since_seq_id']);
        self::assertSame(2, $this->storedCursors()[$this->cursorKeyA()]);
    }

    /**
     * Acceptance 2.
     *
     * @dataProvider modesThatDoNotReconcile
     */
    public function testAllCustomersAndCheckoutOptinReconcileNothing(string $mode): void
    {
        \Configuration::updateGlobalValue(ContactsSettings::MODE, $mode);
        $customer = $this->addCustomer($this->shopA, 'shopper1@example.test', true, $this->idLang);
        $guest = $this->addNewsletterRow($this->shopA, 'shopper2@example.test', true, $this->idLang);
        $this->mock->setHistory([
            ['seq_id' => 1, 'email' => 'shopper1@example.test', 'action' => 'optout'],
            ['seq_id' => 2, 'email' => 'shopper2@example.test', 'action' => 'optout'],
        ]);

        self::assertSame(0, $this->reconciler()->run($this->deadline()));

        self::assertSame([1, 1], [$this->newsletter($customer), $this->active($guest)]);
        self::assertSame([], $this->mock->requests());
    }

    /**
     * @return array<string, array{string}>
     */
    public function modesThatDoNotReconcile(): array
    {
        return ['all customers' => [ContactSyncMode::ALL_CUSTOMERS], 'checkout opt-in only' => [ContactSyncMode::CHECKOUT_OPTIN]];
    }

    /**
     * Acceptance 6: separate stores, one address in both shops.
     */
    public function testAChangeThatShopAsAccountReportsChangesNobodyInShopB(): void
    {
        $inA = $this->addCustomer($this->shopA, 'shopper1@example.test', true, $this->idLang);
        $inB = $this->addCustomer($this->shopB, 'shopper1@example.test', true, $this->idLang);
        $guestInA = $this->addNewsletterRow($this->shopA, 'shopper2@example.test', true, $this->idLang);
        $guestInB = $this->addNewsletterRow($this->shopB, 'shopper2@example.test', true, $this->idLang);
        $this->storeCursors([$this->cursorKeyA() => 0, $this->shopB . '::shopb' => 0]);
        $this->mock->setHistory([
            ['seq_id' => 1, 'email' => 'shopper1@example.test', 'action' => 'optout'],
            ['seq_id' => 2, 'email' => 'shopper2@example.test', 'action' => 'optout'],
        ]);

        self::assertSame(2, $this->reconciler()->run($this->deadline()));

        self::assertSame([0, 0], [$this->newsletter($inA), $this->active($guestInA)]);
        self::assertSame([1, 1], [$this->newsletter($inB), $this->active($guestInB)]);
        self::assertSame(['shopa', 'shopb'], $this->accountsCalled->getArrayCopy());
    }

    /**
     * The first run in separate stores: shop A's unsubscriber list changes nobody in shop B.
     */
    public function testShopAsUnsubscriberListChangesNobodyInShopB(): void
    {
        $inA = $this->addCustomer($this->shopA, 'shopper1@example.test', true, $this->idLang);
        $inB = $this->addCustomer($this->shopB, 'shopper1@example.test', true, $this->idLang);
        $guestInA = $this->addNewsletterRow($this->shopA, 'shopper2@example.test', true, $this->idLang);
        $guestInB = $this->addNewsletterRow($this->shopB, 'shopper2@example.test', true, $this->idLang);
        $this->mock->setUnsubscribers(['shopper1@example.test', 'shopper2@example.test']);

        self::assertSame(2, $this->reconciler()->run($this->deadline()));

        self::assertSame([0, 0], [$this->newsletter($inA), $this->active($guestInA)]);
        self::assertSame([1, 1], [$this->newsletter($inB), $this->active($guestInB)]);
    }

    /**
     * Acceptance 8: one store with one account; shop B's contact sync is off, so its contacts stay
     * as they are, and with every shop off the reconcile asks Smaily nothing.
     */
    public function testAShopWhoseContactSyncIsOffIsNotTouched(): void
    {
        \Configuration::updateGlobalValue(StoreLayout::SEPARATE_STORES, '0');
        self::db()->delete(self::ACCOUNT_TABLE);
        $this->saveAccount(0, 'shopa', self::PASSWORD);
        $this->writeShopValue(ContactsSettings::ENABLED, '0', $this->shopB);
        $inA = $this->addCustomer($this->shopA, 'shopper1@example.test', true, $this->idLang);
        $inB = $this->addCustomer($this->shopB, 'shopper1@example.test', true, $this->idLang);
        $this->mock->setUnsubscribers(['shopper1@example.test']);

        self::assertSame(1, $this->reconciler()->run($this->deadline()));
        self::assertSame([0, 1], [$this->newsletter($inA), $this->newsletter($inB)]);

        \Configuration::updateGlobalValue(ContactsSettings::ENABLED, '0');
        $requests = count($this->mock->requests());
        self::assertSame(0, $this->reconciler()->run($this->deadline()));
        self::assertCount($requests, $this->mock->requests());
    }

    public function testTheNextRunReadsOnlyTheChangesAfterTheLastOneApplied(): void
    {
        $customer = $this->addCustomer($this->shopA, 'shopper1@example.test', true, $this->idLang);
        $this->mock->setHistory([['seq_id' => 41, 'email' => 'shopper1@example.test', 'action' => 'optout']]);
        $this->reconciler()->run($this->deadline());
        self::db()->update('customer', ['newsletter' => 1], 'id_customer = ' . $customer);

        self::assertSame(0, $this->reconciler()->run($this->deadline()));

        self::assertSame(1, $this->newsletter($customer));
        $history = $this->historyRequests();
        self::assertSame('41', end($history)['query']['since_seq_id']);
    }

    public function testAnAccountWhoseLogFillsAPageIsReadOnTheNextPage(): void
    {
        $first = $this->addCustomer($this->shopA, 'shopper1@example.test', true, $this->idLang);
        $second = $this->addCustomer($this->shopA, 'shopper2@example.test', true, $this->idLang);
        $this->storeCursors([$this->cursorKeyA() => 0]);
        $this->mock->setHistory([
            ['seq_id' => 1, 'email' => 'shopper1@example.test', 'action' => 'optout'],
            ['seq_id' => 2, 'email' => 'shopper2@example.test', 'action' => 'optout'],
        ]);

        self::assertSame(2, $this->reconciler(1)->run($this->deadline()));

        self::assertSame([0, 0], [$this->newsletter($first), $this->newsletter($second)]);
        self::assertSame(['0', '1', '2'], array_map(static fn (array $request): string => $request['query']['since_seq_id'], $this->historyRequests()));
    }

    /**
     * Acceptance 9: a failing account leaves a log line and a stored cursor that name no contact.
     */
    public function testNoLogLineOrStoredValueNamesTheContact(): void
    {
        $this->saveAccount($this->shopB, 'shopb', 'a-wrong-fake-password');
        $this->addCustomer($this->shopA, 'shopper1@example.test', true, $this->idLang);
        $this->mock->setHistory([['seq_id' => 3, 'email' => 'shopper1@example.test', 'action' => 'optout']]);
        $this->mock->setUnsubscribers(['shopper1@example.test']);
        $lastLog = (int) self::db()->getValue('SELECT MAX(`id_log`) FROM `' . _DB_PREFIX_ . 'log`', false);

        $this->reconciler(10000, false)->run($this->deadline());

        $logs = array_column(self::db()->executeS('SELECT `message` FROM `' . _DB_PREFIX_ . 'log` WHERE `id_log` > ' . $lastLog, true, false) ?: [], 'message');
        self::assertNotEmpty($logs);
        self::assertStringContainsString('permanent_http_401', implode("\n", $logs));
        $stored = (string) self::db()->getValue('SELECT `value` FROM `' . _DB_PREFIX_ . 'configuration` WHERE `name` = \'' . Reconciler::CURSORS . '\'', false);
        self::assertNotSame('', $stored);
        foreach (array_merge($logs, [$stored]) as $text) {
            foreach (['shopper', 'example.test', 'Testfirst', 'Testlast'] as $quoted) {
                self::assertStringNotContainsString($quoted, $text);
            }
        }
    }

    /**
     * @param bool $onlyShopA whether shop B's account answers an empty log instead of the mock's
     */
    private function reconciler(int $pageSize = 10000, bool $onlyShopA = true): Reconciler
    {
        $services = Services::fromProviders(Services::PROVIDERS);

        return new Reconciler(
            $services->get(ScopeResolver::class),
            $services->get(ContactsSettings::class),
            $this->transport($onlyShopA),
            $pageSize
        );
    }

    private function transport(bool $onlyShopA): Transport
    {
        return new class($this->mock->transport(), $this->accountsCalled, $onlyShopA) implements Transport {
            private Transport $mock;

            /** @var \ArrayObject<int, string> */
            private \ArrayObject $recorded;

            private bool $onlyShopA;

            /**
             * @param \ArrayObject<int, string> $recorded
             */
            public function __construct(Transport $mock, \ArrayObject $recorded, bool $onlyShopA)
            {
                $this->mock = $mock;
                $this->recorded = $recorded;
                $this->onlyShopA = $onlyShopA;
            }

            public function request(string $method, string $url, array $options = []): Response
            {
                $account = explode('.', (string) parse_url($url, PHP_URL_HOST))[0];
                $this->recorded[] = $account;
                if ($this->onlyShopA && $account !== 'shopa') {
                    return new Response(200, '[]');
                }

                return $this->mock->request($method, $url, $options);
            }
        };
    }

    private function deadline(): Deadline
    {
        return new Deadline(microtime(true) + 15, static fn (): float => microtime(true));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function historyRequests(): array
    {
        return array_values(array_filter($this->mock->requests(), static fn (array $request): bool => $request['path'] === '/api/history.php'));
    }

    /**
     * The key of shop A's account in the stored cursors.
     */
    private function cursorKeyA(): string
    {
        return $this->shopA . '::shopa';
    }

    /**
     * @param array<string, mixed> $cursors
     */
    private function storeCursors(array $cursors): void
    {
        \Configuration::updateGlobalValue(Reconciler::CURSORS, (string) json_encode($cursors));
    }

    /**
     * @return array<string, mixed>
     */
    private function storedCursors(): array
    {
        $json = self::db()->getValue('SELECT `value` FROM `' . _DB_PREFIX_ . 'configuration` WHERE `name` = \'' . Reconciler::CURSORS . '\'', false);

        return (array) json_decode((string) $json, true);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function listRequests(): array
    {
        return array_values(array_filter($this->mock->requests(), static fn (array $request): bool => $request['path'] === '/api/contact.php' && $request['method'] === 'GET'));
    }

    private function queuedRows(): int
    {
        return (int) self::db()->getValue('SELECT COUNT(*) FROM `' . _DB_PREFIX_ . EventQueue::TABLE . '`', false);
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

    private function deleteKeys(): void
    {
        foreach (self::KEYS as $key) {
            \Configuration::deleteByName($key);
        }
    }
}
