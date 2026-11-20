<?php
declare(strict_types=1);

namespace Tests\Integration\Scheduler;

use PrestaShop\Module\SmailyForPrestaShop\Config\ContactsSettings;
use PrestaShop\Module\SmailyForPrestaShop\Config\TickSettings;
use PrestaShop\Module\SmailyForPrestaShop\Queue\EventQueue;
use PrestaShop\Module\SmailyForPrestaShop\Runtime\Provider\SchedulerProvider;
use PrestaShop\Module\SmailyForPrestaShop\Runtime\Services;
use PrestaShop\Module\SmailyForPrestaShop\Scheduler\Lock;
use PrestaShop\Module\SmailyForPrestaShop\Scheduler\Tick;
use PrestaShop\Module\SmailyForPrestaShop\Scheduler\TickState;
use Tests\Integration\Contacts\ContactRows;
use Tests\Integration\IntegrationTestCase;

require_once __DIR__ . '/../Contacts/ContactRows.php';

/**
 * A shopper who logs in or registers on a request that works after its response stays logged in:
 * the response carries PrestaShop's cookie with the login's session, although the fallback tick or
 * the contact capture sent it before the request ended. The tests drive the shop's own web server
 * over HTTP as a browser does, cookies included; they run inside the shop's container, where the
 * web server listens on port 80.
 *
 * The web server finds no Smaily account, and its tick finds every job run a moment ago, so it
 * calls no outside service. Skipped where the web server cannot send a response early (no
 * fastcgi_finish_request(), for example Apache with mod_php): no work runs after a response there.
 */
final class AfterResponseSessionTest extends IntegrationTestCase
{
    use ContactRows;

    private const PASSWORD = 'Invented-Pass-4711!';

    private const ACCOUNT_TABLE = 'smaily_connect_account';

    private const KEYS = [
        TickSettings::TICK_STATE,
        ContactsSettings::ENABLED,
        ContactsSettings::CONFIRMED,
        'PS_CUSTOMER_CREATION_EMAIL',
    ];

    /** Whether the web server works after its response; null until the first test has tried. */
    private static ?bool $worksAfterResponse = null;

    /** @var array<string, string|false> */
    private array $savedKeys = [];

    /** @var list<array<string, mixed>> */
    private array $savedAccounts = [];

    private int $lastGuest = 0;

    private int $lastConnection = 0;

    /** Whether setUp() has read what tearDown() puts back. */
    private bool $captured = false;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (self::KEYS as $key) {
            $this->savedKeys[$key] = \Configuration::getGlobalValue($key);
        }
        $this->savedAccounts = self::db()->executeS('SELECT * FROM `' . _DB_PREFIX_ . self::ACCOUNT_TABLE . '`', true, false) ?: [];
        $this->lastGuest = (int) self::db()->getValue('SELECT MAX(`id_guest`) FROM `' . _DB_PREFIX_ . 'guest`', false);
        $this->lastConnection = (int) self::db()->getValue('SELECT MAX(`id_connections`) FROM `' . _DB_PREFIX_ . 'connections`', false);
        $this->captured = true;
        self::db()->delete(self::ACCOUNT_TABLE);
        // The shop's own welcome email of a registration is not sent.
        \Configuration::updateGlobalValue('PS_CUSTOMER_CREATION_EMAIL', '0');

