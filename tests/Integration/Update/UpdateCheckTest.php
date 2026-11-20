<?php
declare(strict_types=1);

namespace Tests\Integration\Update;

use PrestaShop\Module\SmailyForPrestaShop\Config\TickSettings;
use PrestaShop\Module\SmailyForPrestaShop\Config\UpdateSettings;
use PrestaShop\Module\SmailyForPrestaShop\Http\Transport;
use PrestaShop\Module\SmailyForPrestaShop\Runtime\Provider\SchedulerProvider;
use PrestaShop\Module\SmailyForPrestaShop\Runtime\Provider\UpdateProvider;
use PrestaShop\Module\SmailyForPrestaShop\Runtime\Services;
use PrestaShop\Module\SmailyForPrestaShop\Scheduler\Job;
use PrestaShop\Module\SmailyForPrestaShop\Scheduler\Lock;
use PrestaShop\Module\SmailyForPrestaShop\Scheduler\Tick;
use PrestaShop\Module\SmailyForPrestaShop\Scheduler\TickReport;
use PrestaShop\Module\SmailyForPrestaShop\Update\LatestRelease;
use PrestaShop\Module\SmailyForPrestaShop\Update\UpdateNotice;
use Tests\Integration\Fixtures\GithubMockServer;
use Tests\Integration\IntegrationTestCase;

require_once __DIR__ . '/../Fixtures/GithubMockServer.php';

/**
 * The daily update check, run by the tick against a local mock of GitHub's latest-release endpoint:
 * what the admin's notice then says, how often the check runs, what a failed check leaves, and what
 * the request carries.
 */
final class UpdateCheckTest extends IntegrationTestCase
{
    private const DAY = 86400;

    private const NOW = 1763640000;

    private GithubMockServer $github;

    /** @var array<string, string|false> */
    private array $saved = [];

    protected function setUp(): void
    {
        parent::setUp();
        foreach ([UpdateSettings::KEY, TickSettings::TICK_STATE] as $key) {
            $value = \Configuration::getGlobalValue($key);
            $this->saved[$key] = $value === false ? false : (string) $value;
            \Configuration::deleteByName($key);
        }
        \Configuration::loadConfiguration();
        $this->github = GithubMockServer::start();
    }

    protected function tearDown(): void
    {
        if (defined('_PS_ROOT_DIR_')) {
            if (self::moduleId() === 0) {
                self::console('install');
            }
            foreach ($this->saved as $key => $value) {
                \Configuration::deleteByName($key);
                if ($value !== false) {
                    \Configuration::updateGlobalValue($key, $value);
                }
            }
        }
        parent::tearDown();
    }

    public function testANewerReleaseBecomesTheNoticeWithUrlsOfTheOfficialRepository(): void
    {
        $this->github->answer(200, self::release('v3.1.0'));

        $services = $this->tick(self::NOW);

        self::assertSame([
            'version' => '3.1.0',
            'download_url' => 'https://github.com/sendsmaily/smaily-prestashop-module/releases/download/v3.1.0/smailyforprestashop.zip',
            'release_url' => 'https://github.com/sendsmaily/smaily-prestashop-module/releases/tag/v3.1.0',
        ], $services->get(UpdateNotice::class)->current());
    }

    public function testTheInstalledOrAnOlderReleaseIsNoNotice(): void
    {
        foreach ([self::module()->version, '2.2.0'] as $index => $tag) {
            $this->github->answer(200, self::release($tag));

            $services = $this->tick(self::NOW + $index * self::DAY);

            self::assertSame($tag, json_decode((string) \Configuration::getGlobalValue(UpdateSettings::KEY), true)['version'], 'the check ran');
            self::assertNull($services->get(UpdateNotice::class)->current(), $tag);
        }
    }

