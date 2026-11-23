<?php
declare(strict_types=1);

namespace Tests\Unit\Fixtures;

use PHPUnit\Framework\TestCase;
use Tests\Integration\Fixtures\SmailyMockServer;

require_once __DIR__ . '/../../Integration/Fixtures/SmailyMockServer.php';

/**
 * The Smaily mock keeps no credential that a request brought. Whoever walks a dev shop behind the
 * egress guard may type a real API user and password into the stand-in service: the mock's state
 * file, which a walk reads and prints, holds neither, nor the request's Authorization header.
 */
final class SmailyMockCredentialsTest extends TestCase
{
    private const URL = 'https://demo.sendsmaily.net/api/workflows.php';

    private SmailyMockServer $mock;

    protected function setUp(): void
    {
        $this->mock = SmailyMockServer::start();
        $this->mock->acceptCredentials('api-user', 'not-a-real-password');
    }

    public function testARefusedRequestLeavesNothingOfItsCredentials(): void
    {
        $refused = $this->mock->transport()->request('GET', self::URL, ['auth_basic' => ['typed-user', 'typed-password']]);

        self::assertSame(401, $refused->status());
        $request = $this->mock->requests()[0];
        self::assertSame(
            [false, null, 10, 'present'],
            [$request['authorized'], $request['username'], $request['username_length'], $request['authorization']]
        );
        $kept = (string) json_encode($this->mock->requests());
        foreach (['typed-user', 'typed-password', base64_encode('typed-user:typed-password'), 'Basic '] as $secret) {
            self::assertStringNotContainsString($secret, $kept);
        }
    }

    public function testAnAcceptedRequestNamesTheUserTheMockAcceptsAndNeverThePassword(): void
    {
        $accepted = $this->mock->transport()->request('GET', self::URL, ['auth_basic' => ['api-user', 'not-a-real-password']]);

        self::assertSame(200, $accepted->status());
        $request = $this->mock->requests()[0];
        self::assertSame(
            [true, 'api-user', 8, 'present'],
            [$request['authorized'], $request['username'], $request['username_length'], $request['authorization']]
        );
        $kept = (string) json_encode($this->mock->requests());
        self::assertStringNotContainsString('not-a-real-password', $kept);
        self::assertStringNotContainsString(base64_encode('api-user:not-a-real-password'), $kept);
    }

    public function testARequestWithoutCredentialsSaysSo(): void
    {
        $this->mock->transport()->request('GET', self::URL);

        $request = $this->mock->requests()[0];
        self::assertSame(
            [false, null, null, 'absent'],
            [$request['authorized'], $request['username'], $request['username_length'], $request['authorization']]
        );
    }
}
