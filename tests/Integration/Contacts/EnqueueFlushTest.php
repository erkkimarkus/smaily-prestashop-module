<?php
declare(strict_types=1);

namespace Tests\Integration\Contacts;

use PrestaShop\Module\SmailyForPrestaShop\Config\ContactsSettings;
use PrestaShop\Module\SmailyForPrestaShop\Contacts\Capture\ContactEvents;
use PrestaShop\Module\SmailyForPrestaShop\Contacts\ContactSyncMode;
use PrestaShop\Module\SmailyForPrestaShop\Hook\Dispatcher;
use PrestaShop\Module\SmailyForPrestaShop\Http\Transport;
use PrestaShop\Module\SmailyForPrestaShop\Queue\EventQueue;
use PrestaShop\Module\SmailyForPrestaShop\Queue\Handler\ContactSyncHandler;
use PrestaShop\Module\SmailyForPrestaShop\Runtime\Provider\QueueProvider;
use PrestaShop\Module\SmailyForPrestaShop\Runtime\Services;
use PrestaShop\Module\SmailyForPrestaShop\Scheduler\Lock;
use PrestaShop\Module\SmailyForPrestaShop\Scope\StoreLayout;
use PrestaShop\Module\SmailyForPrestaShop\Smaily\Account;
use PrestaShop\Module\SmailyForPrestaShop\Smaily\AccountStore;
use Tests\Integration\Fixtures\SmailyMockServer;
use Tests\Integration\IntegrationTestCase;

require_once __DIR__ . '/../Fixtures/SmailyMockServer.php';
require_once __DIR__ . '/ContactRows.php';

/**
 * The enqueue-time flush: a request that queued a contact row writes it at its end, sends its
 * response, and flushes the queue to the Smaily mock under the tick's lock, so the contact reaches
 * Smaily without waiting for the next tick; the contact sync handler then applies the shop's
 * consent mode, the guest gates included.
 */
final class EnqueueFlushTest extends IntegrationTestCase
{
    use ContactRows;

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

    private int $shop;

    private int $idLang;

