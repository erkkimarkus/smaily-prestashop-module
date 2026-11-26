<?php
declare(strict_types=1);

namespace Tests\Integration\Admin;

use PrestaShop\Module\SmailyForPrestaShop\Admin\Api\AbstractJsonAction;
use PrestaShop\Module\SmailyForPrestaShop\Admin\Api\Settings\ChangeLayout;
use PrestaShop\Module\SmailyForPrestaShop\Admin\Api\Setup\SaveStep;
use PrestaShop\Module\SmailyForPrestaShop\Automation\MappingRepository;
use PrestaShop\Module\SmailyForPrestaShop\Config\AutomationSettings;
use PrestaShop\Module\SmailyForPrestaShop\Config\ContactsSettings;
use PrestaShop\Module\SmailyForPrestaShop\Config\EngineSettings;
use PrestaShop\Module\SmailyForPrestaShop\Config\ReportSettings;
use PrestaShop\Module\SmailyForPrestaShop\Contacts\ContactSyncMode;
use PrestaShop\Module\SmailyForPrestaShop\Engine\Connections;
use PrestaShop\Module\SmailyForPrestaShop\Scope\StoreLayout;
use PrestaShop\Module\SmailyForPrestaShop\Storefront\ActiveConnection;
use Symfony\Component\HttpFoundation\JsonResponse;
use Tests\Integration\MultistoreShop;
use Tests\Integration\Report\ReportTestCase;

require_once __DIR__ . '/../Report/ReportTestCase.php';
require_once __DIR__ . '/../MultistoreShop.php';

/**
 * The Initial setup's first answer to "How are your shops run?" (ARCHITECTURE 8.9, "The first
 * answer"). A store that was set up while it had one shop holds its values, its Campaign
 * Intelligence connection and its cookie-banner confirmation for all shops. Once it has three
 * shops, "Separate stores" in the Initial setup leaves them exactly as the same answer on Settings
 * does; "One store in several versions" is the layout they are in and moves nothing.
 *
 * Every request goes to the recording transport of ReportTestCase, which lets none leave.
 */
final class SetupLayoutAnswerTest extends ReportTestCase
{
    use MultistoreShop;

    private const SETUP_ROUTE = ['_legacy_controller' => 'AdminSmailyConnectSetup', '_route' => 'smailyforprestashop_api_setup_save'];

    private const SETTINGS_ROUTE = ['_legacy_controller' => 'AdminSmailyConnectSettings', '_route' => 'smailyforprestashop_api_settings_layout'];

    private const SETUP_LAYOUT_ROUTE = ['_legacy_controller' => 'AdminSmailyConnectSetup', '_route' => 'smailyforprestashop_api_setup_layout'];

    private const TABLES = ['smaily_connect_account', 'smaily_connect_automation_mapping', 'smaily_connect_connection'];

    private bool $multistore = false;

    private int $shopB = 0;

