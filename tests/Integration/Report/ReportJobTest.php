<?php
declare(strict_types=1);

namespace Tests\Integration\Report;

use PrestaShop\Module\SmailyForPrestaShop\Config\ContactsSettings;
use PrestaShop\Module\SmailyForPrestaShop\Config\MigrationNotices;
use PrestaShop\Module\SmailyForPrestaShop\Config\ReportSettings;
use PrestaShop\Module\SmailyForPrestaShop\Http\NetworkException;
use PrestaShop\Module\SmailyForPrestaShop\Http\Response;
use PrestaShop\Module\SmailyForPrestaShop\Http\Transport;
use PrestaShop\Module\SmailyForPrestaShop\Report\InstallationReport;
use PrestaShop\Module\SmailyForPrestaShop\Runtime\Provider\UpdateProvider;
use PrestaShop\Module\SmailyForPrestaShop\Scope\StoreLayout;
use PrestaShop\Module\SmailyForPrestaShop\Smaily\Client;
use PrestaShop\Module\SmailyForPrestaShop\Smaily\Exception\SmailyClientException;
use Tests\Integration\MultistoreShop;

require_once __DIR__ . '/ReportTestCase.php';
require_once __DIR__ . '/../MultistoreShop.php';

/**
 * The tick's job `installation_report` in the shop, against a receiver that answers in memory:
 * what goes five minutes after a save and once a day, what a store without an account sends, how a
 * shop with its own account shows, and what a receiver that does not answer leaves.
 */
final class ReportJobTest extends ReportTestCase
{
    use MultistoreShop;

    private bool $multistore = false;

    protected function tearDown(): void
    {
        if ($this->multistore) {
            $this->closeMultistore();
        }
        parent::tearDown();
    }

    public function testSeveralSavesGiveOneReportFiveMinutesAfterTheFirst(): void
    {
        $this->storeAccount(0, 'example');
        $report = $this->services->get(InstallationReport::class);
        $before = time();

        $report->setupSaved();
        $dueAt = self::state()['setup_saved_at'];
        $report->setupSaved();
        $this->tick();

        self::assertGreaterThanOrEqual($before + 300, $dueAt);
        self::assertLessThanOrEqual(time() + 300, $dueAt);
        self::assertSame($dueAt, self::state()['setup_saved_at'], 'a second save moves nothing');
        self::assertSame([], $this->transport->reports(), 'nothing goes before the five minutes are over');

        // The five minutes pass: the stored moment is moved instead of the clock.
        self::setState(['setup_saved_at' => time() - 1] + self::state());
        $this->tick();
        $this->tick();

        self::assertCount(1, $this->transport->reports(), 'one report, however often the tick runs');
        $body = $this->transport->report();
        self::assertFollowsTheContract($body);
        self::assertSame(['connect_prestashop', UpdateProvider::moduleVersion(), [['name' => 'prestashop', 'version' => _PS_VERSION_]], PHP_VERSION], [$body['product'], $body['product_version'], $body['platforms'], $body['php_version']]);
        self::assertSame(['snapshot', 'setup_saved', 'example'], [$body['event'], $body['trigger'], $body['smaily_subdomain']]);
        self::assertSame($body['installation_url'], $body['connections'][0]['store_url']);
        self::assertSame((string) $this->defaultShopId(), $body['connections'][0]['website']);
        self::assertNull(self::state()['setup_saved_at']);

        $request = $this->transport->reports()[0];
        self::assertSame('Basic ' . base64_encode('api-user-example:' . self::PASSWORD), $request['options']['headers']['Authorization'], 'the main account authenticates in the header');
        self::assertSame(0, $request['options']['max_redirects']);
        self::assertSame(5, $request['options']['max_duration']);
    }

