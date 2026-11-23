<?php
declare(strict_types=1);

namespace Tests\Integration\Engine;

use PrestaShop\Module\SmailyForPrestaShop\Config\ContactsSettings;
use PrestaShop\Module\SmailyForPrestaShop\Config\EngineSettings;
use PrestaShop\Module\SmailyForPrestaShop\Contacts\ContactSyncMode;
use PrestaShop\Module\SmailyForPrestaShop\Engine\Capture\IdentityCapture;
use PrestaShop\Module\SmailyForPrestaShop\Engine\Capture\VisitorToken;
use PrestaShop\Module\SmailyForPrestaShop\Engine\Ingest\IdentityMergeHandler;
use PrestaShop\Module\SmailyForPrestaShop\Engine\Ingest\IngestFlusher;
use PrestaShop\Module\SmailyForPrestaShop\Engine\Ingest\IngestQueue;
use PrestaShop\Module\SmailyForPrestaShop\Log\ResendGuard;
use PrestaShop\Module\SmailyForPrestaShop\Privacy\LocalData;
use PrestaShop\Module\SmailyForPrestaShop\Privacy\ProfilingConsent;
use PrestaShop\Module\SmailyForPrestaShop\Privacy\ProfilingRecord;
use PrestaShop\Module\SmailyForPrestaShop\Queue\ContactEntity;
use PrestaShop\Module\SmailyForPrestaShop\Queue\EventQueue;
use PrestaShop\Module\SmailyForPrestaShop\Runtime\Provider\QueueProvider;
use PrestaShop\Module\SmailyForPrestaShop\Runtime\Services;
use PrestaShop\Module\SmailyForPrestaShop\Storefront\ActiveConnection;
use PrestaShop\Module\SmailyForPrestaShop\Storefront\Attribution;
use PrestaShop\Module\SmailyForPrestaShop\Storefront\Cookies;
use PrestaShop\Module\SmailyForPrestaShop\Storefront\VisitorToken as StorefrontVisitorToken;
use Tests\Integration\Contacts\ContactRows;
use Tests\Integration\Migration\LegacyStore;

require_once __DIR__ . '/IngestTestCase.php';
require_once __DIR__ . '/../Contacts/ContactRows.php';
require_once __DIR__ . '/../Migration/LegacyStore.php';

/**
 * The customers that reach the engine are the contract's three groups (§4) and no other: (a) the
 * customers of the shop's own contact sync, (b) the buyers inside the history horizon, (c) a
 * customer who signs in or registers with marketing consent, sent before the identity merge (§7).
 * The marketing consent of group (c) is the merchant's confirmation of the cookie banner alone;
 * the customer's `newsletter` is not asked.
 */
final class CustomerIngestTest extends IngestTestCase
{
    use ContactRows;

    private const KEYS = [ContactsSettings::ENABLED, ContactsSettings::CONFIRMED, ContactsSettings::MODE, ContactsSettings::GUESTS, EngineSettings::CONSENT_CONFIRMED];

    private const STORE_TOKEN = 'vs_AbCdEfGhIjKlMnOpQrStUv';

    private const ENGINE_TOKEN = 'vt_8f3k2a9xq4';

    private int $shop;

    private int $idLang;

    /** @var mixed the controller of the context before the test */
    private $savedController;

    /** @var list<int> */
    private array $orders = [];

    /** @var list<int> */
    private array $addresses = [];

    private ?string $token = null;