    private int $connectionId = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpBeforeASecondShop();
        $this->openMultistore();
        $this->multistore = true;
        $group = $this->addShopGroup(false);
        $this->shopB = $this->addShop($group);
        $this->addShop($group);
        self::assertFalse((new StoreLayout())->layoutChosen(), 'the store has not said how its shops are run');
    }

    protected function tearDown(): void
    {
        if ($this->multistore) {
            $this->closeMultistore();
        }
        parent::tearDown();
    }

    public function testSeparateStoresInTheInitialSetupLeavesWhatTheSameAnswerOnSettingsLeaves(): void
    {
        $before = $this->rows();
        \Shop::setContext(\Shop::CONTEXT_SHOP, $this->shopB);

        self::assertSame(200, $this->post(new SaveStep(), ['step' => 'shape', 'data' => ['shape' => 'separate']], self::SETUP_ROUTE)->getStatusCode());
        $setup = $this->comparable();

        $this->restore($before);
        self::assertSame(200, $this->post(new ChangeLayout(), ['shape' => 'separate'], self::SETTINGS_ROUTE)->getStatusCode());
        self::assertSame($setup, $this->comparable(), 'the Initial setup and Settings leave the same rows');

        // What the change is (Admin\Settings\LayoutChange, Engine\LayoutConnections).
        self::assertTrue((new StoreLayout())->separateStores());
        $connections = $this->services->get(Connections::class);
        self::assertNull($connections->find(0));
        self::assertSame($this->connectionId, $connections->find($this->shopB)->id(), 'the selected shop keeps the connection of all shops');
        self::assertSame([[$this->shopB, '1']], $this->configuration(EngineSettings::CONSENT_CONFIRMED), 'the confirmation stays with that shop alone');
        self::assertCount(3, $this->configuration(ContactsSettings::MODE), 'each shop holds the Contacts settings it read');
        self::assertSame([], $this->rowsOf('smaily_connect_account', 0), 'no account is left for all shops');
        self::assertSame(['en:42', 'et:43'], $this->workflows($this->shopB), 'each shop holds the workflows of its languages');
        self::assertSame([], $this->transport->requests, 'no connection was ended and nothing left the shop');
    }

    public function testOneStoreInTheInitialSetupMovesNothing(): void
    {
        $before = $this->comparable();

        self::assertSame(200, $this->post(new SaveStep(), ['step' => 'shape', 'data' => ['shape' => 'one']], self::SETUP_ROUTE)->getStatusCode());

        $after = $this->comparable();
        self::assertSame([[null, '0']], $this->configuration(StoreLayout::SEPARATE_STORES), 'the answer is stored');
        $after['configuration'] = array_values(array_filter($after['configuration'], static fn (array $row): bool => $row['name'] !== StoreLayout::SEPARATE_STORES));
        self::assertSame($before, $after, 'the values, the connection and the confirmation stay for all shops');
        self::assertSame([], $this->transport->requests);
    }

    public function testAnAnswerTheStoredLayoutAlreadyHasMovesNothing(): void
    {
        self::assertSame(200, $this->post(new SaveStep(), ['step' => 'shape', 'data' => ['shape' => 'separate']], self::SETUP_ROUTE)->getStatusCode());
        $before = $this->comparable();

        self::assertSame(200, $this->post(new SaveStep(), ['step' => 'shape', 'data' => ['shape' => 'separate']], self::SETUP_ROUTE)->getStatusCode());

        self::assertSame($before, $this->comparable());
        self::assertSame([], $this->transport->requests);
    }

    /**
     * The store-layout bar of the Initial setup has Magento's Change: it runs the change of
     * Settings, through the setup's own route, in both directions.
     */
    public function testTheBarsChangeInTheInitialSetupIsTheChangeOfSettings(): void
    {
        self::assertSame(200, $this->post(new SaveStep(), ['step' => 'shape', 'data' => ['shape' => 'one']], self::SETUP_ROUTE)->getStatusCode());
        \Shop::setContext(\Shop::CONTEXT_SHOP, $this->shopB);

        foreach (['separate' => true, 'one' => false] as $shape => $separate) {
            $start = $this->rows();
            self::assertSame(200, $this->post(new ChangeLayout(), ['shape' => $shape], self::SETUP_LAYOUT_ROUTE)->getStatusCode());
            $setup = $this->comparable();
            self::assertSame($separate, (new StoreLayout())->separateStores());

            $this->restore($start);
            self::assertSame(200, $this->post(new ChangeLayout(), ['shape' => $shape], self::SETTINGS_ROUTE)->getStatusCode());
            self::assertSame($setup, $this->comparable(), 'the Initial setup and Settings leave the same rows: ' . $shape);
        }
        self::assertSame($this->connectionId, $this->services->get(Connections::class)->find(0)->id(), 'the connection came back to all shops');
        self::assertSame([], $this->transport->requests, 'no connection was ended and nothing left the shop');
    }

    /**
     * A shop listed under a step with its own values keeps them, unless the step's values are
     * picked for it (`use_shared`, Magento's `use_store_wide`).
     */
    public function testAShopListedUnderAStepTakesTheStepsValuesOnlyWhenPicked(): void
    {
        self::assertSame(200, $this->post(new SaveStep(), ['step' => 'shape', 'data' => ['shape' => 'one']], self::SETUP_ROUTE)->getStatusCode());
        (new ContactsSettings())->setMode(new ContactSyncMode('all_customers', true), $this->shopB);
        $contacts = ['mode' => 'subscribers', 'fields' => ['first_name'], 'guests' => false, 'enabled' => true];

        self::assertSame(200, $this->post(new SaveStep(), ['step' => 'contacts', 'data' => $contacts + ['use_shared' => []]], self::SETUP_ROUTE)->getStatusCode());
        self::assertSame([[null, 'subscribers'], [$this->shopB, 'all_customers']], $this->configuration(ContactsSettings::MODE), 'shop B keeps its own mode');

        self::assertSame(200, $this->post(new SaveStep(), ['step' => 'contacts', 'data' => $contacts + ['use_shared' => [$this->shopB]]], self::SETUP_ROUTE)->getStatusCode());
        self::assertSame([[null, 'subscribers']], $this->configuration(ContactsSettings::MODE), 'shop B reads the step\'s values');
    }

    public function testAStoreWithOneShopIsNotAsked(): void
    {
        $this->closeMultistore();
        $this->multistore = false;
        $before = $this->comparable();

        $response = $this->post(new SaveStep(), ['step' => 'shape', 'data' => ['shape' => 'separate']], self::SETUP_ROUTE);

        self::assertSame(422, $response->getStatusCode());
        self::assertSame($before, $this->comparable(), 'nothing is stored or moved');
    }

    /**
     * Set up while the store had one shop, in two languages: the Smaily account, one workflow per
     * language, the Contacts settings and the welcome automation, a Campaign Intelligence
     * connection and the cookie-banner confirmation, all for all shops.
     */
    private function setUpBeforeASecondShop(): void
    {
        $layout = new StoreLayout();
        $layout->setLanguageMode(StoreLayout::MODE_WORKFLOW_PER_LANGUAGE, 0);
        $this->storeAccount(0, 'demo');
        $contacts = new ContactsSettings();
        $contacts->setMode(new ContactSyncMode('subscribers', false), 0);
        $contacts->setFields(['first_name', 'last_name'], 0);
        $contacts->setEnabled(true, 0);
        $contacts->confirm(0);
        (new AutomationSettings())->setEnabled('welcome', true, 0);
        $mappings = $this->services->get(MappingRepository::class);
        $mappings->upsert(0, 'welcome', 'en', MappingRepository::ACCOUNT_DEFAULT, 42, false, true);
        $mappings->upsert(0, 'welcome', 'et', MappingRepository::ACCOUNT_DEFAULT, 43);
        $this->connectionId = $this->storeConnection(0)->id();
        $this->services->get(EngineSettings::class)->setConsentConfirmed(true, 0);
        \Configuration::resetStaticCache();
        \Configuration::loadConfiguration();
    }

    /**
     * @param array<string, mixed> $body
     * @param array<string, string> $route
     */
    private function post(AbstractJsonAction $action, array $body, array $route): JsonResponse
    {
        $response = $this->adminRequest($action, $body, $route);
        \Configuration::resetStaticCache();
        \Configuration::loadConfiguration();
        ActiveConnection::reset();

        return $response;
    }

    /**
     * The module's configuration rows (without the installation report's, which a save schedules)
     * and the rows of its account, workflow and connection tables, as stored.
     *
     * @return array<string, list<array<string, mixed>>>
     */
    private function rows(): array
    {
        $rows = ['configuration' => (array) self::db()->executeS(
            'SELECT `id_shop_group`, `id_shop`, `name`, `value` FROM `' . _DB_PREFIX_ . 'configuration`'
            . ' WHERE `name` LIKE \'SMAILY\\_CONNECT\\_%\' AND `name` NOT IN (\'' . ReportSettings::STATE . '\', \'' . ReportSettings::HEALTH . '\')'
            . ' ORDER BY `name`, `id_shop`',
            true,
            false
        )];
        foreach (self::TABLES as $table) {
            $rows[$table] = (array) self::db()->executeS('SELECT * FROM `' . _DB_PREFIX_ . $table . '` ORDER BY `id`', true, false);
        }

        return $rows;
    }

    /**
     * rows() without what a second run cannot repeat: the times, and the ids of the rows the
     * change inserts. The connection keeps its id.
     *
     * @return array<string, list<array<string, mixed>>>
     */
    private function comparable(): array
    {
        $rows = $this->rows();
        foreach (self::TABLES as $table) {
            $rows[$table] = array_map(static function (array $row) use ($table): array {
                unset($row['date_add'], $row['date_upd']);
                if ($table !== 'smaily_connect_connection') {
                    unset($row['id']);
                }

                return $row;
            }, $rows[$table]);
            usort($rows[$table], static fn (array $a, array $b): int => strcmp(implode('|', $a), implode('|', $b)));
        }

        return $rows;
    }

    /**
     * Puts the rows of rows() back as they were, the encrypted values byte for byte.
     *
     * @param array<string, list<array<string, mixed>>> $rows
     */
    private function restore(array $rows): void
    {
        self::db()->execute('DELETE FROM `' . _DB_PREFIX_ . 'configuration` WHERE `name` LIKE \'SMAILY\\_CONNECT\\_%\'');
        foreach ($rows['configuration'] as $row) {
            self::db()->execute(
                'INSERT INTO `' . _DB_PREFIX_ . 'configuration` (`id_shop_group`, `id_shop`, `name`, `value`, `date_add`, `date_upd`) VALUES ('
                . ($row['id_shop_group'] === null ? 'NULL' : (int) $row['id_shop_group']) . ', '
                . ($row['id_shop'] === null ? 'NULL' : (int) $row['id_shop']) . ', \'' . pSQL($row['name']) . '\', '
                . ($row['value'] === null ? 'NULL' : '\'' . pSQL($row['value'], true) . '\'') . ', NOW(), NOW())'
            );
        }
        foreach (self::TABLES as $table) {
            self::db()->execute('DELETE FROM `' . _DB_PREFIX_ . $table . '`');
            foreach ($rows[$table] as $row) {
                self::db()->execute('INSERT INTO `' . _DB_PREFIX_ . $table . '` (`' . implode('`, `', array_keys($row)) . '`) VALUES ('
                    . implode(', ', array_map(static fn ($value) => $value === null ? 'NULL' : '\'' . pSQL((string) $value, true) . '\'', $row)) . ')');
            }
        }
        \Configuration::resetStaticCache();
        \Configuration::loadConfiguration();
        ActiveConnection::reset();
    }

    /**
     * @return list<array{0: int|null, 1: string|null}> id_shop (null: all shops) and value of each row of the key
     */
    private function configuration(string $key): array
    {
        return array_map(
            static fn (array $row): array => [$row['id_shop'] === null ? null : (int) $row['id_shop'], $row['value']],
            (array) self::db()->executeS('SELECT `id_shop`, `value` FROM `' . _DB_PREFIX_ . 'configuration` WHERE `name` = \'' . pSQL($key) . '\' ORDER BY `id_shop`', true, false)
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function rowsOf(string $table, int $idShop): array
    {
        return (array) self::db()->executeS('SELECT * FROM `' . _DB_PREFIX_ . $table . '` WHERE `id_shop` = ' . $idShop, true, false);
    }

    /**
     * @return list<string> "language:workflow" of the shop's welcome rows, sorted
     */
    private function workflows(int $idShop): array
    {
        $rows = array_map(
            static fn (array $row): string => $row['language'] . ':' . $row['workflow_id'],
            $this->services->get(MappingRepository::class)->rows($idShop)
        );
        sort($rows);

        return $rows;
    }
}
