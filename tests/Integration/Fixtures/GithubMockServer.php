<?php
declare(strict_types=1);

namespace Tests\Integration\Fixtures;

/**
 * Runs the GitHub release mock (mock-github/router.php) under `php -S` on a free local port, for
 * the whole test run. baseUrl() stands in for https://api.github.com.
 */
final class GithubMockServer
{
    private static ?self $running = null;

    /** @var resource */
    private $process;

    /** Whether the server leads a process group of its own, which stop() signals as a whole. */
    private bool $ownGroup;

    private string $baseUrl;

    private string $stateFile;

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
     * Starts the mock once and empties its state: it answers 404 until answer() is called.
     */
    public static function start(): self
    {
        if (self::$running === null) {
            $socket = stream_socket_server('tcp://127.0.0.1:0');
            $port = (int) substr((string) strrchr((string) stream_socket_get_name($socket, false), ':'), 1);
            fclose($socket);

            $stateFile = (string) tempnam(sys_get_temp_dir(), 'github-mock-');
            // The server forks its workers, and on PHP 8.0 a signal to the server alone leaves them
            // running. `setsid` makes the server the leader of a process group of its own, so that
            // stop() signals the server and its workers together.
            $ownGroup = function_exists('posix_kill') && self::hasSetsid();
            $command = ['php', '-S', '127.0.0.1:' . $port, __DIR__ . '/mock-github/router.php'];
            $process = proc_open(
                $ownGroup ? array_merge(['setsid'], $command) : $command,
                [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
                $pipes,
                null,
                // Several workers, so that a delayed answer does not hold the next test's request.
                ['GITHUB_MOCK_STATE' => $stateFile, 'PATH' => (string) getenv('PATH'), 'PHP_CLI_SERVER_WORKERS' => '4']
            );
            if (!is_resource($process)) {
                throw new \RuntimeException('The GitHub mock did not start.');
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

    public function baseUrl(): string
    {
        return $this->baseUrl;
    }

    /**
     * Sets the answer to the latest-release request.
     *
     * @param float $delay seconds the mock waits before it answers
     */
    public function answer(int $status, string $body, float $delay = 0): void
    {
        $this->writeState(['answer' => ['status' => $status, 'body' => $body, 'delay' => $delay]] + $this->state());
    }

    /**
     * @return list<array{method: string, uri: string, headers: array<string, string>, body: string}>
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
        throw new \RuntimeException('The GitHub mock did not answer on port ' . $port . '.');
    }
}
