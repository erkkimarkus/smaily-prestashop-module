<?php
declare(strict_types=1);

namespace Tests\Integration\Automation;

use PrestaShop\Module\SmailyForPrestaShop\Automation\FirstOrder\FirstOrderTrigger;
use PrestaShop\Module\SmailyForPrestaShop\Automation\MappingRepository;
use PrestaShop\Module\SmailyForPrestaShop\Automation\Router;
use PrestaShop\Module\SmailyForPrestaShop\Automation\Trigger;
use PrestaShop\Module\SmailyForPrestaShop\Config\AutomationSettings;
use PrestaShop\Module\SmailyForPrestaShop\Config\ContactsSettings;
use PrestaShop\Module\SmailyForPrestaShop\Contacts\Capture\ContactEvents;
use PrestaShop\Module\SmailyForPrestaShop\Contacts\ContactSyncMode;
use PrestaShop\Module\SmailyForPrestaShop\Hook\Dispatcher;
use PrestaShop\Module\SmailyForPrestaShop\Http\Transport;
use PrestaShop\Module\SmailyForPrestaShop\Queue\EventQueue;
use PrestaShop\Module\SmailyForPrestaShop\Runtime\Provider\QueueProvider;
use PrestaShop\Module\SmailyForPrestaShop\Runtime\Services;
use PrestaShop\Module\SmailyForPrestaShop\Scheduler\Lock;
use PrestaShop\Module\SmailyForPrestaShop\Smaily\Account;
use PrestaShop\Module\SmailyForPrestaShop\Smaily\AccountStore;
use Tests\Integration\Automation\AbandonedCart\CartFixture;
use Tests\Integration\Contacts\ContactRows;
use Tests\Integration\Fixtures\FailingQueries;
use Tests\Integration\Fixtures\SmailyMockServer;
use Tests\Integration\IntegrationTestCase;
use Tests\Integration\Migration\LegacyStore;

require_once __DIR__ . '/../Migration/LegacyStore.php';
require_once __DIR__ . '/../Fixtures/SmailyMockServer.php';
require_once __DIR__ . '/../Contacts/ContactRows.php';
require_once __DIR__ . '/AbandonedCart/CartFixture.php';
require_once __DIR__ . '/../Fixtures/FailingQueries.php';

/**
 * The first-order automation follows a registered customer's first order (actionValidateOrder), as
 * the WooCommerce and Magento connectors do: one email for the customer's first order, none for a
 * later order or a guest checkout, through the router's workflow and account, never forcing an
 * opt-in. As the abandoned-cart reminder, it goes only where the shop's consent mode and Smaily's
 * status of the contact, read before the send, allow it (owner, 2026-11-20). Each order has a cart
 * created 30 minutes before it; the shop is in all customers unless a test says otherwise.
 */
final class FirstOrderAutomationTest extends IntegrationTestCase
{
    use ContactRows;

    private const PASSWORD = 'not-a-real-password';

    private const ADDRESS = 'shopper1@example.test';

    private const SENT = 'sent';

    private const RETRY = 'retry';

    private SmailyMockServer $mock;

    private Services $services;

    private int $shop;

    private int $idLang;

    private CartFixture $carts;

    /** @var list<int> */
    private array $orders = [];

    protected function setUp(): void
    {
        parent::setUp();
        LegacyStore::clear();
        $this->mock = SmailyMockServer::start();
        $this->mock->acceptCredentials('order-user', self::PASSWORD);
        $this->shop = (int) \Configuration::get('PS_SHOP_DEFAULT');
        $this->idLang = (int) \Configuration::get('PS_LANG_DEFAULT');
        $this->carts = new CartFixture();
        \Configuration::updateGlobalValue(ContactsSettings::FIELDS, 'store,first_name,last_name');
        \Configuration::updateGlobalValue(ContactsSettings::MODE, ContactSyncMode::ALL_CUSTOMERS);
        (new AutomationSettings())->setEnabled(Trigger::FIRST_ORDER, true, 0);
        (new MappingRepository())->upsert(0, Trigger::FIRST_ORDER, '', MappingRepository::ACCOUNT_DEFAULT, 301);
        LegacyStore::reloadConfiguration();
        $this->services = Services::fromProviders(Services::PROVIDERS);
        $this->services->set(Transport::class, fn (): Transport => $this->mock->transport());
        // The capture also hears the order; its rows would be written when the test process ends.
        $this->services->set(ContactEvents::class, fn (Services $services): ContactEvents => new ContactEvents(
            $services->get(QueueProvider::EVENT_QUEUE),
            $services->get(ContactsSettings::class),
            new Lock(),
            static fn () => $services->get(QueueProvider::FLUSHER),
            null,
            static function (): void {
            }
        ));
        self::assertTrue($this->services->get(AccountStore::class)->save(new Account(0, '', 'order-store', 'order-user', self::PASSWORD)));
    }