    private int $finished = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mock = SmailyMockServer::start();
        $this->mock->acceptCredentials('api-user', self::PASSWORD);
        $this->savedAccounts = self::db()->executeS('SELECT * FROM `' . _DB_PREFIX_ . self::ACCOUNT_TABLE . '`', true, false) ?: [];
        self::db()->delete(self::ACCOUNT_TABLE);
        self::db()->delete(EventQueue::TABLE);
        $this->deleteKeys();
        $this->shop = (int) \Configuration::get('PS_SHOP_DEFAULT');
        $this->idLang = (int) \Configuration::get('PS_LANG_DEFAULT');
        \Configuration::updateGlobalValue(StoreLayout::SEPARATE_STORES, '1');
        \Configuration::updateGlobalValue(ContactsSettings::ENABLED, '1');
        \Configuration::updateGlobalValue(ContactsSettings::CONFIRMED, '1');
        \Configuration::updateGlobalValue(ContactsSettings::MODE, ContactSyncMode::SUBSCRIBERS);
        \Configuration::updateGlobalValue(ContactsSettings::GUESTS, '1');
        \Configuration::updateGlobalValue(ContactsSettings::FIELDS, 'store,first_name');
        $store = Services::fromProviders(Services::PROVIDERS)->get(AccountStore::class);
        self::assertTrue($store->save(new Account($this->shop, '', 'store', 'api-user', self::PASSWORD)));
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
        }
        parent::tearDown();
    }

    public function testTheRequestsRowIsSentAfterTheResponse(): void
    {
        $shutdown = [];
        $services = $this->services(true, $shutdown);

        $this->register($services, 'shopper1@example.test', false);
        self::assertCount(1, $shutdown, 'the request registers one shutdown function');
        self::assertSame([], $this->statuses(), 'nothing is written before the request ends');
        ($shutdown[0])();

        self::assertSame(1, $this->finished, 'the response goes out before the flush');
        self::assertSame([EventQueue::STATUS_SENT], $this->statuses());
        $requests = $this->mock->requests();
        self::assertCount(1, $requests);
        self::assertSame([['email' => 'shopper1@example.test', 'is_unsubscribed' => 0, 'store' => (string) \ShopUrl::getMainShopDomain($this->shop), 'language' => strtolower((string) \Language::getIsoById($this->idLang)), 'first_name' => 'Testfirst']], $requests[0]['body']);
    }

    public function testWithoutAFinishFunctionTheRowWaitsForTheTick(): void
    {
        $shutdown = [];
        $services = $this->services(false, $shutdown);

        $this->register($services, 'shopper1@example.test', false);
        ($shutdown[0])();

        self::assertSame([EventQueue::STATUS_PENDING], $this->statuses());
        self::assertSame([], $this->mock->requests());
    }

    public function testWhileATickHoldsTheLockTheRowWaitsForTheTick(): void
    {
        $shutdown = [];
        $services = $this->services(true, $shutdown);
        $this->register($services, 'shopper1@example.test', false);
        $holder = proc_open(
            [PHP_BINARY, __DIR__ . '/../Scheduler/lock-holder.php'],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );
        self::assertIsResource($holder);
        try {
            $line = fgets($pipes[1]);
            self::assertSame("locked\n", $line, $line === false ? (string) stream_get_contents($pipes[2]) : '');

            ($shutdown[0])();
        } finally {
            fclose($pipes[0]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($holder);
        }

        self::assertSame([EventQueue::STATUS_PENDING], $this->statuses());
        self::assertSame([], $this->mock->requests());
    }

    /**
     * @return iterable<string, array{string, bool, string}>
     */
    public function guestCases(): iterable
    {
        yield 'subscribers only, include guests off' => [ContactSyncMode::SUBSCRIBERS, false, EventQueue::STATUS_SKIPPED];
        yield 'all customers, include guests off' => [ContactSyncMode::ALL_CUSTOMERS, false, EventQueue::STATUS_SKIPPED];
        yield 'checkout opt-in only, include guests off' => [ContactSyncMode::CHECKOUT_OPTIN, false, EventQueue::STATUS_SENT];
        yield 'subscribers only, include guests on' => [ContactSyncMode::SUBSCRIBERS, true, EventQueue::STATUS_SENT];
        yield 'all customers, include guests on' => [ContactSyncMode::ALL_CUSTOMERS, true, EventQueue::STATUS_SENT];
    }

    /**
     * A guest at the order step who ticked the box, end to end: the row says `guest`, and the
     * handler applies the guest gates of the shop's mode.
     *
     * @dataProvider guestCases
     */
    public function testAGuestCheckoutIsSentOnlyWhereTheModeTakesGuests(string $mode, bool $includeGuests, string $status): void
    {
        \Configuration::updateGlobalValue(ContactsSettings::MODE, $mode);
        \Configuration::updateGlobalValue(ContactsSettings::GUESTS, $includeGuests ? '1' : '0');
        $controller = (new \ReflectionClass(\OrderController::class))->newInstanceWithoutConstructor();
        $controller->php_self = 'order';
        \Context::getContext()->controller = $controller;
        $shutdown = [];
        $services = $this->services(true, $shutdown);

        try {
            $this->register($services, 'shopper1@example.test', true);
        } finally {
            \Context::getContext()->controller = null;
        }
        ($shutdown[0])();

        self::assertSame([$status], $this->statuses());
        $row = self::db()->getRow('SELECT `payload`, `last_error` FROM `' . _DB_PREFIX_ . EventQueue::TABLE . '`', false);
        self::assertTrue(json_decode((string) $row['payload'], true)['guest']);
        self::assertSame($status === EventQueue::STATUS_SKIPPED ? ContactSyncHandler::SKIPPED_NOT_IN_AUDIENCE : null, $row['last_error']);
        self::assertCount($status === EventQueue::STATUS_SENT ? 1 : 0, $this->mock->requests());
    }

    /**
     * @return iterable<string, array{string, bool, string, int|null}>
     */
    public function blockCases(): iterable
    {
        yield 'subscribers only, include guests off' => [ContactSyncMode::SUBSCRIBERS, false, EventQueue::STATUS_SENT, 0];
        yield 'subscribers only, include guests on' => [ContactSyncMode::SUBSCRIBERS, true, EventQueue::STATUS_SENT, 0];
        yield 'all customers, include guests off' => [ContactSyncMode::ALL_CUSTOMERS, false, EventQueue::STATUS_SENT, null];
        yield 'all customers, include guests on' => [ContactSyncMode::ALL_CUSTOMERS, true, EventQueue::STATUS_SENT, null];
        yield 'checkout opt-in only, include guests off' => [ContactSyncMode::CHECKOUT_OPTIN, false, EventQueue::STATUS_SKIPPED, null];
        yield 'checkout opt-in only, include guests on' => [ContactSyncMode::CHECKOUT_OPTIN, true, EventQueue::STATUS_SKIPPED, null];
    }

    /**
     * A newsletter-block subscribe of an address without an account, end to end: it goes by the
     * shop's mode alone, whatever "include guests" says (owner, 2026-11-21, as Magento syncs a
     * newsletter-form signup whatever its guest setting).
     *
     * @dataProvider blockCases
     */
    public function testANewsletterBlockSubscriberIsSentByTheModeWhateverIncludeGuestsSays(string $mode, bool $includeGuests, string $status, ?int $isUnsubscribed): void
    {
        \Configuration::updateGlobalValue(ContactsSettings::MODE, $mode);
        \Configuration::updateGlobalValue(ContactsSettings::GUESTS, $includeGuests ? '1' : '0');
        $shutdown = [];
        $services = $this->services(true, $shutdown);
        $this->addNewsletterRow($this->shop, 'block1@example.test', true, $this->idLang);

        (new Dispatcher(static fn (): bool => true, static fn (): Services => $services, static function (): void {
        }))->run('actionNewsletterRegistrationAfter', ['email' => 'block1@example.test', 'action' => '0', 'module' => 'ps_emailsubscription']);
        ($shutdown[0])();

        self::assertSame([$status], $this->statuses());
        $row = self::db()->getRow('SELECT `payload`, `last_error` FROM `' . _DB_PREFIX_ . EventQueue::TABLE . '`', false);
        self::assertSame('newsletter_block', json_decode((string) $row['payload'], true)['source']);
        self::assertSame($status === EventQueue::STATUS_SKIPPED ? ContactSyncHandler::SKIPPED_NOT_IN_AUDIENCE : null, $row['last_error']);
        $requests = $this->mock->requests();
        self::assertCount($status === EventQueue::STATUS_SENT ? 1 : 0, $requests);
        if ($status === EventQueue::STATUS_SENT) {
            self::assertSame(['block1@example.test', $isUnsubscribed], [$requests[0]['body'][0]['email'], $requests[0]['body'][0]['is_unsubscribed'] ?? null]);
        }
    }

    /**
     * The runtime's services with the Smaily mock and a capture whose shutdown function and
     * finish function this test holds.
     *
     * @param list<callable> $shutdown receives the shutdown functions the capture registers
     */
    private function services(bool $finishes, array &$shutdown): Services
    {
        $services = Services::fromProviders(Services::PROVIDERS);
        $services->set(Transport::class, fn (): Transport => $this->mock->transport());
        $services->set(ContactEvents::class, function (Services $services) use ($finishes, &$shutdown): ContactEvents {
            return new ContactEvents(
                $services->get(QueueProvider::EVENT_QUEUE),
                $services->get(ContactsSettings::class),
                new Lock(),
                static fn () => $services->get(QueueProvider::FLUSHER),
                $finishes ? function (): void {
                    ++$this->finished;
                } : null,
                static function (callable $function) use (&$shutdown): void {
                    $shutdown[] = $function;
                }
            );
        });

        return $services;
    }

    private function register(Services $services, string $email, bool $guest): void
    {
        $customer = new \Customer($this->addCustomer($this->shop, $email, true, $this->idLang, ['is_guest' => (int) $guest]));
        (new Dispatcher(static fn (): bool => true, static fn (): Services => $services, static function (): void {
        }))->run('actionCustomerAccountAdd', ['newCustomer' => $customer]);
    }

    /**
     * @return list<string>
     */
    private function statuses(): array
    {
        return array_column(self::db()->executeS('SELECT `status` FROM `' . _DB_PREFIX_ . EventQueue::TABLE . '` ORDER BY `id`', true, false) ?: [], 'status');
    }

    private function deleteKeys(): void
    {
        foreach (self::KEYS as $key) {
            \Configuration::deleteByName($key);
        }
    }
}