    public function testADismissedVersionStaysClosedAndTheNextVersionShows(): void
    {
        $this->github->answer(200, self::release('v3.1.0'));
        $services = $this->tick(self::NOW);

        $services->get(UpdateNotice::class)->dismiss('3.1.0');

        self::assertNull($services->get(UpdateNotice::class)->current());
        self::assertNull($this->tick(self::NOW + self::DAY)->get(UpdateNotice::class)->current(), 'the same release a day later');

        $this->github->answer(200, self::release('v3.1.1'));
        $services = $this->tick(self::NOW + 2 * self::DAY);

        self::assertSame('3.1.1', $services->get(UpdateNotice::class)->current()['version'] ?? null);
    }

    public function testTheCheckRunsAtMostOnceADayHoweverOftenTheTickRuns(): void
    {
        $this->github->answer(200, self::release('v3.1.0'));

        foreach ([0, 60, 3600, self::DAY - 400] as $offset) {
            $this->tick(self::NOW + $offset);
        }
        self::assertCount(1, $this->github->requests());

        $report = $this->tickReport(self::NOW + self::DAY);

        self::assertSame([UpdateProvider::JOB], $report->jobs());
        self::assertCount(2, $this->github->requests());
    }

    /**
     * @dataProvider failedAnswers
     */
    public function testAFailedCheckChangesNothingRaisesNothingAndWaitsADay(int $status, string $body, string $error): void
    {
        $this->github->answer(200, self::release('v3.0.5'));
        $this->tick(self::NOW);
        $stored = \Configuration::getGlobalValue(UpdateSettings::KEY);
        $lastLog = self::lastLogId();
        $this->github->answer($status, $body);

        $report = $this->tickReport(self::NOW + self::DAY);
        $services = $this->tick(self::NOW + self::DAY + 3600);

        self::assertSame(TickReport::RAN, $report->status());
        self::assertSame($stored, \Configuration::getGlobalValue(UpdateSettings::KEY));
        self::assertSame('3.0.5', $services->get(UpdateNotice::class)->current()['version'] ?? null, 'the earlier notice stays');
        self::assertCount(2, $this->github->requests(), 'no second try within the day');
        self::assertSame([$error], self::logLinesSince($lastLog));
    }

    /**
     * @return iterable<array{int, string, string}>
     */
    public static function failedAnswers(): iterable
    {
        $unexpected = 'UnexpectedValueException';

        yield 'rate limit' => [403, '{"message":"API rate limit exceeded"}', $unexpected];
        yield 'no release' => [404, '{"message":"Not Found"}', $unexpected];
        yield 'server error' => [500, '', $unexpected];
        yield 'malformed JSON' => [200, '{"tag_name": "v3.2.0"', $unexpected];
        yield 'unexpected fields' => [200, '{"name":"3.2.0","draft":false,"prerelease":false}', $unexpected];
        yield 'a pre-release' => [200, self::release('v3.2.0', ['prerelease' => true]), $unexpected];
        yield 'a tag that is no plain version' => [200, self::release('v3.2.0-rc1'), $unexpected];
        yield 'a release page on another host' => [200, self::release('v3.2.0', ['html_url' => 'https://example.com/sendsmaily/smaily-prestashop-module/releases/tag/v3.2.0']), $unexpected];
        yield 'a zip of another repository' => [200, self::release('v3.2.0', ['assets' => [['name' => 'smailyforprestashop.zip', 'browser_download_url' => 'https://github.com/someone/smaily-prestashop-module/releases/download/v3.2.0/smailyforprestashop.zip']]]), $unexpected];
    }

    public function testNoAnswerChangesNothingAndRaisesNothing(): void
    {
        $this->github->answer(200, self::release('v3.0.5'));
        $this->tick(self::NOW);
        $stored = \Configuration::getGlobalValue(UpdateSettings::KEY);
        $lastLog = self::lastLogId();
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        $closedPort = (string) strrchr((string) stream_socket_get_name($socket, false), ':');
        fclose($socket);

        $report = $this->tickReport(self::NOW + self::DAY, 'http://127.0.0.1' . $closedPort);

        self::assertSame(TickReport::RAN, $report->status());
        self::assertSame($stored, \Configuration::getGlobalValue(UpdateSettings::KEY));
        self::assertSame(['NetworkException'], self::logLinesSince($lastLog));
    }

