<?php
declare(strict_types=1);

namespace Tests\Integration\Contacts;

use PrestaShop\Module\SmailyForPrestaShop\Config\ContactsSettings;
use PrestaShop\Module\SmailyForPrestaShop\Config\TickSettings;
use PrestaShop\Module\SmailyForPrestaShop\Contacts\Import\ContactImport;
use PrestaShop\Module\SmailyForPrestaShop\Contacts\Import\JobStore;
use PrestaShop\Module\SmailyForPrestaShop\Contacts\Reconciler;
use PrestaShop\Module\SmailyForPrestaShop\Http\Response;
use PrestaShop\Module\SmailyForPrestaShop\Http\Transport;
use PrestaShop\Module\SmailyForPrestaShop\Runtime\Provider\SchedulerProvider;
use PrestaShop\Module\SmailyForPrestaShop\Runtime\Services;
use PrestaShop\Module\SmailyForPrestaShop\Scheduler\Job;
use PrestaShop\Module\SmailyForPrestaShop\Scheduler\Tick;
use PrestaShop\Module\SmailyForPrestaShop\Scheduler\TickState;
use PrestaShop\Module\SmailyForPrestaShop\Smaily\Account;
use PrestaShop\Module\SmailyForPrestaShop\Smaily\AccountStore;
use PrestaShop\Module\SmailyForPrestaShop\Update\LatestRelease;
use Tests\Integration\Fixtures\SmailyMockServer;
use Tests\Integration\IntegrationTestCase;

require_once __DIR__ . '/../Fixtures/SmailyMockServer.php';
require_once __DIR__ . '/ContactRows.php';

/**
 * The runtime registers the reconcile, the daily refresh and the import chunk as background jobs
 * of the tick, and the admin reaches the import through it. With contact sync off, the tick runs
 * the three jobs and they do no work.
 */
final class ContactJobsTest extends IntegrationTestCase
{
    use ContactRows;

    private const ACCOUNT_TABLE = 'smaily_connect_account';

    private const KEYS = [ContactsSettings::ENABLED, ContactsSettings::CONFIRMED, ContactsSettings::MODE, Reconciler::CURSORS];

    /** @var string|false */
    private $savedState = false;

    /** @var list<array<string, mixed>> */
    private array $savedAccounts = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->savedState = \Configuration::getGlobalValue(TickSettings::TICK_STATE);
        \Configuration::deleteByName(TickSettings::TICK_STATE);
        $this->savedAccounts = self::db()->executeS('SELECT * FROM `' . _DB_PREFIX_ . self::ACCOUNT_TABLE . '`', true, false) ?: [];
        self::db()->delete(self::ACCOUNT_TABLE);
        $this->deleteJobs();
        foreach (self::KEYS as $key) {
            \Configuration::deleteByName($key);
        }
    }

    protected function tearDown(): void
    {
        if (defined('_PS_ROOT_DIR_')) {
            $this->removeContactRows();
            $this->deleteJobs();
            foreach (self::KEYS as $key) {
                \Configuration::deleteByName($key);
            }
            self::db()->delete(self::ACCOUNT_TABLE);
            foreach ($this->savedAccounts as $row) {
                self::db()->insert(self::ACCOUNT_TABLE, array_map(static fn ($value) => $value === null ? null : pSQL((string) $value), $row), true);
            }
            \Configuration::deleteByName(TickSettings::TICK_STATE);
            if ($this->savedState !== false) {
                \Configuration::updateGlobalValue(TickSettings::TICK_STATE, (string) $this->savedState);
            }
        }
        parent::tearDown();
    }

    /**
     * Acceptance 8: the intervals and budgets of ARCHITECTURE 4.1.
     */
    public function testTheJobsRunAtTheirIntervalsWithinTheirBudgets(): void
    {
        $jobs = [];
        foreach (Services::fromProviders(Services::PROVIDERS)->listeners(SchedulerProvider::JOBS) as $listener) {
            $job = $listener([]);
            $jobs[$job->name()] = $job;
        }

        foreach (['contacts_reconcile' => [900, 15], 'contacts_refresh' => [86400, 5], 'contacts_import' => [60, 15]] as $name => [$interval, $budget]) {
            self::assertArrayHasKey($name, $jobs);
            $job = $jobs[$name];
            $state = TickState::fromJson(null)->withJobRun($name, 1000000);
            self::assertSame($budget, $job->budget(), $name);
            self::assertFalse($job->isDue($state, 1000000 + $interval - Job::GRACE_SECONDS - 1), $name);
            self::assertTrue($job->isDue($state, 1000000 + $interval - Job::GRACE_SECONDS), $name);
            self::assertFalse($job->runsBeforeUpgrade(), $name);
        }
    }

    /**
     * Acceptance 8: with contact sync off, the tick runs the contact jobs, and they neither call
     * Smaily nor queue an import.
     */
    public function testWithContactSyncOffTheTickRunsTheContactJobsAndTheyDoNoWork(): void
    {
        $mock = SmailyMockServer::start();
        $mock->acceptCredentials('api-user', 'not-a-real-password');
        $services = Services::fromProviders(Services::PROVIDERS);
        $services->set(Transport::class, static fn (): Transport => $mock->transport());
        // The update check asks no real GitHub: its request finds no release.
        $services->set(LatestRelease::class, fn (): LatestRelease => new LatestRelease(new class() implements Transport {
            public function request(string $method, string $url, array $options = []): Response
            {
                return new Response(404, '');
            }
        }, '3.0.0'));
        self::assertTrue($services->get(AccountStore::class)->save(new Account(0, '', 'globalaccount', 'api-user', 'not-a-real-password')));
        $idShop = (int) \Configuration::get('PS_SHOP_DEFAULT');
        $this->addCustomer($idShop, 'shopper1@example.test', true, (int) \Configuration::get('PS_LANG_DEFAULT'));
        \Configuration::updateGlobalValue(ContactsSettings::ENABLED, '0');
        \Configuration::updateGlobalValue(ContactsSettings::CONFIRMED, '1');

        $report = $services->get(Tick::class)->run('tick', 50);

        self::assertSame([], array_values(array_diff(['contacts_reconcile', 'contacts_refresh', 'contacts_import'], $report->jobs())));
        self::assertSame([], $mock->requests());
        self::assertSame(0, (int) self::db()->getValue('SELECT COUNT(*) FROM `' . _DB_PREFIX_ . JobStore::TABLE . '` WHERE `type` LIKE \'contacts_%\'', false));
    }

    public function testTheAdminReachesTheImportThroughTheRuntime(): void
    {
        self::assertInstanceOf(ContactImport::class, Services::fromProviders(Services::PROVIDERS)->get(ContactImport::class));
    }

    private function deleteJobs(): void
    {
        self::db()->delete(JobStore::TABLE, '`type` IN (\'' . ContactImport::TYPE_IMPORT . '\', \'' . ContactImport::TYPE_REFRESH . '\')');
    }
}