    protected function tearDown(): void
    {
        if (defined('_PS_ROOT_DIR_')) {
            if ($this->orders !== []) {
                self::db()->delete('orders', '`id_order` IN (' . implode(',', $this->orders) . ')');
            }
            $this->carts->remove();
            $this->removeContactRows();
            LegacyStore::restore();
        }
        parent::tearDown();
    }

    /**
     * Proof 6: a customer's first order sends one first-order email with the order's fields and
     * the marker, in the order's language, after one status read through the sending account.
     */
    public function testACustomersFirstOrderSendsOneFirstOrderEmail(): void
    {
        $customer = $this->customer('order1@example.test');
        $order = $this->order($customer);

        $this->validate($order, $customer);
        $this->flush();

        $rows = $this->rows();
        self::assertSame([[FirstOrderTrigger::EVENT_TYPE, EventQueue::STATUS_SENT, 'order1@example.test', strtolower((string) \Language::getIsoById($this->idLang))]], array_map(
            static fn (array $row): array => [$row['event_type'], $row['status'], $row['entity_id'], $row['language']],
            $rows
        ));
        self::assertSame([['/api/contact.php', ['email' => 'order1@example.test'], 'order-user']], $this->reads());
        $requests = $this->sends();
        self::assertCount(1, $requests);
        self::assertSame(['/api/autoresponder.php', 'order-user', 301, false], [
            $requests[0]['path'],
            $requests[0]['username'],
            $requests[0]['body']['autoresponder'],
            $requests[0]['body']['force_opt_in'],
        ]);
        $address = $requests[0]['body']['addresses'][0];
        self::assertMatchesRegularExpression('/^\d{4}-\d\d-\d\d \d\d:\d\d:\d\d$/', $address['first_order_automation_at']);
        unset($address['first_order_automation_at']);
        $currency = new \Currency((int) \Configuration::get('PS_CURRENCY_DEFAULT'));
        self::assertSame([
            'email' => 'order1@example.test',
            'store' => (string) \ShopUrl::getMainShopDomain($this->shop),
            'first_name' => 'Testfirst',
            'last_name' => 'Testlast',
            'order_id' => 'TESTORDER',
            'order_total' => number_format(61.8, (int) $currency->precision, '.', ''),
            'order_currency' => (string) $currency->iso_code,
        ], $address);
    }

    public function testASecondOrderSendsNone(): void
    {
        $customer = $this->customer('order1@example.test');
        $this->order($customer);

        $this->validate($this->order($customer), $customer);

        self::assertSame([], $this->rows());
    }

    /**
     * The first-order email goes to a customer without an earlier order. A read of the earlier
     * orders that fails (facts file 6.11) is not "none": read so, a returning customer got the
     * first-order email and its discount code. The order itself goes through, and nothing is
     * queued.
     */
    public function testAnEarlierOrderThatCouldNotBeReadQueuesNothing(): void
    {
        $customer = $this->customer('order1@example.test');
        $this->order($customer);
        $second = $this->order($customer);

        $failed = FailingQueries::during('/^SELECT 1 FROM `[^`]+orders` WHERE `id_customer`/', function () use ($second, $customer): void {
            $this->validate($second, $customer);
        });

        self::assertSame(1, $failed);
        self::assertSame([], $this->rows());
    }

