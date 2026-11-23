<?php
declare(strict_types=1);

namespace Tests\Integration\Report;

use PrestaShop\Module\SmailyForPrestaShop\Config\ReportSettings;
use PrestaShop\Module\SmailyForPrestaShop\Config\TickSettings;
use PrestaShop\Module\SmailyForPrestaShop\Engine\Connection;
use PrestaShop\Module\SmailyForPrestaShop\Engine\Connections;
use PrestaShop\Module\SmailyForPrestaShop\Http\NetworkException;
use PrestaShop\Module\SmailyForPrestaShop\Http\Response;
use PrestaShop\Module\SmailyForPrestaShop\Http\Transport;
use PrestaShop\Module\SmailyForPrestaShop\Report\ReportSender;
use PrestaShop\Module\SmailyForPrestaShop\Runtime\Provider\ReportProvider;
use PrestaShop\Module\SmailyForPrestaShop\Runtime\Provider\SchedulerProvider;
use PrestaShop\Module\SmailyForPrestaShop\Runtime\Services;
use PrestaShop\Module\SmailyForPrestaShop\Scheduler\Job;
use PrestaShop\Module\SmailyForPrestaShop\Scheduler\Lock;
use PrestaShop\Module\SmailyForPrestaShop\Scheduler\Tick;
use PrestaShop\Module\SmailyForPrestaShop\Smaily\Account;
use PrestaShop\Module\SmailyForPrestaShop\Smaily\AccountStore;
use Tests\Integration\IntegrationTestCase;
use Tests\Integration\Migration\LegacyStore;

require_once __DIR__ . '/../Migration/LegacyStore.php';
require_once __DIR__ . '/../Fixtures/mock-subscription/contract.php';

/**
 * Answers every request of a test in memory: nothing leaves the process. A request to the
 * installation report's receiver takes the next of the answers a test queued (the last one stays),
 * a Smaily host accepts everything, the engine answers its three connection calls, and any other
 * host does not answer.
 */
final class ReportHostsTransport implements Transport
{
    /** @var list<array{method: string, url: string, options: array<string, mixed>}> */
    public array $requests = [];

    /** @var list<Response|\Throwable> */
    public array $reportAnswers = [];

    public function request(string $method, string $url, array $options = []): Response
    {
        $this->requests[] = ['method' => $method, 'url' => $url, 'options' => $options];
        $host = (string) parse_url($url, PHP_URL_HOST);
        $path = (string) parse_url($url, PHP_URL_PATH);

        if ($url === ReportSender::ENDPOINT) {
            $answer = count($this->reportAnswers) > 1 ? array_shift($this->reportAnswers) : ($this->reportAnswers[0] ?? new Response(200, '{"installation_id": "3f8a1c52-7b9e-4d1a-9c3e-2a6f5b8d0e17"}'));
            if ($answer instanceof \Throwable) {
                throw $answer;
            }

            return $answer;
        }
        if (preg_match('/\.sendsmaily\.net$/D', $host) === 1) {
            return new Response(200, $method === 'POST' ? '{"code": 101, "message": "OK"}' : '[]');
        }
        if ($host === 'intelligence.smaily.com') {
            switch ($path) {
                case '/api/setup/exchange':
                    return new Response(200, (string) json_encode(['api_key' => 'sk_not_a_real_key', 'tenant_id' => '2c9d7e41-5f3a-4b86-9d02-7a1e6c4f8b35']));
                case '/api/v1/store/uninstall':
                    return new Response(200, '{"ok": true, "status": "store_paused"}');
                case '/api/v1/ingest/ping':
                    return new Response(200, '{"ok": true, "pong": true}');
            }

            return new Response(404, '{"error": "not_found"}');
        }

        throw new NetworkException('The test lets no request to another host leave.');
    }

    /**
     * The requests to the installation report's receiver.
     *
     * @return list<array{method: string, url: string, options: array<string, mixed>}>
     */
    public function reports(): array
    {
        return array_values(array_filter($this->requests, static fn (array $request): bool => $request['url'] === ReportSender::ENDPOINT));
    }

    /**
     * The decoded body of a report.
     *
     * @return array<string, mixed>
     */
    public function report(int $index = 0): array
    {
        return json_decode((string) $this->reports()[$index]['options']['body'], true);
    }
}

