<?php
declare(strict_types=1);

namespace Tests\Integration\Automation\AbandonedCart;

use PrestaShop\Module\SmailyForPrestaShop\Automation\AbandonedCart\AbandonedCartSweeper;
use PrestaShop\Module\SmailyForPrestaShop\Automation\AbandonedCart\CartTracker;
use PrestaShop\Module\SmailyForPrestaShop\Automation\MappingRepository;
use PrestaShop\Module\SmailyForPrestaShop\Automation\Trigger;
use PrestaShop\Module\SmailyForPrestaShop\Config\AutomationSettings;
use PrestaShop\Module\SmailyForPrestaShop\Config\ContactsSettings;
use PrestaShop\Module\SmailyForPrestaShop\Contacts\ContactSyncMode;
use PrestaShop\Module\SmailyForPrestaShop\Http\Response;
use PrestaShop\Module\SmailyForPrestaShop\Http\Transport;
use PrestaShop\Module\SmailyForPrestaShop\Runtime\Provider\QueueProvider;
use PrestaShop\Module\SmailyForPrestaShop\Runtime\Services;
use PrestaShop\Module\SmailyForPrestaShop\Scheduler\Deadline;
use PrestaShop\Module\SmailyForPrestaShop\Smaily\Account;
use PrestaShop\Module\SmailyForPrestaShop\Smaily\AccountStore;
use PrestaShop\Module\SmailyForPrestaShop\Update\LatestRelease;
use Tests\Integration\Fixtures\SmailyMockServer;
use Tests\Integration\IntegrationTestCase;
use Tests\Integration\Migration\LegacyStore;

require_once __DIR__ . '/../../Migration/LegacyStore.php';
require_once __DIR__ . '/../../Fixtures/SmailyMockServer.php';
require_once __DIR__ . '/CartFixture.php';

/**
 * A store with the abandoned-cart automation on (workflow 102, delay 15 minutes) through one
 * Smaily account that the Smaily mock accepts, and the module's services with the mock's
 * transport. The update check asks no real GitHub.
 */
abstract class AbandonedCartTestCase extends IntegrationTestCase
{
    protected const ADDRESS = 'shopper1@example.test';

    protected const WORKFLOW = 102;

    protected SmailyMockServer $mock;

    protected Services $services;

    protected CartFixture $fixture;

    protected int $idShop;

    /** @var mixed */
    private $container;

    protected function setUp(): void
    {
        parent::setUp();
        LegacyStore::restore();
        $this->idShop = (int) \Configuration::get('PS_SHOP_DEFAULT');
        $this->fixture = new CartFixture();
        $this->mock = SmailyMockServer::start();
        $this->mock->acceptCredentials('api-user', 'not-a-real-password');
        // A cron or tick request builds PrestaShop's front container; PHPUnit does not.
        $this->container = \Context::getContext()->container;
        if (\Context::getContext()->container === null) {
            \Context::getContext()->container = \PrestaShop\PrestaShop\Adapter\ContainerBuilder::getContainer('front', false);
        }

        Services::instance()->get(AccountStore::class)->save(new Account(0, '', 'example-store', 'api-user', 'not-a-real-password'));
        (new MappingRepository())->upsert(0, Trigger::ABANDONED_CART, '', MappingRepository::ACCOUNT_DEFAULT, self::WORKFLOW);
        $settings = new AutomationSettings();
        $settings->setEnabled(Trigger::ABANDONED_CART, true, 0);
        $settings->setCartDelayMinutes(15, 0);
        $this->mode(ContactSyncMode::ALL_CUSTOMERS);
        $this->services = $this->services();
    }

    protected function tearDown(): void
    {
        if (defined('_PS_ROOT_DIR_')) {
            $this->fixture->remove();
            LegacyStore::restore();
            \Context::getContext()->container = $this->container;
        }
        parent::tearDown();
    }

    protected function mode(string $mode, bool $guests = false): void
    {
        \Configuration::updateGlobalValue(ContactsSettings::MODE, $mode);
        \Configuration::updateGlobalValue(ContactsSettings::GUESTS, $guests ? '1' : '0');
        LegacyStore::reloadConfiguration();
    }

    protected function services(): Services
    {
        $services = Services::fromProviders(Services::PROVIDERS);
        $transport = $this->mock->transport();
        $services->set(Transport::class, fn (): Transport => $transport);
        $services->set(LatestRelease::class, fn (): LatestRelease => new LatestRelease(new class() implements Transport {
            public function request(string $method, string $url, array $options = []): Response
            {
                return new Response(404, '');
            }
        }, '3.0.0'));

        return $services;
    }

    protected function sweep(): int
    {
        return $this->services->get(AbandonedCartSweeper::class)->sweep(new Deadline(microtime(true) + 10, static fn (): float => microtime(true)));
    }

    protected function flush(): int
    {
        return $this->services->get(QueueProvider::FLUSHER)->flush();
    }

    /**
     * @return array<string, string|null>|null
     */
    protected function cartRow(int $idCart): ?array
    {
        return (new CartTracker())->row($idCart);
    }

    /**
     * @return list<array<string, string|null>>
     */
    protected function events(): array
    {
        return self::db()->executeS('SELECT * FROM `' . _DB_PREFIX_ . 'smaily_connect_event_queue` WHERE `event_type` = \'automation.abandoned_cart\' ORDER BY `id`', true, false) ?: [];
    }

    /**
     * The reminders the mock received.
     *
     * @return list<array<string, mixed>>
     */
    protected function reminders(): array
    {
        return array_values(array_filter(
            $this->mock->requests(),
            static fn (array $request): bool => $request['method'] === 'POST' && $request['path'] === '/api/autoresponder.php'
        ));
    }
}
