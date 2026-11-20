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
use Tests\Integration\Contacts\ContactRows;
use Tests\Integration\Fixtures\SmailyMockServer;
use Tests\Integration\IntegrationTestCase;
use Tests\Integration\Migration\LegacyStore;

require_once __DIR__ . '/../Migration/LegacyStore.php';
require_once __DIR__ . '/../Fixtures/SmailyMockServer.php';
require_once __DIR__ . '/../Contacts/ContactRows.php';

/**
 * The first-order automation follows a registered customer's first order (actionValidateOrder), as
 * the WooCommerce and Magento connectors do: one email for the customer's first order, none for a
 * later order or a guest checkout, through the router's workflow and account, never forcing an
 * opt-in.
 */
final class FirstOrderAutomationTest extends IntegrationTestCase
{
    use ContactRows;

    private const PASSWORD = 'not-a-real-password';

    private SmailyMockServer $mock;

    private Services $services;

    private int $shop;

    private int $idLang;

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
        \Configuration::updateGlobalValue(ContactsSettings::FIELDS, 'store,first_name,last_name');
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
            $this->removeContactRows();
            LegacyStore::restore();
        }
        parent::tearDown();
    }

    /**
     * Proof 6: a customer's first order sends one first-order email with the order's fields and
     * the marker, in the order's language.
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
        $requests = $this->mock->requests();
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
     * A cart split by carrier becomes several orders in one request: the first of them is the
     * customer's first order.
     */
    public function testOneCartSplitIntoTwoOrdersSendsOne(): void
    {
        $customer = $this->customer('order1@example.test');
        $first = $this->order($customer);
        $second = $this->order($customer);

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
     * @return iterable<string, array{string, bool, bool}>
     */
    public function consentCases(): iterable
    {
        foreach ([ContactSyncMode::SUBSCRIBERS, ContactSyncMode::ALL_CUSTOMERS, ContactSyncMode::CHECKOUT_OPTIN] as $mode) {
            yield $mode . ', no newsletter consent' => [$mode, false, true];
            yield $mode . ', newsletter consent' => [$mode, true, true];
            yield $mode . ', contact sync off' => [$mode, false, false];
        }
    }

    /**
     * Proof 7: as in the siblings, the first-order email asks neither the consent mode nor the
     * newsletter consent nor the contact sync; `force_opt_in` false keeps a Smaily unsubscribe.
     *
     * @dataProvider consentCases
     */
    public function testTheFirstOrderEmailGoesInEveryConsentMode(string $mode, bool $newsletter, bool $contactSync): void
    {
        \Configuration::updateGlobalValue(ContactsSettings::MODE, $mode);
        \Configuration::updateGlobalValue(ContactsSettings::ENABLED, $contactSync ? '1' : '0');
        \Configuration::updateGlobalValue(ContactsSettings::CONFIRMED, '1');
        LegacyStore::reloadConfiguration();
        $customer = $this->customer('order1@example.test', ['newsletter' => (int) $newsletter]);

        $this->validate($this->order($customer), $customer);
        $this->flush();

        self::assertCount(1, $this->mock->requests());
        self::assertFalse($this->mock->requests()[0]['body']['force_opt_in']);
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

    private function order(\Customer $customer): \Order
    {
        $now = gmdate('Y-m-d H:i:s');
        self::db()->insert('orders', [
            'reference' => 'TESTORDER',
            'id_shop_group' => (int) \Shop::getGroupFromShop($this->shop),
            'id_shop' => $this->shop,
            'id_carrier' => 0,
            'id_lang' => $this->idLang,
            'id_customer' => (int) $customer->id,
            'id_cart' => 0,
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
            'SELECT `event_type`, `status`, `entity_id`, `language`, `last_error` FROM `' . _DB_PREFIX_ . EventQueue::TABLE . '` ORDER BY `id`',
            true,
            false
        ) ?: [];
    }
}