    /** @var list<array{0: int, 1: bool}> what the visitor-token seam was asked */
    private array $asked = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->shop = (int) \Configuration::get('PS_SHOP_DEFAULT');
        $this->idLang = (int) \Configuration::get('PS_LANG_DEFAULT');
        $this->savedController = \Context::getContext()->controller;
        // The storefront's side of the seam: the token of this "request", and what it was asked.
        $this->services->set(VisitorToken::class, fn (): VisitorToken => new class($this->token, $this->asked) implements VisitorToken {
            private ?string $token;

            /** @var list<array{0: int, 1: bool}> */
            private array $asked;

            /**
             * @param list<array{0: int, 1: bool}> $asked
             */
            public function __construct(?string &$token, array &$asked)
            {
                $this->token = &$token;
                $this->asked = &$asked;
            }

            public function token(int $idShop, bool $marketingConsent): ?string
            {
                $this->asked[] = [$idShop, $marketingConsent];

                return $this->token;
            }
        });
        // The storefront's own listener of a sign-in sets a cookie where the merchant confirmed
        // the banner; PHPUnit's process has no browser to set one in.
        $this->services->set(StorefrontVisitorToken::class, fn (Services $services): StorefrontVisitorToken => new StorefrontVisitorToken(
            $services->get(ActiveConnection::class),
            $services->get(Attribution::class),
            static fn (): ?Cookies => null
        ));
    }

    protected function tearDown(): void
    {
        if (defined('_PS_ROOT_DIR_')) {
            \Context::getContext()->controller = $this->savedController;
            if ($this->orders !== []) {
                self::db()->delete('orders', '`id_order` IN (' . implode(',', $this->orders) . ')');
            }
            if ($this->addresses !== []) {
                self::db()->delete('address', '`id_address` IN (' . implode(',', $this->addresses) . ')');
            }
            $this->removeContactRows();
            // The personalization choices of the tests' shoppers, and their rows for the engine.
            self::db()->delete(ProfilingRecord::TABLE);
            self::db()->delete(EventQueue::TABLE, '`event_type` = \'' . ProfilingConsent::EVENT_TYPE . '\'');
            foreach (self::KEYS as $key) {
                \Configuration::deleteByName($key);
            }
            LegacyStore::reloadConfiguration();
        }
        parent::tearDown();
    }

    public function testACustomerOfTheContactSyncIsSentWithTheContractsFields(): void
    {
        $this->connect();
        $this->contactSync(ContactSyncMode::SUBSCRIBERS);
        $customer = $this->customer('Shopper1@Example.test', true, ['date_add' => '2026-01-15 12:30:00']);
        $this->address((int) $customer->id, '+372 5550 0101');

        $this->fire('actionCustomerAccountAdd', ['newCustomer' => $customer]);
        $this->fire('actionObjectCustomerUpdateAfter', ['object' => $customer]);

        self::assertSame(1, $this->commit(), 'one row, however many hooks named the customer');
        $row = self::rows()[0];
        self::assertSame([IngestQueue::DOMAIN_CUSTOMERS, (string) $customer->id, null], [$row['domain'], $row['entity_key'], $row['payload']], 'the row holds the customer\'s id and nothing of the customer');
        $this->flush();

        $requests = $this->engine->requestsTo(self::CUSTOMERS);
        self::assertCount(1, $requests);
        self::assertSame(['customers'], array_keys($requests[0]['body']));
        $sent = $requests[0]['body']['customers'][0];
        self::assertSame(
            ['event_id', 'email', 'first_name', 'last_name', 'country', 'language', 'phone', 'first_seen_at', 'external_id'],
            array_keys($sent),
            'the fields of contract §4 that the module sends: no consent field, no price group'
        );
        unset($sent['event_id']);
        self::assertSame([
            'email' => 'shopper1@example.test',
            'first_name' => 'Testfirst',
            'last_name' => 'Testlast',
            'country' => (string) \Country::getIsoById((int) \Configuration::get('PS_COUNTRY_DEFAULT')),
            'language' => (string) \Language::getIsoById($this->idLang),
            'phone' => '+372 5550 0101',
            'first_seen_at' => gmdate('Y-m-d\TH:i:s\Z', (int) strtotime('2026-01-15 12:30:00')),
            'external_id' => (string) $customer->id,
        ], $sent);
        self::assertSame(EventQueue::STATUS_SENT, self::rows()[0]['status']);
    }

    public function testACustomerWithoutAnAddressGoesWithoutCountryAndPhone(): void
    {
        $this->connect();
        $this->contactSync(ContactSyncMode::ALL_CUSTOMERS);
        $customer = $this->customer('shopper2@example.test', false);

        $this->fire('actionObjectCustomerUpdateAfter', ['object' => $customer]);
        $this->commit();
        $this->flush();

        self::assertSame(
            ['event_id', 'email', 'first_name', 'last_name', 'language', 'first_seen_at', 'external_id'],
            array_keys($this->sent(self::CUSTOMERS, 'customers')[0])
        );
    }

    /**
     * @dataProvider outsideTheScope
     */
    public function testACustomerOutsideTheScopeIsNotQueued(?string $mode, bool $newsletter, bool $guest): void
    {
        $this->connect();
        if ($mode !== null) {
            $this->contactSync($mode);
        }
        $customer = $this->customer('shopper3@example.test', $newsletter, ['is_guest' => (int) $guest]);

        $this->fire('actionCustomerAccountAdd', ['newCustomer' => $customer]);
        $this->fire('actionObjectCustomerUpdateAfter', ['object' => $customer]);

        self::assertSame(0, $this->commit());
        self::assertSame([], self::rows());
    }

    /**
     * @return iterable<string, array{string|null, bool, bool}>
     */
    public static function outsideTheScope(): iterable
    {
        yield 'no contact sync, a subscriber' => [null, true, false];
        yield 'subscribers only, not subscribed' => [ContactSyncMode::SUBSCRIBERS, false, false];
        yield 'subscribers only, a subscribed guest without "include guests"' => [ContactSyncMode::SUBSCRIBERS, true, true];
        yield 'checkout opt-in only, a subscriber' => [ContactSyncMode::CHECKOUT_OPTIN, true, false];
    }

    public function testABuyerIsSentWhateverTheConsent(): void
    {
        $this->connect();
        $customer = $this->customer('shopper4@example.test', false);
        $order = $this->order((int) $customer->id, date('Y-m-d H:i:s'));

        $this->fire('actionValidateOrder', ['order' => $order, 'customer' => $customer]);

        self::assertSame(2, $this->commit());
        self::assertSame(
            [[IngestQueue::DOMAIN_CUSTOMERS, (string) $customer->id, EventQueue::STATUS_PENDING], [IngestQueue::DOMAIN_ORDERS, (string) $order->id, EventQueue::STATUS_PENDING]],
            self::summary()
        );
        $this->flush();
        self::assertSame('shopper4@example.test', $this->sent(self::CUSTOMERS, 'customers')[0]['email']);
    }

    public function testAnOrderOlderThanTheHorizonAndAGuestBuyerAreNotCustomersOfTheEngine(): void
    {
        $this->connect();
        $old = $this->customer('shopper5@example.test', false);
        $this->order((int) $old->id, date('Y-m-d H:i:s', (int) strtotime('-61 months')));
        $guest = $this->customer('shopper6@example.test', false, ['is_guest' => 1]);
        $guestOrder = $this->order((int) $guest->id, date('Y-m-d H:i:s'));

        $this->fire('actionObjectCustomerUpdateAfter', ['object' => $old]);
        $this->fire('actionValidateOrder', ['order' => $guestOrder, 'customer' => $guest]);

        self::assertSame(1, $this->commit());
        self::assertSame([[IngestQueue::DOMAIN_ORDERS, (string) $guestOrder->id, EventQueue::STATUS_PENDING]], self::summary(), 'a guest buyer reaches the engine with the order');
    }

    public function testACustomerWhoSubscribesThroughTheNewsletterBlockIsQueued(): void
    {
        $this->connect();
        $this->contactSync(ContactSyncMode::SUBSCRIBERS);
        // The block writes `customer.newsletter` with plain SQL: no customer hook fires.
        $customer = $this->customer('shopper7@example.test', true);

        $this->fire('actionNewsletterRegistrationAfter', ['email' => 'shopper7@example.test', 'action' => '0', 'error' => null]);

        self::assertSame(1, $this->commit());
        self::assertSame([[IngestQueue::DOMAIN_CUSTOMERS, (string) $customer->id, EventQueue::STATUS_PENDING]], self::summary());
    }

    public function testTheFlushAsksTheScopeAgainAndReadsTheCustomerThen(): void
    {
        $this->connect();
        $this->contactSync(ContactSyncMode::SUBSCRIBERS);
        $left = $this->customer('shopper8@example.test', true);
        $gone = $this->customer('shopper9@example.test', true);
        $renamed = $this->customer('shopper10@example.test', true);
        foreach ([$left, $gone, $renamed] as $customer) {
            $this->fire('actionObjectCustomerUpdateAfter', ['object' => $customer]);
        }
        self::assertSame(3, $this->commit());
        self::db()->update('customer', ['newsletter' => 0], '`id_customer` = ' . (int) $left->id);
        self::db()->update('customer', ['deleted' => 1], '`id_customer` = ' . (int) $gone->id);
        self::db()->update('customer', ['firstname' => 'Later'], '`id_customer` = ' . (int) $renamed->id);

        $this->flush();

        self::assertSame(
            [[EventQueue::STATUS_SKIPPED, IngestFlusher::SKIPPED_NOT_IN_SCOPE], [EventQueue::STATUS_SKIPPED, IngestFlusher::SKIPPED_CUSTOMER_GONE], [EventQueue::STATUS_SENT, null]],
            array_map(static fn (array $row): array => [$row['status'], $row['last_error']], self::rows())
        );
        $sent = $this->sent(self::CUSTOMERS, 'customers');
        self::assertSame([['shopper10@example.test', 'Later']], array_map(static fn (array $item): array => [$item['email'], $item['first_name']], $sent), 'the customer as the store holds it at the send');
    }

    public function testASignInWhereTheMerchantConfirmedTheBannerSendsTheCustomerFirstAndThenTheMerge(): void
    {
        $this->connect();
        $this->confirmTracking();
        $this->storefront();
        $this->token = self::STORE_TOKEN;
        // Not in the contact sync, never bought and not subscribed: group (c) alone.
        $customer = $this->customer('shopper11@example.test', false);

        $this->fire('actionAuthentication', ['customer' => $customer]);

        self::assertSame([[$this->shop, true]], $this->asked, 'the seam is asked once, with the merchant\'s confirmation');
        $rows = self::mergeRows();
        self::assertCount(1, $rows);
        self::assertSame(['shopper11@example.test', (string) $this->shop, EventQueue::STATUS_PENDING], [$rows[0]['entity_id'], $rows[0]['id_shop'], $rows[0]['status']]);
        $payload = json_decode((string) $rows[0]['payload'], true);
        self::assertSame(['id_customer', 'merge_reason', 'merge_ts', 'visitor_token', 'send_customer'], array_keys($payload), 'the row names the customer by id: no address in its payload');
        self::assertSame([(int) $customer->id, IdentityCapture::REASON_SIGNED_IN, self::STORE_TOKEN, true], [$payload['id_customer'], $payload['merge_reason'], $payload['visitor_token'], $payload['send_customer']]);
        self::assertSame([], self::rows(), 'no ingest row: the merge sends the customer itself');

        $this->services->get(QueueProvider::FLUSHER)->flush();

        $requests = array_values(array_filter($this->engine->requests(), static fn (array $request): bool => $request['path'] !== '/api/setup/exchange'));
        self::assertSame([self::CUSTOMERS, self::MERGE], array_column($requests, 'path'), 'the customer first, then the merge');
        self::assertSame('shopper11@example.test', $requests[0]['body']['customers'][0]['email']);
        self::assertSame([
            'smaily_visitor_token' => self::STORE_TOKEN,
            'customer_email' => 'shopper11@example.test',
            'customer_external_id' => (string) $customer->id,
            'merge_ts' => $payload['merge_ts'],
            'merge_reason' => 'user_logged_in',
        ], $requests[1]['body'], 'the fields of contract §7 that the module sends');
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $payload['merge_ts']);
        self::assertSame(EventQueue::STATUS_SENT, self::mergeRows()[0]['status']);
        $stored = self::mergeRows()[0];
        self::assertTrue(json_decode((string) $stored['last_response'], true)['body']['ok']);
        self::assertStringNotContainsString(self::STORE_TOKEN, $stored['last_request'] . $stored['last_response'], 'the Log\'s copy of the exchange holds no token');
        self::assertSame([[IngestQueue::DOMAIN_CUSTOMERS, (string) $customer->id, EventQueue::STATUS_SENT]], self::summary(), 'the Log has the customer that the merge sent');
    }

    public function testARegistrationAndAGuestCheckoutAlsoQueueTheMerge(): void
    {
        $this->connect();
        $this->confirmTracking();
        $this->storefront();
        $this->token = self::ENGINE_TOKEN;
        $registered = $this->customer('shopper12@example.test', true);
        $guest = $this->customer('shopper13@example.test', true, ['is_guest' => 1]);

        // PrestaShop signs both in without actionAuthentication (facts file part 2).
        $this->fire('actionCustomerAccountAdd', ['newCustomer' => $registered]);
        $this->fire('actionCustomerAccountAdd', ['newCustomer' => $guest]);

        $payloads = array_map(static fn (array $row): array => json_decode((string) $row['payload'], true), self::mergeRows());
        self::assertSame(
            [
                [(int) $registered->id, IdentityCapture::REASON_SIGNED_IN, self::ENGINE_TOKEN, true],
                [(int) $guest->id, IdentityCapture::REASON_CHECKOUT, self::ENGINE_TOKEN, null],
            ],
            array_map(static fn (array $payload): array => [$payload['id_customer'], $payload['merge_reason'], $payload['visitor_token'], $payload['send_customer'] ?? null], $payloads),
            'a guest has no account to sign in to: group (c) does not send a guest'
        );
    }

    /**
     * @testWith [true]
     *           [false]
     */
    public function testWithoutTheMerchantsConfirmationNobodyIsSentOrJoinedWhateverTheNewsletter(bool $newsletter): void
    {
        $this->connect();
        $this->storefront();
        $customer = $this->customer('shopper14@example.test', $newsletter);

        $this->token = self::STORE_TOKEN;
        $this->fire('actionAuthentication', ['customer' => $customer]);
        $this->token = null;
        $this->fire('actionAuthentication', ['customer' => $customer]);
        $this->token = 'not-a-token';
        $this->fire('actionAuthentication', ['customer' => $customer]);

        self::assertSame([[$this->shop, false], [$this->shop, false], [$this->shop, false]], $this->asked);
        self::assertSame([], self::mergeRows());
    }

    public function testWithoutAVisitorTokenAConsentingSignInSendsTheCustomerAndNoMerge(): void
    {
        $this->connect();
        $this->confirmTracking();
        $this->storefront();
        $customer = $this->customer('shopper15@example.test', false);

        $this->fire('actionAuthentication', ['customer' => $customer]);
        $this->services->get(QueueProvider::FLUSHER)->flush();

        self::assertCount(1, $this->engine->requestsTo(self::CUSTOMERS));
        self::assertSame([], $this->engine->requestsTo(self::MERGE));
        $row = self::mergeRows()[0];
        self::assertSame(EventQueue::STATUS_SENT, $row['status']);
        self::assertSame('shopper15@example.test', json_decode((string) $row['last_request'], true)['customers'][0]['email'], 'the row keeps the customer that was sent for it');
    }

    public function testAConfirmationTakenBackBeforeTheSendSendsNothing(): void
    {
        $this->connect();
        $this->confirmTracking();
        $this->storefront();
        $this->token = self::STORE_TOKEN;
        $customer = $this->customer('shopper16@example.test', true);
        $this->fire('actionAuthentication', ['customer' => $customer]);
        $this->confirmTracking(false);

        $this->services->get(QueueProvider::FLUSHER)->flush();

        self::assertSame([], $this->engine->requestsTo(self::CUSTOMERS));
        self::assertSame([], $this->engine->requestsTo(self::MERGE));
        $row = self::mergeRows()[0];
        self::assertSame([EventQueue::STATUS_SKIPPED, IdentityMergeHandler::SKIPPED_NOTHING_TO_SEND], [$row['status'], $row['last_error']]);
    }

    public function testAMergeOfACustomerTheEngineLacksSendsTheCustomerOfTheScopeAndTriesOnceMore(): void
    {
        $this->connect();
        $this->storefront();
        $this->token = self::ENGINE_TOKEN;
        // No consent, but a buyer: the store sends this customer anyway (group b).
        $customer = $this->customer('shopper17@example.test', false);
        $this->order((int) $customer->id, date('Y-m-d H:i:s'));

        $this->fire('actionAuthentication', ['customer' => $customer]);
        $this->services->get(QueueProvider::FLUSHER)->flush();

        $requests = array_values(array_filter($this->engine->requests(), static fn (array $request): bool => $request['path'] !== '/api/setup/exchange'));
        self::assertSame([self::MERGE, self::CUSTOMERS, self::MERGE], array_column($requests, 'path'));
        self::assertSame(EventQueue::STATUS_SENT, self::mergeRows()[0]['status']);
    }

    public function testAMergeOfACustomerTheStoreDoesNotSendIsParkedWithoutTheEnginesText(): void
    {
        $this->connect();
        $this->storefront();
        $this->token = self::ENGINE_TOKEN;
        $customer = $this->customer('shopper18@example.test', false);

        $this->fire('actionAuthentication', ['customer' => $customer]);
        $this->services->get(QueueProvider::FLUSHER)->flush();

        self::assertSame([], $this->engine->requestsTo(self::CUSTOMERS), 'a customer outside the three groups is not sent');
        $row = self::mergeRows()[0];
        self::assertSame([EventQueue::STATUS_FAILED, IdentityMergeHandler::FAILED_NOT_SENT], [$row['status'], $row['last_error']]);
        self::assertSame(['http_status' => 404, 'body' => ['error' => 'customer_not_found']], json_decode((string) $row['last_response'], true));
        // The engine's 404 names the address it was asked about: nothing of that text is kept.
        self::assertStringNotContainsString('No customer found', (string) $row['last_response'] . $row['last_error']);

        // The Log's retry meets the same answer once and parks the row again: it never repeats the 404.
        $this->services->get(QueueProvider::EVENT_QUEUE)->retry([(int) $row['id']]);
        $this->services->get(QueueProvider::FLUSHER)->flush();
        self::assertCount(2, $this->engine->requestsTo(self::MERGE));
        self::assertSame(EventQueue::STATUS_FAILED, self::mergeRows()[0]['status']);
    }

    public function testNothingIsQueuedWithoutAConnectionOrOutsideTheShoppersOwnRequest(): void
    {
        $this->contactSync(ContactSyncMode::ALL_CUSTOMERS);
        $this->token = self::STORE_TOKEN;
        $customer = $this->customer('shopper19@example.test', true);
        $order = $this->order((int) $customer->id, date('Y-m-d H:i:s'));

        $this->storefront();
        $this->fire('actionCustomerAccountAdd', ['newCustomer' => $customer]);
        $this->fire('actionAuthentication', ['customer' => $customer]);
        $this->fire('actionObjectCustomerUpdateAfter', ['object' => $customer]);
        $this->fire('actionValidateOrder', ['order' => $order, 'customer' => $customer]);
        self::assertSame(0, $this->commit(), 'no connection: nothing is queued');
        self::assertSame([], self::mergeRows());
        self::assertSame([], $this->asked, 'and the storefront is not asked for a token');

        // An employee who adds a customer is not that shopper: no merge, whatever the connection.
        $this->connect();
        \Context::getContext()->controller = $this->savedController;
        $this->fire('actionCustomerAccountAdd', ['newCustomer' => $customer]);
        self::assertSame([], self::mergeRows());
        self::assertSame([], $this->asked);
    }

    public function testARefusedConnectionKeepsTheMergeWaiting(): void
    {
        $this->connect();
        $this->confirmTracking();
        $this->storefront();
        $this->token = self::STORE_TOKEN;
        $customer = $this->customer('shopper20@example.test', true);
        $this->fire('actionAuthentication', ['customer' => $customer]);
        $this->engine->revokeKeys();

        $this->services->get(QueueProvider::FLUSHER)->flush();
        $requests = count($this->engine->requests());
        $this->services->get(QueueProvider::FLUSHER)->flush();

        $row = self::mergeRows()[0];
        self::assertSame([EventQueue::STATUS_PENDING, '0', null], [$row['status'], $row['attempts'], $row['last_error']]);
        self::assertNotSame('', $this->connections()->find(0)->refusedAt());
        self::assertCount($requests, $this->engine->requests(), 'once the refusal is known, nothing more is sent');
    }

    public function testAPrivacyRequestFindsTheShoppersEngineRowsAndTheErasureClearsThem(): void
    {
        $connection = $this->connect();
        $this->confirmTracking();
        $this->storefront();
        $this->token = self::STORE_TOKEN;
        $shopper = $this->customer('Shopper30@Example.test', true);
        $this->address((int) $shopper->id, '+372 5550 0130');
        $order = $this->order((int) $shopper->id, date('Y-m-d H:i:s'));
        $other = $this->customer('shopper31@example.test', true);
        // What was sent: the shopper and the merge of a sign-in, and another shopper.
        $this->fire('actionAuthentication', ['customer' => $shopper]);
        $this->fire('actionAuthentication', ['customer' => $other]);
        $this->services->get(QueueProvider::FLUSHER)->flush();
        // An order that was sent, as the Log keeps it; and what still waits.
        $queue = $this->services->get(IngestQueue::class);
        $sentOrder = $queue->record($connection->id(), IngestQueue::DOMAIN_ORDERS, (string) $order->id, ['customer_email' => 'shopper30@example.test'], ['http_status' => 200, 'body' => ['ok' => true]], null);
        $waiting = [
            $queue->enqueue($connection->id(), IngestQueue::DOMAIN_CUSTOMERS, (string) $shopper->id),
            $queue->enqueue($connection->id(), IngestQueue::DOMAIN_ORDERS, (string) $order->id),
        ];
        // A product whose id is the shopper's customer id: a product row holds nothing of a shopper.
        $product = $queue->enqueue($connection->id(), IngestQueue::DOMAIN_CATALOG, (string) $shopper->id);
        self::db()->update(IngestQueue::TABLE, ['updated_at' => '2026-11-01 10:00:00'], '`id` = ' . $sentOrder);
        $before = self::rows();
        self::assertStringContainsString('shopper30@example.test', (string) $before[0]['last_request'], 'the Log\'s copy of what was sent names the shopper');
        $local = $this->services->get(LocalData::class);

        $export = $local->export(' Shopper30@Example.test ');

        self::assertSame(
            [
                ['engine.identity_merge', 'sent'],
                ['engine.customers', 'sent'],
                ['engine.orders', 'sent'],
                ['engine.customers', 'pending'],
                ['engine.orders', 'pending'],
            ],
            array_map(static fn (array $row): array => [$row['type'], $row['status']], $export['queue']),
            'the rows of both queues, never what was sent, and nothing of the other shopper or of a product'
        );

        $erased = $local->erase('shopper30@example.test');

        self::assertSame([2, 3], [$erased['queue_removed'], $erased['queue_cleared']]);
        $rows = array_column(self::rows(), null, 'id');
        self::assertSame([], array_intersect_key($rows, array_flip($waiting)), 'nothing more goes out for the shopper');
        foreach ([$before[0]['id'], $sentOrder] as $id) {
            self::assertSame(
                [EventQueue::STATUS_SENT, ResendGuard::ERASED_ENTITY, null, null, null],
                [$rows[$id]['status'], $rows[$id]['entity_key'], $rows[$id]['payload'], $rows[$id]['last_request'], $rows[$id]['last_response']],
                'a sent row stays as the record of a send, without the shopper'
            );
        }
        self::assertSame('2026-11-01 10:00:00', $rows[$sentOrder]['updated_at'], 'and leaves with the retention as before');
        self::assertSame([$before[1], IngestQueue::DOMAIN_CATALOG], [$rows[$before[1]['id']], $rows[$product]['domain']], 'the other shopper\'s row and the product\'s row are untouched');
        self::assertSame([(string) $shopper->id, EventQueue::STATUS_PENDING], [$rows[$product]['entity_key'], $rows[$product]['status']]);
        $merges = self::mergeRows();
        self::assertSame([ResendGuard::ERASED_ENTITY, 'shopper31@example.test'], array_column($merges, 'entity_id'));
        self::assertSame(['[]', null, null], [$merges[0]['payload'], $merges[0]['last_request'], $merges[0]['last_response']]);
        $kept = (string) json_encode([array_diff_key($rows, [$before[1]['id'] => 1]), $merges[0]]);
        self::assertStringNotContainsString('shopper30', $kept, 'neither queue holds the address');
        self::assertStringNotContainsString('5550', $kept);
        self::assertSame(
            [(int) $before[0]['id'] => ResendGuard::REASON_ERASED],
            (new ResendGuard())->ingestRefusals([(int) $before[0]['id'] => $rows[$before[0]['id']]]),
            'the Log refuses to send an erased row again'
        );
        self::assertSame(['queue_removed' => 0, 'queue_cleared' => 0], array_intersect_key($local->erase('shopper30@example.test'), ['queue_removed' => 1, 'queue_cleared' => 1]), 'a second erasure finds nothing');
    }

    public function testASignInOfAShopperWhoOptedOutOfPersonalizationSendsNothing(): void
    {
        $this->connect();
        $this->storefront();
        $this->token = self::ENGINE_TOKEN;
        $customer = $this->customer('shopper32@example.test', true);
        $this->fire('actionAuthentication', ['customer' => $customer]);
        $this->optOut('Shopper32@Example.test');
        $requests = count($this->engine->requests());

        $this->services->get(QueueProvider::FLUSHER)->flush();

        $row = self::mergeRows()[0];
        self::assertSame([EventQueue::STATUS_SKIPPED, IdentityMergeHandler::SKIPPED_OPTED_OUT], [$row['status'], $row['last_error']]);
        self::assertCount($requests, $this->engine->requests(), 'neither the customer nor the merge is sent');
        self::assertSame([], self::rows());
    }

    public function testACustomerTheEngineTookHasTheStoredOptOutSentAgainOnce(): void
    {
        $connection = $this->connect();
        $this->contactSync(ContactSyncMode::SUBSCRIBERS);
        $optedOut = $this->customer('Shopper33@Example.test', true);
        $allowed = $this->customer('shopper34@example.test', true);
        $at = $this->optOut('shopper33@example.test');
        $queue = $this->services->get(IngestQueue::class);
        foreach ([$optedOut, $allowed, $optedOut] as $customer) {
            $queue->enqueue($connection->id(), IngestQueue::DOMAIN_CUSTOMERS, (string) $customer->id);
        }

        $this->flush();
        $queue->enqueue($connection->id(), IngestQueue::DOMAIN_CUSTOMERS, (string) $optedOut->id);
        $this->flush();

        self::assertSame([EventQueue::STATUS_SENT], array_values(array_unique(array_column(self::rows(), 'status'))));
        $choices = $this->choiceRows();
        self::assertCount(1, $choices, 'one row for the shopper who opted out, however many rows the engine took; none while one waits');
        self::assertSame(
            [ContactEntity::key('shopper33@example.test'), $this->shop, EventQueue::STATUS_PENDING],
            [$choices[0]['entity_id'], (int) $choices[0]['id_shop'], $choices[0]['status']]
        );
        self::assertSame(
            ['connection' => $connection->id(), 'email' => 'shopper33@example.test', 'opt_out' => true, 'opted_out_at' => $at],
            json_decode((string) $choices[0]['payload'], true),
            'the stored choice with its moment, for the connection that took the customer'
        );

        $this->services->get(QueueProvider::FLUSHER)->flush();

        self::assertSame(EventQueue::STATUS_SENT, $this->choiceRows()[0]['status']);
        $sent = array_values(array_filter($this->engine->requests(), static fn (array $request): bool => substr($request['path'], -8) === '/opt-out'));
        self::assertCount(1, $sent);
        self::assertSame([true, $at], [$sent[0]['body']['opt_out'], $sent[0]['body']['opted_out_at']]);
    }

    /**
     * Records the shopper's own opt-out of personalization, as the account page does.
     *
     * @return string the moment of the choice, as the engine gets it
     */
    private function optOut(string $email): string
    {
        return $this->services->get(ProfilingRecord::class)->write($email, true, ProfilingRecord::SOURCE_ACCOUNT);
    }

    /**
     * The rows of the event queue that hold a choice about personalization, oldest first.
     *
     * @return list<array<string, mixed>>
     */
    private function choiceRows(): array
    {
        return self::db()->executeS(
            'SELECT * FROM `' . _DB_PREFIX_ . EventQueue::TABLE . '` WHERE `event_type` = \'' . ProfilingConsent::EVENT_TYPE . '\' ORDER BY `id`',
            true,
            false
        ) ?: [];
    }

    private function contactSync(string $mode, bool $guests = false): void
    {
        \Configuration::updateGlobalValue(ContactsSettings::ENABLED, '1');
        \Configuration::updateGlobalValue(ContactsSettings::CONFIRMED, '1');
        \Configuration::updateGlobalValue(ContactsSettings::MODE, $mode);
        \Configuration::updateGlobalValue(ContactsSettings::GUESTS, $guests ? '1' : '0');
        LegacyStore::reloadConfiguration();
    }

    /**
     * The merchant's confirmation that the store's cookie banner covers Smaily tracking.
     */
    private function confirmTracking(bool $confirmed = true): void
    {
        $this->services->get(EngineSettings::class)->setConsentConfirmed($confirmed, 0);
    }

    /**
     * Puts the shopper's own storefront request into the context.
     */
    private function storefront(): void
    {
        \Context::getContext()->controller = (new \ReflectionClass(\OrderController::class))->newInstanceWithoutConstructor();
    }

    /**
     * @param array<string, mixed> $columns
     */
    private function customer(string $email, bool $newsletter, array $columns = []): \Customer
    {
        $customer = new \Customer($this->addCustomer($this->shop, $email, $newsletter, $this->idLang, $columns + [
            'passwd' => pSQL(password_hash('not-a-real-password', PASSWORD_BCRYPT)),
            'birthday' => '0000-00-00',
            'secure_key' => md5(uniqid('', true)),
        ]));
        self::assertTrue(\Validate::isLoadedObject($customer));

        return $customer;
    }

    private function address(int $idCustomer, string $phone): void
    {
        self::db()->insert('address', [
            'id_country' => (int) \Configuration::get('PS_COUNTRY_DEFAULT'),
            'id_customer' => $idCustomer,
            'alias' => 'Test',
            'lastname' => 'Testlast',
            'firstname' => 'Testfirst',
            'address1' => '1 Test Street',
            'city' => 'Testcity',
            'phone' => pSQL($phone),
            'date_add' => date('Y-m-d H:i:s'),
            'date_upd' => date('Y-m-d H:i:s'),
            'active' => 1,
            'deleted' => 0,
        ]);
        $this->addresses[] = (int) self::db()->Insert_ID();
    }

    private function order(int $idCustomer, string $placedAt): \Order
    {
        self::db()->insert('orders', [
            'reference' => 'TESTORDER',
            'id_shop_group' => (int) \Shop::getGroupFromShop($this->shop),
            'id_shop' => $this->shop,
            'id_carrier' => 0,
            'id_lang' => $this->idLang,
            'id_customer' => $idCustomer,
            'id_cart' => 0,
            'id_currency' => (int) \Configuration::get('PS_CURRENCY_DEFAULT'),
            'id_address_delivery' => 0,
            'id_address_invoice' => 0,
            'current_state' => 0,
            'payment' => 'test',
            'total_paid' => 61.8,
            'total_paid_tax_incl' => 61.8,
            'invoice_date' => $placedAt,
            'delivery_date' => $placedAt,
            'date_add' => $placedAt,
            'date_upd' => $placedAt,
        ]);
        $id = (int) self::db()->Insert_ID();
        $this->orders[] = $id;

        return new \Order($id);
    }
}
