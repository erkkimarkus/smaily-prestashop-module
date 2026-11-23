<?php
declare(strict_types=1);

namespace Tests\Integration\Contacts;

use PrestaShop\Module\SmailyForPrestaShop\Config\ContactsSettings;
use PrestaShop\Module\SmailyForPrestaShop\Contacts\Capture\ContactEvents;
use PrestaShop\Module\SmailyForPrestaShop\Contacts\ContactSyncMode;
use PrestaShop\Module\SmailyForPrestaShop\Contacts\Reconciler;
use PrestaShop\Module\SmailyForPrestaShop\Queue\EventQueue;
use PrestaShop\Module\SmailyForPrestaShop\Runtime\Services;
use PrestaShop\Module\SmailyForPrestaShop\Scheduler\Deadline;
use PrestaShop\Module\SmailyForPrestaShop\Scope\ScopeResolver;
use PrestaShop\Module\SmailyForPrestaShop\Scope\StoreLayout;
use PrestaShop\Module\SmailyForPrestaShop\Smaily\Account;
use PrestaShop\Module\SmailyForPrestaShop\Smaily\AccountStore;
use Tests\Integration\Fixtures\SmailyMockServer;
use Tests\Integration\IntegrationTestCase;

require_once __DIR__ . '/../Fixtures/SmailyMockServer.php';
require_once __DIR__ . '/ContactRows.php';

/**
 * Acceptance 7 of the capture: the reconcile writes Smaily's unsubscribes straight to the store's
 * tables, so no capture hook fires and nothing goes back to Smaily.
 */
final class ReconcileCaptureTest extends IntegrationTestCase
{
    use ContactRows;

    private const PASSWORD = 'not-a-real-password';

    private const ACCOUNT_TABLE = 'smaily_connect_account';

    private const KEYS = [
        ContactsSettings::ENABLED,
        ContactsSettings::CONFIRMED,
        ContactsSettings::MODE,
        StoreLayout::SEPARATE_STORES,
        Reconciler::CURSORS,
    ];

    private SmailyMockServer $mock;

    /** @var list<array<string, mixed>> */
    private array $savedAccounts = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->mock = SmailyMockServer::start();
        $this->mock->acceptCredentials('api-user', self::PASSWORD);
        $this->savedAccounts = self::db()->executeS('SELECT * FROM `' . _DB_PREFIX_ . self::ACCOUNT_TABLE . '`', true, false) ?: [];
        self::db()->delete(self::ACCOUNT_TABLE);
        self::db()->delete(EventQueue::TABLE);
        $this->deleteKeys();
        \Configuration::updateGlobalValue(StoreLayout::SEPARATE_STORES, '1');
        \Configuration::updateGlobalValue(ContactsSettings::ENABLED, '1');
        \Configuration::updateGlobalValue(ContactsSettings::CONFIRMED, '1');
        \Configuration::updateGlobalValue(ContactsSettings::MODE, ContactSyncMode::SUBSCRIBERS);
        $store = Services::fromProviders(Services::PROVIDERS)->get(AccountStore::class);
        self::assertTrue($store->save(new Account((int) \Configuration::get('PS_SHOP_DEFAULT'), '', 'store', 'api-user', self::PASSWORD)));
        $this->events()->commit();
        self::db()->delete(EventQueue::TABLE);
    }

    protected function tearDown(): void
    {
        if (defined('_PS_ROOT_DIR_')) {
            $this->events()->commit();
            $this->removeContactRows();
            self::db()->delete(EventQueue::TABLE);
            self::db()->delete(self::ACCOUNT_TABLE);
            foreach ($this->savedAccounts as $row) {
                self::db()->insert(self::ACCOUNT_TABLE, array_map(static fn ($value) => pSQL((string) $value), $row));
            }
            $this->deleteKeys();
        }
        parent::tearDown();
    }

    public function testAReconcileRunQueuesNothingThroughTheCaptureListeners(): void
    {
        $idShop = (int) \Configuration::get('PS_SHOP_DEFAULT');
        $idLang = (int) \Configuration::get('PS_LANG_DEFAULT');
        $customer = $this->addCustomer($idShop, 'shopper1@example.test', true, $idLang);
        $guest = $this->addNewsletterRow($idShop, 'shopper2@example.test', true, $idLang);
        $this->mock->setUnsubscribers(['shopper1@example.test', 'shopper2@example.test']);

        $services = Services::fromProviders(Services::PROVIDERS);
        $reconciler = new Reconciler($services->get(ScopeResolver::class), $services->get(ContactsSettings::class), $this->mock->transport());
        $reconciler->run(new Deadline(microtime(true) + 15, static fn (): float => microtime(true)));

        self::assertSame([0, 0], [$this->newsletter($customer), $this->active($guest)], 'the reconcile applied the unsubscribes');
        self::assertSame(0, $this->events()->commit());
        self::assertSame(0, (int) self::db()->getValue('SELECT COUNT(*) FROM `' . _DB_PREFIX_ . EventQueue::TABLE . '`', false));
    }

    private function events(): ContactEvents
    {
        return Services::instance()->get(ContactEvents::class);
    }

    private function deleteKeys(): void
    {
        foreach (self::KEYS as $key) {
            \Configuration::deleteByName($key);
        }
    }
}