/**
 * The base of the installation report's tests in the shop: a fresh 3.0.0 install, the runtime with
 * a transport that answers in memory, and that runtime as the shop's own, so the module's hooks,
 * its uninstall and its admin endpoints use it too. No request of a test leaves the process.
 */
abstract class ReportTestCase extends IntegrationTestCase
{
    protected const PASSWORD = 'not-a-real-password';

    protected ReportHostsTransport $transport;

    protected Services $services;

    protected function setUp(): void
    {
        parent::setUp();
        LegacyStore::restore();
        $this->transport = new ReportHostsTransport();
        $this->services = Services::fromProviders(Services::PROVIDERS);
        $transport = $this->transport;
        $this->services->set(Transport::class, static fn (): Transport => $transport);
        $this->useAsShopRuntime($this->services);
    }

    protected function tearDown(): void
    {
        if (defined('_PS_ROOT_DIR_')) {
            $this->restoreShopRuntime();
            LegacyStore::restore();
        }
        parent::tearDown();
    }

    protected function storeAccount(int $idShop, string $subdomain, string $language = ''): void
    {
        $this->services->get(AccountStore::class)->save(new Account($idShop, $language, $subdomain, 'api-user-' . $subdomain, self::PASSWORD));
    }

    /**
     * Stores a Campaign Intelligence connection as a setup exchange leaves it.
     */
    protected function storeConnection(int $idShop, string $tenantId = '2c9d7e41-5f3a-4b86-9d02-7a1e6c4f8b35'): Connection
    {
        return $this->services->get(Connections::class)->save($idShop, 'example', $tenantId, 'sk_not_a_real_key', ['engine_base_url' => 'https://intelligence.smaily.com']);
    }

    /**
     * Runs a tick that holds the installation report's job alone, as the provider registered it.
     */
    protected function tick(): void
    {
        $jobs = array_values(array_filter(
            array_map(static fn (callable $job): Job => $job([]), $this->services->listeners(SchedulerProvider::JOBS)),
            static fn (Job $job): bool => $job->name() === ReportProvider::JOB
        ));
        self::assertCount(1, $jobs);
        $failures = [];
        (new Tick(new Lock(), new TickSettings(), $jobs, static fn (): bool => true, static fn (): float => microtime(true), static function (string $job, \Throwable $error) use (&$failures): void {
            $failures[] = get_class($error);
        }))->run('tick', 50);
        self::assertSame([], $failures, 'the job threw nothing');
    }

    /**
     * @return array<string, mixed>
     */
    protected static function state(): array
    {
        $value = json_decode((string) self::stored(ReportSettings::STATE), true);

        return is_array($value) ? $value : [];
    }

    /**
     * @param array<string, mixed> $state
     */
    protected static function setState(array $state): void
    {
        \Configuration::updateGlobalValue(ReportSettings::STATE, (string) json_encode($state));
    }

    protected static function stored(string $key): ?string
    {
        $value = self::db()->getValue('SELECT `value` FROM `' . _DB_PREFIX_ . 'configuration` WHERE `name` = \'' . pSQL($key) . '\' AND `id_shop` IS NULL AND `id_shop_group` IS NULL', false);

        return $value === false ? null : (string) $value;
    }

    /**
     * The lines of the shop's log about the installation report, written after log row $after.
     *
     * @return list<string>
     */
    protected static function logLines(int $after): array
    {
        return array_column((array) self::db()->executeS(
            'SELECT `message` FROM `' . _DB_PREFIX_ . 'log` WHERE `id_log` > ' . $after . ' AND `message` LIKE \'Smaily Connect: the installation report%\' ORDER BY `id_log`',
            true,
            false
        ), 'message');
    }

    protected static function lastLogId(): int
    {
        return (int) self::db()->getValue('SELECT MAX(`id_log`) FROM `' . _DB_PREFIX_ . 'log`', false);
    }

    /**
     * Holds a report's body against the contract's rules and against the secrets of the tests.
     *
     * @param array<string, mixed> $body
     */
    protected static function assertFollowsTheContract(array $body): void
    {
        self::assertSame([], installation_report_errors($body), 'the body follows the contract');
        self::assertSame([], installation_report_unknown_fields($body), 'the body holds only the contract\'s fields');
        $json = (string) json_encode($body);
        foreach ([self::PASSWORD, 'api-user', 'sk_not_a_real_key'] as $secret) {
            self::assertStringNotContainsString($secret, $json);
        }
    }
}
