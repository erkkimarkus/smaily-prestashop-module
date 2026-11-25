<?php
declare(strict_types=1);

namespace Tests\Integration\Report;

use PrestaShop\Module\SmailyForPrestaShop\Admin\Api\Settings\SaveTab;
use PrestaShop\Module\SmailyForPrestaShop\Admin\Api\Setup\SaveStep;
use PrestaShop\Module\SmailyForPrestaShop\Config\ReportSettings;
use PrestaShop\Module\SmailyForPrestaShop\Engine\Connections;
use PrestaShop\Module\SmailyForPrestaShop\Engine\Disconnector;
use PrestaShop\Module\SmailyForPrestaShop\Engine\Setup;
use PrestaShop\Module\SmailyForPrestaShop\Http\NetworkException;
use PrestaShop\Module\SmailyForPrestaShop\Http\Response;
use PrestaShop\Module\SmailyForPrestaShop\Install\Schema;
use PrestaShop\Module\SmailyForPrestaShop\Install\Uninstaller;
use PrestaShop\Module\SmailyForPrestaShop\Migration\LegacyOverride;
use PrestaShop\Module\SmailyForPrestaShop\Scope\StoreLayout;
use Tests\Integration\MultistoreShop;

require_once __DIR__ . '/ReportTestCase.php';
require_once __DIR__ . '/../MultistoreShop.php';

/**
 * The installation report's events and the saves that schedule a snapshot, in the shop: an
 * uninstall says `deactivated` before the engine is told to disconnect and completes whatever the
 * receiver answers; a removed Campaign Intelligence connection or Smaily account says
 * `disconnected`; a save of the setup or the Settings schedules one snapshot.
 */
final class ReportEventsTest extends ReportTestCase
{
    use MultistoreShop;

    private const ENGINE_UNINSTALL = 'https://intelligence.smaily.com/api/v1/store/uninstall';

    private const RSS = ['tab' => 'rss', 'data' => ['category' => '', 'limit' => '7', 'sort_by' => 'date_upd', 'sort_order' => 'desc']];

    private const SETTINGS_ROUTE = ['_legacy_controller' => 'AdminSmailyConnectSettings', '_route' => 'smailyforprestashop_api_settings_save'];

    private const SETUP_ROUTE = ['_legacy_controller' => 'AdminSmailyConnectSetup', '_route' => 'smailyforprestashop_api_setup_save'];

    private bool $multistore = false;

    protected function tearDown(): void
    {
        if ($this->multistore) {
            $this->closeMultistore();
        }
        parent::tearDown();
    }

    /**
     * @return array<string, array{list<Response|\Throwable>, list<string>}>
     */
    public function receiverAnswers(): array
    {
        return [
            'the receiver takes the report' => [[], []],
            'the receiver fails' => [[new Response(503, '{"error": "unavailable", "message": "Try later."}', ['retry-after' => ['60']])], ['Smaily Connect: the installation report (deactivated) was not taken in: http=503 error=unavailable']],
            'the receiver does not answer' => [[new NetworkException('Idle timeout reached.')], ['Smaily Connect: the installation report (deactivated) was not taken in: http=0 error=network_error']],
        ];
    }

    /**
     * @dataProvider receiverAnswers
     *
     * @param list<Response|\Throwable> $answers
     * @param list<string> $log
     */
    public function testAnUninstallSaysDeactivatedBeforeTheEngineIsToldAndCompletesWhateverTheAnswer(array $answers, array $log): void
    {
        $this->storeAccount(0, 'example');
        $this->storeConnection(0);
        $this->transport->reportAnswers = $answers;
        $lastLog = self::lastLogId();

        self::assertTrue((new Uninstaller($this->services, new LegacyOverride(_PS_OVERRIDE_DIR_)))->uninstall());

        self::assertSame(
            ['https://subscription.smaily.com/v1/reports', self::ENGINE_UNINSTALL],
            array_column($this->transport->requests, 'url'),
            'one report, once, and then the engine'
        );
        $body = $this->transport->report();
        self::assertFollowsTheContract($body);
        self::assertSame('deactivated', $body['event']);
        self::assertArrayNotHasKey('trigger', $body);
        self::assertSame('2c9d7e41-5f3a-4b86-9d02-7a1e6c4f8b35', $body['connections'][0]['intelligence']['tenant_id'], 'the connections as they are');
        self::assertSame($log, self::logLines($lastLog));
        self::assertFalse(Schema::isCurrent(), 'the removal completed');
        self::assertNull(self::stored(ReportSettings::STATE));
        self::assertNull(self::stored(ReportSettings::HEALTH));
    }

    public function testAnUninstallOfAStoreWithoutAnAccountSendsNoReport(): void
    {
        $this->storeConnection(0);

        self::assertTrue((new Uninstaller($this->services, new LegacyOverride(_PS_OVERRIDE_DIR_)))->uninstall());

        self::assertSame([self::ENGINE_UNINSTALL], array_column($this->transport->requests, 'url'));
    }