    public function testASlowGithubIsCutOffAfterTheTimeout(): void
    {
        $this->github->answer(200, self::release('v3.0.5'));
        $this->tick(self::NOW);
        $stored = \Configuration::getGlobalValue(UpdateSettings::KEY);
        $lastLog = self::lastLogId();
        $this->github->answer(200, self::release('v3.2.0'), LatestRelease::TIMEOUT_SECONDS + 3);

        $start = microtime(true);
        $report = $this->tickReport(self::NOW + self::DAY);
        $took = microtime(true) - $start;

        self::assertSame(TickReport::RAN, $report->status());
        self::assertLessThan(LatestRelease::TIMEOUT_SECONDS + 1.5, $took);
        self::assertSame($stored, \Configuration::getGlobalValue(UpdateSettings::KEY));
        self::assertSame(['NetworkException'], self::logLinesSince($lastLog));
    }

    public function testTheRequestCarriesNoStoreData(): void
    {
        // Distinct values: the demo shop is named "PrestaShop", which the module's own name holds.
        foreach (['PS_SHOP_NAME' => 'Invented Store Q7', 'PS_SHOP_EMAIL' => 'owner@store-q7.example'] as $key => $value) {
            $this->saved[$key] = (string) \Configuration::getGlobalValue($key);
            \Configuration::updateGlobalValue($key, $value);
        }
        $this->github->answer(200, self::release('v3.1.0'));
        $this->tick(self::NOW);

        $requests = $this->github->requests();
        self::assertCount(1, $requests);
        $request = $requests[0];
        $headers = array_change_key_case($request['headers'], CASE_LOWER);
        ksort($headers);

        self::assertSame('GET', $request['method']);
        self::assertSame('/repos/sendsmaily/smaily-prestashop-module/releases/latest', $request['uri'], 'no query string');
        self::assertSame('', $request['body']);
        self::assertSame(['accept', 'accept-encoding', 'host', 'user-agent'], array_keys($headers));
        self::assertSame('SmailyForPrestaShop/' . self::module()->version, $headers['user-agent']);
        self::assertSame('application/vnd.github+json', $headers['accept']);
        self::assertSame(parse_url($this->github->baseUrl(), PHP_URL_HOST) . ':' . parse_url($this->github->baseUrl(), PHP_URL_PORT), $headers['host']);
        $sent = strtolower($request['uri'] . "\n" . implode("\n", $headers));
        foreach (['PS_SHOP_DOMAIN', 'PS_SHOP_DOMAIN_SSL', 'PS_SHOP_NAME', 'PS_SHOP_EMAIL'] as $key) {
            $value = strtolower((string) \Configuration::get($key));
            self::assertNotSame('', $value, $key . ' is set in the shop');
            self::assertStringNotContainsString($value, $sent, $key);
        }
    }

    public function testAPageRequestReadsTheNoticeWithoutHttpOrQuery(): void
    {
        $this->github->answer(200, self::release('v3.1.0'));
        $this->tick(self::NOW);
        $requests = count($this->github->requests());
        $services = Services::fromProviders(Services::PROVIDERS);
        $services->set(Transport::class, static function (): Transport {
            throw new \LogicException('A page request built the HTTP transport.');
        });
        // A page request has loaded the configuration and the module before the admin reads the notice.
        \Configuration::loadConfiguration();
        self::module();

        $before = self::questions();
        $notice = $services->get(UpdateNotice::class)->current();
        $after = self::questions();

        self::assertSame('3.1.0', $notice['version'] ?? null);
        self::assertSame(1, $after - $before, 'no statement but the second count');
        self::assertCount($requests, $this->github->requests());
    }

    public function testTheConsoleUninstallLeavesNoKeyOfTheUpdateCheck(): void
    {
        $this->github->answer(200, self::release('v3.1.0'));
        $this->tick(self::NOW);
        $services = Services::fromProviders(Services::PROVIDERS);
        $services->get(UpdateNotice::class)->dismiss('3.1.0');
        self::assertSame(1, self::rows(UpdateSettings::KEY));

        self::assertSame(0, self::console('uninstall'));

        self::assertSame(0, self::rows(UpdateSettings::KEY));
    }

