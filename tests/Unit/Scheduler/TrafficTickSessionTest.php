<?php
declare(strict_types=1);

namespace Tests\Unit\Scheduler;

use PHPUnit\Framework\TestCase;

/**
 * PHP keeps the request's session locked until the request ends: the visitor's next request would
 * wait for the fallback tick, although the response has gone out. The fallback tick releases the
 * session before its work. A process of its own (session-worker.php) starts the session before
 * anything is printed, which this process cannot.
 */
final class TrafficTickSessionTest extends TestCase
{
    public function testReleasesTheSessionBeforeTheTickRuns(): void
    {
        $process = proc_open(
            [PHP_BINARY, __DIR__ . '/session-worker.php'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );
        self::assertIsResource($process);
        $seen = (string) stream_get_contents($pipes[1]);
        $errors = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);

        self::assertSame((string) PHP_SESSION_NONE, $seen, $errors);
    }
}
