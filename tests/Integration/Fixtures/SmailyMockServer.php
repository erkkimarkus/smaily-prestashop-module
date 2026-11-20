<?php
declare(strict_types=1);

namespace Tests\Integration\Fixtures;

use PrestaShop\Module\SmailyForPrestaShop\Http\Response;
use PrestaShop\Module\SmailyForPrestaShop\Http\SymfonyTransport;
use PrestaShop\Module\SmailyForPrestaShop\Http\Transport;

/**
 * Runs the Smaily API mock (mock-smaily/router.php) under `php -S` on a free local port, for the
 * whole test run. transport() sends the requests that the module addresses to
 * https://{subdomain}.sendsmaily.net to the mock instead, over the real HTTP client.
 */
final class SmailyMockServer
{
    private static ?self $running = null;

    /** @var resource */
    private $process;

    private string $baseUrl;

    private string $stateFile;

    /**
     * @param resource $process
     */
    private function __construct($process, string $baseUrl, string $stateFile)
    {
        $this->process = $process;
        $this->baseUrl = $baseUrl;
        $this->stateFile = $stateFile;
    }

    /**
     * Starts the mock once and empties its state.
     */
    public static function start(): self
    {
        if (self::$running === null) {
            $socket = stream_socket_server('tcp://127.0.0.1:0');
            $port = (int) substr((string) strrchr((string) stream_socket_get_name($socket, false), ':'), 1);
            fclose($socket);

            $stateFile = (string) tempnam(sys_get_temp_dir(), 'smaily-mock-');
            $process = proc_open(
                ['php', '-S', '127.0.0.1:' . $port, __DIR__ . '/mock-smaily/router.php'],
                [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
                $pipes,
                null,
                ['SMAILY_MOCK_STATE' => $stateFile, 'PATH' => (string) getenv('PATH')]
            );
            if (!is_resource($process)) {
                throw new \RuntimeException('The Smaily mock did not start.');
            }
            self::$running = new self($process, 'http://127.0.0.1:' . $port, $stateFile);
            register_shutdown_function([self::class, 'stop']);
            self::$running->waitUntilReachable($port);
        }
        self::$running->writeState([]);

        return self::$running;
    }

    public static function stop(): void
    {
        if (self::$running !== null) {
            proc_terminate(self::$running->process);
            @unlink(self::$running->stateFile);
            self::$running = null;
        }
    }

    public function transport(): Transport
    {
        $baseUrl = $this->baseUrl;

        return new class($baseUrl) implements Transport {
            private string $baseUrl;

            private SymfonyTransport $transport;

            public function __construct(string $baseUrl)
            {
                $this->baseUrl = $baseUrl;
                $this->transport = new SymfonyTransport();
            }

            public function request(string $method, string $url, array $options = []): Response
            {
                $url = (string) preg_replace('~^https://[a-z0-9-]+\.sendsmaily\.net~i', $this->baseUrl, $url);

                return $this->transport->request($method, $url, $options);
            }
        };
    }

    public function acceptCredentials(string $username, string $password): void
    {
        $this->writeState(['credentials' => ['username' => $username, 'password' => $password]] + $this->state());
    }

    /**
     * @param list<string> $emails
     */
    public function setUnsubscribers(array $emails): void
    {
        $this->writeState(['unsubscribers' => $emails] + $this->state());
    }

    /**
     * The rows of the action log that history.php answers from.
     *
     * @param list<array{seq_id: int, email: string, action: string}> $rows
     */
    public function setHistory(array $rows): void
    {
        $this->writeState(['history' => $rows] + $this->state());
    }

    /**
     * @return list<array{method: string, path: string, query: array<string, string>, content_type: ?string, body: mixed, authorized: bool}>
     */
    public function requests(): array
    {
        return $this->state()['requests'] ?? [];
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
        throw new \RuntimeException('The Smaily mock did not answer on port ' . $port . '.');
    }
}