    public function testTheDailyReportGoesWhenADayHasPassedAlsoWhenNothingChanged(): void
    {
        $this->storeAccount(0, 'example');

        $this->tick();
        $first = self::state()['daily_at'];
        self::assertGreaterThanOrEqual(time(), $first, 'the first daily report waits a random part of a day');
        self::assertLessThanOrEqual(time() + 86400, $first);
        self::assertSame([], $this->transport->reports());

        // A day passes: the stored moment is moved instead of the clock.
        self::setState(['daily_at' => time() - 1] + self::state());
        $this->tick();
        $this->tick();

        self::assertCount(1, $this->transport->reports());
        self::assertSame('daily', $this->transport->report()['trigger']);
        self::assertFollowsTheContract($this->transport->report());
        self::assertEqualsWithDelta(time() + 86400, self::state()['daily_at'], 5, 'the next one is due 24 hours after this attempt');

        self::setState(['daily_at' => time() - 1] + self::state());
        $this->tick();
        self::assertCount(2, $this->transport->reports(), 'the next day\'s report goes although nothing changed');
    }

    public function testAStoreWithoutAConnectedAccountSendsNothing(): void
    {
        $this->services->get(InstallationReport::class)->setupSaved();
        $this->tick();
        self::assertNull(self::stored(ReportSettings::STATE), 'nothing is scheduled');

        // An account without a password that decrypts is not connected either.
        self::db()->insert('smaily_connect_account', ['id_shop' => 0, 'language' => '', 'subdomain' => 'example', 'username' => 'api-user-example', 'password_enc' => 'not-a-ciphertext', 'date_add' => date('Y-m-d H:i:s'), 'date_upd' => date('Y-m-d H:i:s')]);
        self::setState(['setup_saved_at' => time() - 1, 'daily_at' => time() - 1, 'retry' => null]);
        $this->tick();

        self::assertSame([], $this->transport->reports());
        self::assertSame([], self::state(), 'what was scheduled is forgotten');
    }

    public function testAShopWithItsOwnAccountIsItsOwnConnectionWithItsShopAndItsAddress(): void
    {
        $this->openMultistore();
        $this->multistore = true;
        $default = $this->defaultShopId();
        $shopB = $this->addShop((int) \Shop::getGroupFromShop($default));
        $shopC = $this->addShop((int) \Shop::getGroupFromShop($default));
        \Configuration::updateGlobalValue(StoreLayout::SEPARATE_STORES, '0');
        \Configuration::updateGlobalValue(ContactsSettings::ENABLED, '1');
        \Configuration::updateGlobalValue(ContactsSettings::CONFIRMED, '1');
        \Configuration::updateValue(ContactsSettings::ENABLED, '0', false, (int) \Shop::getGroupFromShop($shopB), $shopB);
        $this->storeAccount(0, 'example');
        $this->storeAccount($shopB, 'example-second');
        self::setState(['setup_saved_at' => null, 'daily_at' => time() - 1, 'retry' => null]);

        $this->tick();

        $body = $this->transport->report();
        self::assertFollowsTheContract($body);
        self::assertSame('example', $body['smaily_subdomain'], 'the account set up for all shops authenticates');
        self::assertSame('Basic ' . base64_encode('api-user-example:' . self::PASSWORD), $this->transport->reports()[0]['options']['headers']['Authorization']);
        $scheme = parse_url($body['installation_url'], PHP_URL_SCHEME);
        self::assertSame([
            [(string) $default, $body['installation_url'], 'example', ['subscriber_sync']],
            [(string) $shopB, $scheme . '://shop' . $shopB . '.example.invalid', 'example-second', []],
            [(string) $shopC, $scheme . '://shop' . $shopC . '.example.invalid', 'example', ['subscriber_sync']],
        ], array_map(
            static fn (array $connection): array => [$connection['website'], $connection['store_url'], $connection['smaily_subdomain'], $connection['features']],
            $body['connections']
        ));
    }