    public function testARemovedEngineConnectionSaysDisconnectedBeforeTheEngineIsTold(): void
    {
        $this->storeAccount(0, 'example');
        $connection = $this->storeConnection(0);

        $this->services->get(Disconnector::class)->disconnect($connection);

        self::assertSame(['https://subscription.smaily.com/v1/reports', self::ENGINE_UNINSTALL], array_column($this->transport->requests, 'url'));
        $body = $this->transport->report();
        self::assertFollowsTheContract($body);
        self::assertSame('disconnected', $body['event']);
        self::assertCount(1, $body['connections'], 'the Smaily account stays connected');
        self::assertArrayNotHasKey('intelligence', $body['connections'][0]);
        self::assertNotContains('campaign_intelligence', $body['connections'][0]['features']);
        self::assertNull($this->services->get(Connections::class)->find(0));
    }

    public function testOfTwoShopsOnOneEngineStoreOnlyTheDisconnectedOneLosesIt(): void
    {
        $this->openMultistore();
        $this->multistore = true;
        $default = $this->defaultShopId();
        $shopB = $this->addShop((int) \Shop::getGroupFromShop($default));
        \Configuration::updateGlobalValue(StoreLayout::SEPARATE_STORES, '1');
        $this->storeAccount($default, 'example');
        $this->storeAccount($shopB, 'example');
        $first = $this->storeConnection($default);
        $this->storeConnection($shopB);

        $this->services->get(Disconnector::class)->disconnect($first);

        $connections = $this->transport->report()['connections'];
        self::assertFollowsTheContract($this->transport->report());
        self::assertSame([(string) $default, (string) $shopB], array_column($connections, 'website'));
        self::assertArrayNotHasKey('intelligence', $connections[0]);
        self::assertSame('2c9d7e41-5f3a-4b86-9d02-7a1e6c4f8b35', $connections[1]['intelligence']['tenant_id']);
    }

    public function testAConnectionToCampaignIntelligenceSchedulesASnapshot(): void
    {
        $this->storeAccount(0, 'example');

        $this->services->get(Setup::class)->connect(0, 'https://intelligence.smaily.com/setup/tok1');

        self::assertEqualsWithDelta(time() + 300, self::state()['setup_saved_at'], 5);
        self::assertSame([], $this->transport->reports(), 'nothing goes inside the request');
    }

    public function testASaveOfTheSettingsOrTheSetupSchedulesOneSnapshotAndARefusedSaveNone(): void
    {
        $this->storeAccount(0, 'example');

        $refused = $this->adminRequest(new SaveTab(), ['tab' => 'rss', 'data' => ['limit' => '0']], self::SETTINGS_ROUTE);
        self::assertSame(422, $refused->getStatusCode());
        self::assertNull(self::stored(ReportSettings::STATE), 'a save that did not go through schedules nothing');

        $saved = $this->adminRequest(new SaveTab(), self::RSS, self::SETTINGS_ROUTE);
        self::assertSame(200, $saved->getStatusCode());
        $dueAt = self::state()['setup_saved_at'];
        self::assertEqualsWithDelta(time() + 300, $dueAt, 5);

        $contacts = ['step' => 'contacts', 'data' => ['mode' => 'all_customers', 'fields' => ['first_name'], 'guests' => true, 'enabled' => true]];
        self::assertSame(200, $this->adminRequest(new SaveStep(), $contacts, self::SETUP_ROUTE)->getStatusCode());
        self::assertSame($dueAt, self::state()['setup_saved_at'], 'a second save moves nothing');
        self::assertSame([], $this->transport->reports(), 'nothing goes inside the request');
    }

    public function testASaveOfAStoreWithoutAnAccountSchedulesNothing(): void
    {
        self::assertSame(200, $this->adminRequest(new SaveTab(), self::RSS, self::SETTINGS_ROUTE)->getStatusCode());

        self::assertNull(self::stored(ReportSettings::STATE));
        self::assertSame([], $this->transport->requests);
    }

    public function testASaveThatReturnsAShopToTheSharedAccountSaysDisconnectedAtOnce(): void
    {
        $this->openMultistore();
        $this->multistore = true;
        $default = $this->defaultShopId();
        $shopB = $this->addShop((int) \Shop::getGroupFromShop($default));
        \Configuration::updateGlobalValue(StoreLayout::SEPARATE_STORES, '0');
        $this->storeAccount(0, 'example');
        $this->storeAccount($shopB, 'example-second');
        \Shop::setContext(\Shop::CONTEXT_SHOP, $shopB);

        $response = $this->adminRequest(new SaveTab(), ['tab' => 'connection', 'own' => false, 'data' => []], self::SETTINGS_ROUTE);

        self::assertSame(200, $response->getStatusCode());
        self::assertTrue(json_decode((string) $response->getContent(), true)['data']['returned']);
        self::assertCount(1, $this->transport->reports());
        $body = $this->transport->report();
        self::assertFollowsTheContract($body);
        self::assertSame('disconnected', $body['event']);
        self::assertSame(['example', 'example'], array_column($body['connections'], 'smaily_subdomain'), 'the connections that remain');
        self::assertSame('Basic ' . base64_encode('api-user-example:' . self::PASSWORD), $this->transport->reports()[0]['options']['headers']['Authorization']);
        self::assertEqualsWithDelta(time() + 300, self::state()['setup_saved_at'], 5, 'the save also schedules a snapshot');
    }
}