    /**
     * Two first orders in one flush. A read of the second row's check fails after the first row's
     * email went out: the second row is retried, and the first keeps its outcome. A failure that
     * ended the whole batch handed the first row to the handler again, and its email went twice.
     */
    public function testARowWhoseCheckCouldNotBeReadLeavesTheSentRowBeforeItAlone(): void
    {
        $first = $this->customer('order1@example.test');
        $second = $this->customer('order2@example.test');
        $this->validate($this->order($first), $first);
        $order = $this->order($second);
        $this->validate($order, $second);

        $failed = FailingQueries::during('/^SELECT c\.`id_customer`, c\.`date_add`.* WHERE c\.`id_cart` = ' . (int) $order->id_cart . '$/', function (): void {
            $this->flush();
        });

        self::assertSame(1, $failed);
        self::assertSame(
            [[EventQueue::STATUS_SENT, '0'], [EventQueue::STATUS_PENDING, '1']],
            array_map(static fn (array $row): array => [$row['status'], (string) $row['attempts']], $this->rows())
        );
        self::assertCount(1, $this->sends(), 'the first email went once');

        self::db()->update(EventQueue::TABLE, ['next_retry_at' => gmdate('Y-m-d H:i:s', time() - 1)], '`status` = \'pending\'');
        $this->flush();
        self::assertCount(2, $this->sends());
    }

    /**
     * A cart split by carrier becomes several orders in one request: the first of them is the
     * customer's first order.
     */
    public function testOneCartSplitIntoTwoOrdersSendsOne(): void
    {
        $customer = $this->customer('order1@example.test');
        $first = $this->order($customer);
        $second = $this->order($customer, (int) $first->id_cart);

        $this->validate($first, $customer);
        $this->validate($second, $customer);

        self::assertCount(1, $this->rows());
    }

    public function testAGuestCheckoutSendsNone(): void
    {
        $customer = $this->customer('order1@example.test', ['is_guest' => 1]);

        $this->validate($this->order($customer), $customer);

        self::assertSame([], $this->rows());
    }

    public function testTheAutomationOffSendsNone(): void
    {
        (new AutomationSettings())->setEnabled(Trigger::FIRST_ORDER, false, 0);
        LegacyStore::reloadConfiguration();
        $customer = $this->customer('order1@example.test');

        $this->validate($this->order($customer), $customer);

        self::assertSame([], $this->rows());
    }

    /**
     * A row queued while the automation was on, and sent after it was switched off, ends skipped
     * with the router's reason, which the Log shows.
     */
    public function testARowSentAfterTheAutomationWasSwitchedOffIsSkippedWithItsReason(): void
    {
        $customer = $this->customer('order1@example.test');
        $this->validate($this->order($customer), $customer);
        (new AutomationSettings())->setEnabled(Trigger::FIRST_ORDER, false, 0);
        LegacyStore::reloadConfiguration();

        $this->flush();

        self::assertSame([[EventQueue::STATUS_SKIPPED, Router::SKIPPED_AUTOMATION_OFF]], array_map(
            static fn (array $row): array => [$row['status'], $row['last_error']],
            $this->rows()
        ));
        self::assertSame([], $this->mock->requests());
    }

    /**
     * @return iterable<string, array{string, string, string, string}>
     */
    public function consentCases(): iterable
    {
        foreach ([ContactSyncMode::ALL_CUSTOMERS, ContactSyncMode::SUBSCRIBERS, ContactSyncMode::CHECKOUT_OPTIN] as $mode) {
            foreach (['none', 'earlier', 'ticked'] as $consent) {
                foreach (['subscribed', 'unsubscribed', 'unknown', 'fails'] as $smaily) {
                    yield implode(', ', [$mode, $consent, $smaily]) => [$mode, $consent, $smaily, self::expected($mode, $consent, $smaily)];
                }
            }
        }
    }

