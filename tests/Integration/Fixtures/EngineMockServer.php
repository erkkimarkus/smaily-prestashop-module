<?php
declare(strict_types=1);

namespace Tests\Integration\Fixtures;

use PrestaShop\Module\SmailyForPrestaShop\Http\NetworkException;
use PrestaShop\Module\SmailyForPrestaShop\Http\Response;
use PrestaShop\Module\SmailyForPrestaShop\Http\SymfonyTransport;
use PrestaShop\Module\SmailyForPrestaShop\Http\Transport;

/**
 * Runs the Campaign Intelligence engine mock (mock-engine/router.php) under `php -S` on a free
 * local port, for the whole test run. transport() sends the requests that the module addresses to
 * https://intelligence.smaily.com to the mock instead, over the real HTTP client, and lets no
 * request to any other host leave.
 */
final class EngineMockServer
{
    public const ENGINE = 'https://intelligence.smaily.com';

    private static ?self $running = null;

    /** @var resource */
    private $process;

    /** Whether the server leads a process group of its own, which stop() signals as a whole. */
    private bool $ownGroup;

    private string $baseUrl;

    private string $stateFile;

    private static ?SymfonyTransport $http = null;

    /**
     * @param resource $process
     */
    private function __construct($process, bool $ownGroup, string $baseUrl, string $stateFile)
    {
        $this->process = $process;
        $this->ownGroup = $ownGroup;
        $this->baseUrl = $baseUrl;
        $this->stateFile = $stateFile;
    }