        if (self::$worksAfterResponse === null) {
            $this->lastTickWas(3600);
            $this->request($this->browser(), 'authentication');
            self::$worksAfterResponse = $this->trafficTickRan();
        }
        if (!self::$worksAfterResponse) {
            self::markTestSkipped('The web server of this shop runs no work after a response (no fastcgi_finish_request()).');
        }
    }

    protected function tearDown(): void
    {
        if ($this->captured) {
            $db = self::db();
            if ($this->addedCustomers !== []) {
                $customers = implode(',', $this->addedCustomers);
                $db->delete('customer_group', 'id_customer IN (' . $customers . ')');
                $db->delete('customer_session', 'id_customer IN (' . $customers . ')');
                if ($db->executeS('SHOW TABLES LIKE \'' . _DB_PREFIX_ . 'psgdpr_log\'', true, false)) {
                    $db->delete('psgdpr_log', 'id_customer IN (' . $customers . ')');
                }
            }
            $this->removeContactRows();
            $db->delete('guest', 'id_guest > ' . $this->lastGuest);
            $db->delete('connections_source', 'id_connections > ' . $this->lastConnection);
            $db->delete('connections', 'id_connections > ' . $this->lastConnection);
            $db->delete(EventQueue::TABLE, 'entity_id LIKE \'after-response-%@example.test\'');
            $db->delete(self::ACCOUNT_TABLE);
            foreach ($this->savedAccounts as $row) {
                $db->insert(self::ACCOUNT_TABLE, array_map(static fn ($value) => $value === null ? null : pSQL((string) $value), $row), true);
            }
            foreach ($this->savedKeys as $key => $value) {
                if ($value === false) {
                    \Configuration::deleteByName($key);
                } else {
                    \Configuration::updateGlobalValue($key, $value);
                }
            }
        }
        parent::tearDown();
    }

    public function testAShopperWhoLogsInWhileATickIsDueStaysLoggedIn(): void
    {
        $shop = (int) \Configuration::get('PS_SHOP_DEFAULT');
        $this->addCustomer($shop, 'after-response-1@example.test', false, (int) \Configuration::get('PS_LANG_DEFAULT'), [
            'passwd' => pSQL(password_hash(self::PASSWORD, PASSWORD_BCRYPT)),
        ]);
        $browser = $this->browser();
        $this->lastTickWas(0);
        $this->request($browser, 'authentication');
        $this->lastTickWas(3600);

        $status = $this->request($browser, 'authentication', [
            'back' => '',
            'email' => 'after-response-1@example.test',
            'password' => self::PASSWORD,
            'submitLogin' => '1',
        ]);

        self::assertSame(302, $status, 'the login is accepted');
        self::assertTrue($this->trafficTickRan(), 'the tick ran after the login\'s response');
        self::assertSame(200, $this->request($browser, 'my-account'), 'the next request opens the account page: the shopper is logged in');
    }

    public function testAShopperWhoRegistersWhileATickIsDueIsLoggedIn(): void
    {
        $browser = $this->browser();
        $this->lastTickWas(0);
        $this->request($browser, 'registration');
        $this->lastTickWas(3600);

        $status = $this->register($browser, 'after-response-2@example.test');

        self::assertSame(302, $status, 'the registration is accepted');
        self::assertTrue($this->trafficTickRan(), 'the tick ran after the registration\'s response');
        self::assertSame(200, $this->request($browser, 'my-account'), 'the next request opens the account page: the shopper is logged in');
    }

    public function testAShopperWhoRegistersWhileTheContactCaptureFlushesIsLoggedIn(): void
    {
        \Configuration::deleteByName(ContactsSettings::ENABLED);
        \Configuration::deleteByName(ContactsSettings::CONFIRMED);
        \Configuration::updateGlobalValue(ContactsSettings::ENABLED, '1');
        \Configuration::updateGlobalValue(ContactsSettings::CONFIRMED, '1');
        $browser = $this->browser();
        $this->lastTickWas(0);
        $this->request($browser, 'registration');

        $status = $this->register($browser, 'after-response-3@example.test');

        self::assertSame(302, $status, 'the registration is accepted');
        self::assertSame(EventQueue::STATUS_SKIPPED, $this->flushedStatus('after-response-3@example.test'), 'the capture flushed the contact\'s row after the response; no account takes it');
        self::assertSame('tick', (new TickSettings())->storedState()->lastSource(), 'no tick was due: the capture alone sent the response early');
        self::assertSame(200, $this->request($browser, 'my-account'), 'the next request opens the account page: the shopper is logged in');
    }

    /**
     * Stores a tick state whose last tick is $age seconds old and whose jobs all ran just now: a
     * tick of the web server finds no job due but the migration, which has nothing to do.
     */
    private function lastTickWas(int $age): void
    {
        $now = time();
        $state = TickState::fromJson(null)->withTick('tick', $now - $age);
        foreach (Services::fromProviders(Services::PROVIDERS)->listeners(SchedulerProvider::JOBS) as $job) {
            $state = $state->withJobRun($job([])->name(), $now);
        }
        (new TickSettings())->saveState($state);
    }

    /**
     * Whether the web server ran a tick from page traffic. Waits for that tick's end (it holds its
     * lock until then), so that its last write cannot land in the state a later test stores.
     */
    private function trafficTickRan(): bool
    {
        $lock = new Lock();
        for ($i = 0; $i < 50; ++$i) {
            if ((new TickSettings())->storedState()->lastSource() === Tick::SOURCE_TRAFFIC && $lock->acquire()) {
                $lock->release();

                return true;
            }
            usleep(100000);
        }

        return false;
    }

    /**
     * The status that the web server's flush gave the queue row of $email, or `pending` when no
     * flush took it within 5 seconds.
     */
    private function flushedStatus(string $email): string
    {
        for ($i = 0; $i < 50; ++$i) {
            $status = (string) self::db()->getValue('SELECT `status` FROM `' . _DB_PREFIX_ . EventQueue::TABLE . '` WHERE `entity_id` = \'' . pSQL($email) . '\'', false);
            if ($status !== '' && $status !== EventQueue::STATUS_PENDING && $status !== EventQueue::STATUS_PROCESSING) {
                return $status;
            }
            usleep(100000);
        }

        return EventQueue::STATUS_PENDING;
    }

    /**
     * Submits the storefront's registration form for an invented shopper.
     *
     * @param resource|\CurlHandle $browser
     *
     * @return int the HTTP status
     */
    private function register($browser, string $email): int
    {
        $status = $this->request($browser, 'registration', [
            'id_gender' => '1',
            'firstname' => 'Testfirst',
            'lastname' => 'Testlast',
            'email' => $email,
            'password' => self::PASSWORD,
            'birthday' => '',
            'psgdpr' => '1',
            'customer_privacy' => '1',
            'submitCreate' => '1',
        ]);
        $id = (int) self::db()->getValue('SELECT `id_customer` FROM `' . _DB_PREFIX_ . 'customer` WHERE `email` = \'' . pSQL($email) . '\'', false);
        if ($id > 0) {
            $this->addedCustomers[] = $id;
        }

        return $status;
    }

    /**
     * A browser without cookies. Its requests go to the web server in this container, whatever
     * host and port the shop's URLs name.
     *
     * @return resource|\CurlHandle
     */
    private function browser()
    {
        $browser = curl_init();
        curl_setopt_array($browser, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_COOKIEFILE => '',
            CURLOPT_CONNECT_TO => ['::127.0.0.1:80'],
            CURLOPT_TIMEOUT => 30,
        ]);

        return $browser;
    }

    /**
     * Opens the storefront page $page, or posts $form to it, without following a redirect.
     *
     * @param resource|\CurlHandle $browser
     * @param array<string, string>|null $form
     *
     * @return int the HTTP status
     */
    private function request($browser, string $page, ?array $form = null): int
    {
        curl_setopt($browser, CURLOPT_URL, \Context::getContext()->link->getPageLink($page));
        if ($form === null) {
            curl_setopt($browser, CURLOPT_HTTPGET, true);
        } else {
            curl_setopt($browser, CURLOPT_POSTFIELDS, http_build_query($form));
        }
        self::assertIsString(curl_exec($browser), curl_error($browser));

        return (int) curl_getinfo($browser, CURLINFO_RESPONSE_CODE);
    }
}