    /**
     * Proof 7: for each consent mode, each store consent (none, a subscription older than the
     * order's cart, the box ticked in this checkout) and each answer of Smaily's status read, the
     * first order sends, skips with a reason code, or keeps its row for a retry: the abandoned-cart
     * reminder's rule. Each case runs the order, the queue and the flush against the Smaily mock.
     *
     * @dataProvider consentCases
     */
    public function testTheFirstOrderEmailFollowsTheConsentMode(string $mode, string $consent, string $smaily, string $expected): void
    {
        \Configuration::updateGlobalValue(ContactsSettings::MODE, $mode);
        LegacyStore::reloadConfiguration();
        if ($smaily === 'fails') {
            $this->mock->failContactReads(true);
        } elseif ($smaily !== 'unknown') {
            $this->mock->setContacts([self::ADDRESS => $smaily === 'subscribed' ? '0' : '1']);
        }
        // The order's cart was created 30 minutes ago: an earlier subscription predates it, a tick
        // in this checkout came after it.
        $columns = $consent === 'none' ? [] : [
            'newsletter' => 1,
            'newsletter_date_add' => date('Y-m-d H:i:s', $consent === 'earlier' ? time() - 86400 * 30 : time() - 600),
        ];
        $customer = $this->customer(self::ADDRESS, $columns);

        $this->validate($this->order($customer), $customer);
        $this->flush();

        self::assertSame([['/api/contact.php', ['email' => self::ADDRESS], 'order-user']], $this->reads(), 'one status read through the sending account');
        $row = $this->rows()[0];
        if ($expected === self::SENT) {
            self::assertCount(1, $this->sends());
            self::assertFalse($this->sends()[0]['body']['force_opt_in']);
            self::assertSame(EventQueue::STATUS_SENT, $row['status']);
        } elseif ($expected === self::RETRY) {
            self::assertSame([], $this->sends());
            self::assertSame([EventQueue::STATUS_PENDING, '1'], [$row['status'], (string) $row['attempts']]);
            self::assertNotNull($row['next_retry_at']);
        } else {
            self::assertSame([], $this->sends());
            self::assertSame([EventQueue::STATUS_SKIPPED, $expected], [$row['status'], $row['last_error']]);
        }
    }

    /**
     * Subscribers only: an active newsletter-block row of the address is the store's consent.
     */
    public function testANewsletterBlockRowIsConsentInSubscribersOnly(): void
    {
        if (!self::db()->executeS('SHOW TABLES LIKE \'' . _DB_PREFIX_ . 'emailsubscription\'')) {
            self::markTestSkipped('ps_emailsubscription is not installed.');
        }
        \Configuration::updateGlobalValue(ContactsSettings::MODE, ContactSyncMode::SUBSCRIBERS);
        LegacyStore::reloadConfiguration();
        $this->addNewsletterRow($this->shop, self::ADDRESS, true, $this->idLang);
        $customer = $this->customer(self::ADDRESS);

        $this->validate($this->order($customer), $customer);
        $this->flush();

        self::assertCount(1, $this->sends());
    }

    /**
     * A read that failed sends the email at a later attempt, once Smaily answers.
     */
    public function testAFailedReadIsRetriedAndThenSent(): void
    {
        $this->mock->failContactReads(true);
        $customer = $this->customer(self::ADDRESS);
        $this->validate($this->order($customer), $customer);
        $this->flush();
        self::assertSame([], $this->sends());

        $this->mock->failContactReads(false);
        self::db()->update(EventQueue::TABLE, ['next_retry_at' => gmdate('Y-m-d H:i:s', time() - 1)]);
        $this->flush();

        self::assertCount(1, $this->sends());
        self::assertSame(EventQueue::STATUS_SENT, $this->rows()[0]['status']);
    }

    /**
     * No log line, queue `last_error` or failure names a contact: both skips, a failed read and a
     * refused request carry reason codes only.
     */
    public function testNoOutcomeNamesAContact(): void
    {
        $logs = (int) self::db()->getValue('SELECT MAX(`id_log`) FROM `' . _DB_PREFIX_ . 'log`', false);
        \Configuration::updateGlobalValue(ContactsSettings::MODE, ContactSyncMode::SUBSCRIBERS);
        LegacyStore::reloadConfiguration();
        $this->mock->setContacts(['shopper1@example.test' => '0', 'shopper2@example.test' => '1']);
        foreach (['shopper1@example.test', 'shopper2@example.test', 'shopper3@example.test'] as $email) {
            $customer = $this->customer($email);
            $this->validate($this->order($customer), $customer);
        }
        $this->flush();

        // A failed read, then a refused request.
        $this->mock->failContactReads(true);
        $customer = $this->customer('shopper4@example.test');
        $this->validate($this->order($customer), $customer);
        $this->flush();
        $this->mock->failContactReads(false);
        $this->mock->acceptCredentials('order-user', 'another-fake-password');
        self::db()->update(EventQueue::TABLE, ['next_retry_at' => gmdate('Y-m-d H:i:s', time() - 1)], '`status` = \'pending\'');
        $this->flush();

        $rows = $this->rows();
        self::assertSame(
            [EventQueue::STATUS_SENT, EventQueue::STATUS_SKIPPED, EventQueue::STATUS_SKIPPED],
            array_column(array_slice($rows, 0, 3), 'status')
        );
        self::assertNotSame(EventQueue::STATUS_SENT, $rows[3]['status']);
        self::assertNotSame('', (string) $rows[3]['last_error'], 'the refused request left its reason');
        $texts = array_merge(
            array_column($rows, 'last_error'),
            array_column(self::db()->executeS('SELECT `message` FROM `' . _DB_PREFIX_ . 'log` WHERE `id_log` > ' . $logs) ?: [], 'message')
        );
        $texts = array_values(array_filter($texts, static fn ($text): bool => $text !== null && $text !== ''));
        foreach ($texts as $text) {
            foreach (['shopper', 'example.test', 'Testfirst', 'Testlast', 'not-a-real-password', 'another-fake-password', 'TESTORDER'] as $quoted) {
                self::assertStringNotContainsString($quoted, (string) $text);
            }
        }
        self::assertContains('unsubscribed_in_smaily', $texts);
        self::assertContains('no_marketing_consent', $texts);
    }