    public function testAReceiverThatDoesNotAnswerIsAskedFourTimesAndLeavesOneLineAndNoNotice(): void
    {
        $this->storeAccount(0, 'example');
        $this->transport->reportAnswers = [new NetworkException('Idle timeout reached for "https://subscription.smaily.com/v1/reports".')];
        self::setState(['setup_saved_at' => null, 'daily_at' => time() - 1, 'retry' => null]);
        $lastLog = self::lastLogId();

        $this->tick();
        $retry = self::state()['retry'];
        self::assertSame(['daily', 1], [$retry['trigger'], $retry['attempt']]);
        self::assertGreaterThanOrEqual(time() + 55, $retry['at'], 'the first retry waits 60 to 90 seconds');
        self::assertLessThanOrEqual(time() + 90, $retry['at']);
        $this->tick();
        self::assertCount(1, $this->transport->reports(), 'the retry waits across ticks');

        foreach ([2, 3] as $attempt) {
            // The wait passes: the stored moment is moved instead of the clock.
            self::setState(['retry' => ['at' => time() - 1] + self::state()['retry']] + self::state());
            $this->tick();
            self::assertSame($attempt, self::state()['retry']['attempt']);
        }
        self::assertSame([], self::logLines($lastLog), 'a try that is repeated leaves no line');

        self::setState(['retry' => ['at' => time() - 1] + self::state()['retry']] + self::state());
        $this->tick();
        $this->tick();

        self::assertCount(4, $this->transport->reports(), 'the first try and three more');
        self::assertNull(self::state()['retry']);
        self::assertSame(['Smaily Connect: the installation report (daily) was not taken in: http=0 error=network_error'], self::logLines($lastLog));
        self::assertNull(self::stored(MigrationNotices::KEY), 'no notice is stored for the admin');
    }

    public function testARefusedReportLeavesOneLineWithTheCodeOnlyAndIsNotRepeated(): void
    {
        $this->storeAccount(0, 'example');
        $this->transport->reportAnswers = [new Response(401, '{"error": "invalid_smaily_credentials", "message": "Smaily refused the credentials of api-user-example."}')];
        self::setState(['setup_saved_at' => time() - 1, 'daily_at' => time() + 5000, 'retry' => null]);
        $lastLog = self::lastLogId();

        $this->tick();
        $this->tick();

        self::assertCount(1, $this->transport->reports());
        self::assertSame(['Smaily Connect: the installation report (setup_saved) was not taken in: http=401 error=invalid_smaily_credentials'], self::logLines($lastLog));
    }

    public function testTheReportCarriesHowTheAccountsLastRequestsWent(): void
    {
        $this->storeAccount(0, 'example');
        $client = new Client($this->services->get(Transport::class), 'example', 'api-user-example', self::PASSWORD);

        $client->post(Client::ENDPOINT_CONTACT, [['email' => 'shopper@example.test']]);
        self::setState(['setup_saved_at' => null, 'daily_at' => time() - 1, 'retry' => null]);
        $this->tick();

        $smaily = $this->transport->report()['connections'][0]['smaily'];
        self::assertSame(['ok', null], [$smaily['status'], $smaily['last_error_code']]);
        self::assertEqualsWithDelta(time(), strtotime($smaily['last_sync_at']), 5);

        // The account's next request is refused: a transport that answers 401 for it.
        try {
            (new Client(new class() implements Transport {
                public function request(string $method, string $url, array $options = []): Response
                {
                    return new Response(401, '{"message": "Wrong password for shopper@example.test"}');
                }
            }, 'example', 'api-user-example', self::PASSWORD))->get(Client::ENDPOINT_WORKFLOWS);
            self::fail('a 401 refuses the request');
        } catch (SmailyClientException $refusal) {
        }
        self::setState(['daily_at' => time() - 1] + self::state());
        $this->tick();

        $body = $this->transport->report(1);
        self::assertFollowsTheContract($body);
        self::assertSame(['failing', 'credentials_rejected'], [$body['connections'][0]['smaily']['status'], $body['connections'][0]['smaily']['last_error_code']]);
        self::assertSame($smaily['last_sync_at'], $body['connections'][0]['smaily']['last_sync_at'], 'the last sync stays');
        self::assertStringNotContainsString('shopper', (string) self::stored(ReportSettings::HEALTH));
    }
}