    /**
     * Runs the update check through a tick at Unix time $now, the clock of the tick, against the
     * mock (or $apiBase), and returns the runtime it ran in.
     */
    private function tick(int $now, ?string $apiBase = null): Services
    {
        [$services] = $this->runTick($now, $apiBase);

        return $services;
    }

    private function tickReport(int $now, ?string $apiBase = null): TickReport
    {
        [, $report] = $this->runTick($now, $apiBase);

        return $report;
    }

    /**
     * @return array{Services, TickReport}
     */
    private function runTick(int $now, ?string $apiBase): array
    {
        $services = Services::fromProviders(Services::PROVIDERS);
        $base = $apiBase ?? $this->github->baseUrl();
        $services->set(LatestRelease::class, static fn (Services $services): LatestRelease => new LatestRelease(
            $services->get(Transport::class),
            UpdateProvider::moduleVersion(),
            $base
        ));
        $jobs = array_values(array_filter(
            array_map(static fn (callable $job): Job => $job([]), $services->listeners(SchedulerProvider::JOBS)),
            static fn (Job $job): bool => $job->name() === UpdateProvider::JOB
        ));
        self::assertCount(1, $jobs);
        $tick = new Tick(new Lock(), new TickSettings(), $jobs, static fn (): bool => true, static fn (): float => (float) $now, [Tick::class, 'logFailure']);

        return [$services, $tick->run('tick', 50)];
    }

    /**
     * A release as GitHub's API answers it, with a zip asset.
     *
     * @param array<string, mixed> $changes
     */
    private static function release(string $tag, array $changes = []): string
    {
        return (string) json_encode(array_merge([
            'url' => 'https://api.github.com/repos/sendsmaily/smaily-prestashop-module/releases/1',
            'html_url' => 'https://github.com/sendsmaily/smaily-prestashop-module/releases/tag/' . $tag,
            'tag_name' => $tag,
            'name' => $tag,
            'draft' => false,
            'prerelease' => false,
            'assets' => [[
                'name' => 'smailyforprestashop.zip',
                'browser_download_url' => 'https://github.com/sendsmaily/smaily-prestashop-module/releases/download/' . $tag . '/smailyforprestashop.zip',
            ]],
        ], $changes));
    }

    private static function lastLogId(): int
    {
        return (int) self::db()->getValue('SELECT MAX(`id_log`) FROM `' . _DB_PREFIX_ . 'log`', false);
    }

    /**
     * The error class of each log line of the update check written after log row $lastLog.
     *
     * @return list<string>
     */
    private static function logLinesSince(int $lastLog): array
    {
        $rows = self::db()->executeS('SELECT `message` FROM `' . _DB_PREFIX_ . 'log` WHERE `id_log` > ' . $lastLog, true, false) ?: [];
        $errors = [];
        foreach ($rows as $row) {
            if (preg_match('/^Smaily Connect: the background job update_check failed with (?:.*\\\\)?(\w+) at /', $row['message'], $match) === 1) {
                $errors[] = $match[1];
            }
        }

        return $errors;
    }

    /**
     * The number of statements this connection has sent, counting this one.
     */
    private static function questions(): int
    {
        $rows = self::db()->executeS('SHOW SESSION STATUS LIKE \'Questions\'', true, false) ?: [];

        return (int) ($rows[0]['Value'] ?? 0);
    }

    private static function rows(string $key): int
    {
        return (int) self::db()->getValue(
            'SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'configuration` WHERE `name` = \'' . pSQL($key) . '\'',
            false
        );
    }

    private static function moduleId(): int
    {
        return (int) self::db()->getValue(
            'SELECT `id_module` FROM `' . _DB_PREFIX_ . 'module` WHERE `name` = \'smailyforprestashop\'',
            false
        );
    }

    private static function console(string $action): int
    {
        exec('php ' . _PS_ROOT_DIR_ . '/bin/console prestashop:module ' . $action . ' smailyforprestashop 2>&1', $output, $code);

        return $code;
    }
}