    private static function expected(string $mode, string $consent, string $smaily): string
    {
        if ($smaily === 'fails') {
            return self::RETRY;
        }
        if ($smaily === 'unsubscribed') {
            return 'unsubscribed_in_smaily';
        }
        if ($smaily === 'subscribed' || $mode === ContactSyncMode::ALL_CUSTOMERS) {
            return self::SENT;
        }
        $consented = $mode === ContactSyncMode::SUBSCRIBERS ? $consent !== 'none' : $consent === 'ticked';

        return $consented ? self::SENT : 'no_marketing_consent';
    }

    private function validate(\Order $order, \Customer $customer): void
    {
        (new Dispatcher(static fn (): bool => true, fn (): Services => $this->services, static function (): void {
        }))->run('actionValidateOrder', ['order' => $order, 'customer' => $customer]);
    }

    private function flush(): void
    {
        $this->services->get(QueueProvider::FLUSHER)->flush();
    }

    /**
     * @param array<string, mixed> $columns
     */
    private function customer(string $email, array $columns = []): \Customer
    {
        return new \Customer($this->addCustomer($this->shop, $email, (bool) ($columns['newsletter'] ?? false), $this->idLang, $columns));
    }

    /**
     * An order of the customer, for $idCart or a new cart created 30 minutes ago.
     */
    private function order(\Customer $customer, ?int $idCart = null): \Order
    {
        $now = gmdate('Y-m-d H:i:s');
        self::db()->insert('orders', [
            'reference' => 'TESTORDER',
            'id_shop_group' => (int) \Shop::getGroupFromShop($this->shop),
            'id_shop' => $this->shop,
            'id_carrier' => 0,
            'id_lang' => $this->idLang,
            'id_customer' => (int) $customer->id,
            'id_cart' => $idCart ?? $this->carts->cart((int) $customer->id, 30),
            'id_currency' => (int) \Configuration::get('PS_CURRENCY_DEFAULT'),
            'id_address_delivery' => 0,
            'id_address_invoice' => 0,
            'current_state' => 0,
            'payment' => 'test',
            'total_paid' => 61.8,
            'invoice_date' => $now,
            'delivery_date' => $now,
            'date_add' => $now,
            'date_upd' => $now,
        ]);
        $id = (int) self::db()->Insert_ID();
        $this->orders[] = $id;

        return new \Order($id);
    }

    /**
     * @return list<array<string, string|null>>
     */
    private function rows(): array
    {
        return self::db()->executeS(
            'SELECT `event_type`, `status`, `entity_id`, `language`, `last_error`, `attempts`, `next_retry_at` FROM `' . _DB_PREFIX_ . EventQueue::TABLE . '` ORDER BY `id`',
            true,
            false
        ) ?: [];
    }

    /**
     * The status reads the mock received, as [path, query, user].
     *
     * @return list<array{string, array<string, string>, ?string}>
     */
    private function reads(): array
    {
        return array_values(array_map(
            static fn (array $request): array => [$request['path'], $request['query'], $request['username']],
            array_filter($this->mock->requests(), static fn (array $request): bool => $request['method'] === 'GET')
        ));
    }

    /**
     * The emails the mock received.
     *
     * @return list<array<string, mixed>>
     */
    private function sends(): array
    {
        return array_values(array_filter(
            $this->mock->requests(),
            static fn (array $request): bool => $request['method'] === 'POST' && $request['path'] === '/api/autoresponder.php'
        ));
    }
}
