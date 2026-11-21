<?php
declare(strict_types=1);

namespace Tests\Unit\Storefront;

use PrestaShop\Module\SmailyForPrestaShop\Engine\Client;
use PrestaShop\Module\SmailyForPrestaShop\Engine\Connection;
use PrestaShop\Module\SmailyForPrestaShop\Http\Response;
use PrestaShop\Module\SmailyForPrestaShop\Http\Transport;
use PrestaShop\Module\SmailyForPrestaShop\Storefront\ActiveConnection;
use PrestaShop\Module\SmailyForPrestaShop\Storefront\Attribution;
use PrestaShop\Module\SmailyForPrestaShop\Storefront\Cookies;
use PrestaShop\Module\SmailyForPrestaShop\Storefront\RateLimiter;
use PrestaShop\Module\SmailyForPrestaShop\Storefront\Store;

/**
 * What the storefront's unit tests stand on: a connection, a cache in a temporary folder, a clock
 * that the test moves, cookies that record what the server sets, and an engine that records each
 * request and answers what the test gives it.
 */
final class StorefrontFixture
{
    public const KEY = 'sk_test_not_a_real_key';

    public const NOW = 1795000000;

    public int $now = self::NOW;

    public string $address = '192.0.2.10';

    public bool $consentConfirmed = false;

    public ?Connection $connection;

    /** @var list<array{string, string, array<string, mixed>}> every cookie the server set */
    public array $sent = [];

    /** @var list<array{string, string, array<string, mixed>}> every request to the engine */
    public array $requests = [];

    /** @var Response|\Throwable what the engine answers */
    public $answer;

    private string $directory;

    public function __construct()
    {
        $this->connection = self::connection();
        $this->answer = new Response(200, '{"ok": true}');
        $this->directory = sys_get_temp_dir() . '/smaily-storefront-' . bin2hex(random_bytes(6));
    }

    public function __destruct()
    {
        foreach (glob($this->directory . '/*') ?: [] as $file) {
            unlink($file);
        }
        if (is_dir($this->directory)) {
            rmdir($this->directory);
        }
    }

    /**
     * @param array<string, mixed> $settings
     */
    public static function connection(array $settings = [], int $idShop = 0): Connection
    {
        return new Connection(7, $idShop, '', 'tenant-1', self::KEY, $settings + ['engine_base_url' => Client::BASE_URL]);
    }

    public function directory(): string
    {
        return $this->directory;
    }

    public function store(): Store
    {
        return new Store($this->directory, fn (): int => $this->now);
    }

    public function limiter(): RateLimiter
    {
        return new RateLimiter($this->store(), fn (): string => $this->address, fn (): int => $this->now, 'test-secret');
    }

    public function connections(): ActiveConnection
    {
        return new ActiveConnection(fn (int $idShop): ?Connection => $this->connection);
    }

    public function attribution(): Attribution
    {
        return new Attribution(fn (int $idShop): bool => $this->consentConfirmed);
    }

    /**
     * @param array<string, mixed> $received
     */
    public function cookies(array $received = [], bool $sendable = true): Cookies
    {
        return new Cookies($received, '/', false, function (string $name, string $value, array $options) use ($sendable): bool {
            if ($sendable) {
                $this->sent[] = [$name, $value, $options];
            }

            return $sendable;
        });
    }

    public function client(): Client
    {
        return new Client(new class($this) implements Transport {
            private StorefrontFixture $fixture;

            public function __construct(StorefrontFixture $fixture)
            {
                $this->fixture = $fixture;
            }

            public function request(string $method, string $url, array $options = []): Response
            {
                $this->fixture->requests[] = [$method, $url, $options];
                if ($this->fixture->answer instanceof \Throwable) {
                    throw $this->fixture->answer;
                }

                return $this->fixture->answer;
            }
        }, '3.0.0');
    }
}
