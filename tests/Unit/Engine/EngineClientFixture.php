<?php
declare(strict_types=1);

namespace Tests\Unit\Engine;

use PrestaShop\Module\SmailyForPrestaShop\Engine\Client;
use PrestaShop\Module\SmailyForPrestaShop\Engine\Connection;
use PrestaShop\Module\SmailyForPrestaShop\Http\Response;
use PrestaShop\Module\SmailyForPrestaShop\Http\Transport;

/**
 * What the tests of the engine client share: a connection with an invented key, and a transport
 * that records.
 */
final class EngineClientFixture
{
    public const KEY = 'sk_not_a_real_key';

    /**
     * @param array<string, string> $endpoints the endpoints map of the connection's setup exchange
     */
    public static function connection(array $endpoints = []): Connection
    {
        return new Connection(1, 0, 'demo', 'tenant', self::KEY, ['engine_base_url' => Client::BASE_URL, 'endpoints' => $endpoints]);
    }
}

/**
 * Records each request and answers every one with the same response or failure.
 */
final class EngineRecordingTransport implements Transport
{
    /** @var list<array{string, string, array<string, mixed>}> */
    public array $requests = [];

    /** @var Response|\Throwable */
    private $answer;

    /**
     * @param Response|\Throwable $answer
     */
    public function __construct($answer)
    {
        $this->answer = $answer;
    }

    public function request(string $method, string $url, array $options = []): Response
    {
        $this->requests[] = [$method, $url, $options];
        if ($this->answer instanceof \Throwable) {
            throw $this->answer;
        }

        return $this->answer;
    }
}