    /**
     * Starts the mock once and empties its state: no connection, no used token, no switch set.
     */
    public static function start(): self
    {
        if (self::$running === null) {
            $socket = stream_socket_server('tcp://127.0.0.1:0');
            $port = (int) substr((string) strrchr((string) stream_socket_get_name($socket, false), ':'), 1);
            fclose($socket);

            $stateFile = (string) tempnam(sys_get_temp_dir(), 'engine-mock-');
            // As GithubMockServer: the server forks its workers, and `setsid` lets stop() signal
            // the server and its workers together.
            $ownGroup = function_exists('posix_kill') && self::hasSetsid();
            $command = ['php', '-S', '127.0.0.1:' . $port, __DIR__ . '/mock-engine/router.php'];
            $process = proc_open(
                $ownGroup ? array_merge(['setsid'], $command) : $command,
                [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
                $pipes,
                null,
                // Several workers, so that a delayed answer does not hold the next test's request.
                ['ENGINE_MOCK_STATE' => $stateFile, 'PATH' => (string) getenv('PATH'), 'PHP_CLI_SERVER_WORKERS' => '4']
            );
            if (!is_resource($process)) {
                throw new \RuntimeException('The engine mock did not start.');
            }
            self::$running = new self($process, $ownGroup, 'http://127.0.0.1:' . $port, $stateFile);
            register_shutdown_function([self::class, 'stop']);
            self::$running->waitUntilReachable($port);
        }
        self::$running->writeState([]);

        return self::$running;
    }

    public static function stop(): void
    {
        if (self::$running !== null) {
            $pid = (int) (proc_get_status(self::$running->process)['pid'] ?? 0);
            if (self::$running->ownGroup && $pid > 1) {
                // SIGTERM (15) to the process group, which a negative id names.
                posix_kill(-$pid, 15);
            } else {
                proc_terminate(self::$running->process);
            }
            @unlink(self::$running->stateFile);
            self::$running = null;
        }
    }

    /**
     * The transport of a test: the engine's address goes to the mock, and a request to any other
     * host fails as a network failure without leaving the test.
     */
    public function transport(): Transport
    {
        // One HTTP client for every test: a client keeps its connection open while it lives, and
        // PHPUnit keeps every test, so a client per test ran the suite out of open files.
        return new class($this->baseUrl, self::$http ??= new SymfonyTransport()) implements Transport {
            private string $baseUrl;

            private SymfonyTransport $transport;

            public function __construct(string $baseUrl, SymfonyTransport $transport)
            {
                $this->baseUrl = $baseUrl;
                $this->transport = $transport;
            }

            public function request(string $method, string $url, array $options = []): Response
            {
                if (strpos($url, EngineMockServer::ENGINE . '/') !== 0) {
                    throw NetworkException::of(new \RuntimeException('The test transport reaches the engine mock only.'));
                }

                return $this->transport->request($method, $this->baseUrl . substr($url, strlen(EngineMockServer::ENGINE)), $options);
            }
        };
    }

    /**
     * A setup link as the engine gives it (contract §1), for a token that the mock has not seen.
     */
    public function setupLink(string $token): string
    {
        return self::ENGINE . '/setup/' . $token;
    }

    /**
     * Sets a switch of the mock (mock-engine/router.php lists them).
     *
     * @param mixed $value
     */
    public function set(string $switch, $value): void
    {
        $this->writeState([$switch => $value] + $this->state());
    }

    /**
     * Makes a route (`exchange`, `ping`, `uninstall`) answer an HTTP error. A 401 and a 409 take
     * the contract's own bodies unless $error names another code.
     */
    public function fail(string $route, int $status, ?string $error = null, ?string $message = null): void
    {
        $failures = $this->state()['fail'] ?? [];
        $failures[$route] = array_filter(['status' => $status, 'error' => $error, 'message' => $message], static fn ($value): bool => $value !== null);
        $this->set('fail', $failures);
    }

    /**
     * Makes a route wait this many seconds before it answers.
     */
    public function delay(string $route, float $seconds): void
    {
        $this->set('delay', [$route => $seconds] + ($this->state()['delay'] ?? []));
    }

    /**
     * Plays a Smaily operator who revokes every key the mock issued: each then answers 401.
     */
    public function revokeKeys(): void
    {
        $connections = $this->state()['connections'] ?? [];
        foreach ($connections as $key => $connection) {
            if ($connection['revoked'] === false) {
                $connections[$key]['revoked'] = 'operator';
            }
        }
        $this->set('connections', $connections);
    }

    /**
     * How the mock names an API key in its state: by its SHA-256, so that the state file holds no
     * key it issued or was sent.
     */
    public static function keyId(string $apiKey): string
    {
        return hash('sha256', $apiKey);
    }

    /**
     * The connections the mock holds, by keyId() of their API key: `tenant_id`, `prefix`,
     * `revoked` (false, "uninstall" or "operator") and the `site_url` the exchange named.
     *
     * @return array<string, array{tenant_id: string, prefix: string, revoked: string|false, site_url: ?string}>
     */
    public function connections(): array
    {
        return $this->state()['connections'] ?? [];
    }

    /**
     * Every request received, oldest first; `key` is keyId() of the bearer key it carried (null
     * without one), `raw` the body as sent and `body` the body as JSON.
     *
     * @return list<array{method: string, path: string, query: array<string, string>, user_agent: ?string, accept: ?string, content_type: ?string, key: ?string, raw: string, body: mixed}>
     */
    public function requests(): array
    {
        return $this->state()['requests'] ?? [];
    }

    /**
     * The state file as the mock last wrote it.
     */
    public function stateText(): string
    {
        return (string) file_get_contents($this->stateFile);
    }

    /**
     * The requests of one path, oldest first.
     *
     * @return list<array<string, mixed>>
     */
    public function requestsTo(string $path): array
    {
        return array_values(array_filter($this->requests(), static fn (array $request): bool => $request['path'] === $path));
    }

    /**
     * @return array<string, mixed>
     */
    private function state(): array
    {
        return (array) json_decode((string) file_get_contents($this->stateFile), true);
    }

    /**
     * @param array<string, mixed> $state
     */
    private function writeState(array $state): void
    {
        file_put_contents($this->stateFile, json_encode($state));
    }

    private static function hasSetsid(): bool
    {
        exec('command -v setsid 2>/dev/null', $output, $status);

        return $status === 0;
    }

    private function waitUntilReachable(int $port): void
    {
        $deadline = microtime(true) + 5;
        while (microtime(true) < $deadline) {
            $connection = @fsockopen('127.0.0.1', $port, $errno, $error, 0.1);
            if (is_resource($connection)) {
                fclose($connection);

                return;
            }
            usleep(100000);
        }
        self::stop();
        throw new \RuntimeException('The engine mock did not answer on port ' . $port . '.');
    }
}
