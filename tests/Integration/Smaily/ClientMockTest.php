<?php
declare(strict_types=1);

namespace Tests\Integration\Smaily;

use PrestaShop\Module\SmailyForPrestaShop\Smaily\Client;
use PrestaShop\Module\SmailyForPrestaShop\Smaily\Exception\ApiException;
use PrestaShop\Module\SmailyForPrestaShop\Smaily\Exception\AuthenticationException;
use Tests\Integration\Fixtures\SmailyMockServer;
use Tests\Integration\IntegrationTestCase;

require_once __DIR__ . '/../Fixtures/SmailyMockServer.php';

/**
 * Every Smaily call that 2.x made goes over HTTP to the Smaily mock: the contact upsert, the
 * unsubscriber list, and the autoresponder call with a workflow id and without one (the generic
 * opt-in).
 */
final class ClientMockTest extends IntegrationTestCase
{
    private const PASSWORD = 'not-a-real-password';

    private SmailyMockServer $mock;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mock = SmailyMockServer::start();
        $this->mock->acceptCredentials('api-user', self::PASSWORD);
        $this->client = new Client($this->mock->transport(), 'demo', 'api-user', self::PASSWORD);
    }

    public function testUpsertsContacts(): void
    {
        $contacts = [
            ['email' => 'first@example.test', 'store' => 'shop.example.test', 'first_name' => 'First'],
            ['email' => 'second@example.test', 'store' => 'shop.example.test'],
        ];

        self::assertSame(['code' => 101, 'message' => 'OK'], $this->client->post(Client::ENDPOINT_CONTACT, $contacts));

        $this->assertOneRequest('POST', '/api/contact.php');
        self::assertSame('application/json', $this->mock->requests()[0]['content_type']);
        self::assertSame($contacts, $this->mock->requests()[0]['body']);
    }

    public function testListsUnsubscribersPageByPage(): void
    {
        $this->mock->setUnsubscribers(['first@example.test', 'second@example.test', 'third@example.test']);

        $pages = [];
        for ($offset = 0; $offset < 3; ++$offset) {
            $pages[] = array_column(
                $this->client->get(Client::ENDPOINT_CONTACT, ['list' => 2, 'limit' => 2, 'offset' => $offset]),
                'email'
            );
        }

        self::assertSame([['first@example.test', 'second@example.test'], ['third@example.test'], []], $pages);
        self::assertSame(['list' => '2', 'limit' => '2', 'offset' => '0'], $this->mock->requests()[0]['query']);
    }

    public function testTriggersAWorkflowById(): void
    {
        $payload = ['autoresponder' => '42', 'addresses' => [['email' => 'first@example.test', 'is_abandoned_cart' => true]]];

        self::assertSame(101, $this->client->post(Client::ENDPOINT_AUTORESPONDER, $payload)['code']);

        $this->assertOneRequest('POST', '/api/autoresponder.php');
        self::assertSame($payload, $this->mock->requests()[0]['body']);
    }

    public function testOptsInWithoutAWorkflowId(): void
    {
        $payload = ['addresses' => [['email' => 'first@example.test']]];

        self::assertSame(101, $this->client->post(Client::ENDPOINT_AUTORESPONDER, $payload)['code']);

        $this->assertOneRequest('POST', '/api/autoresponder.php');
        self::assertSame($payload, $this->mock->requests()[0]['body']);
    }

    public function testInvalidDataIsAPermanentApiError(): void
    {
        try {
            $this->client->post(Client::ENDPOINT_CONTACT, [['name' => 'No address']]);
            self::fail('Expected an ApiException');
        } catch (ApiException $exception) {
            self::assertSame(203, $exception->getSmailyCode());
            self::assertTrue($exception->isPermanent());
        }
    }

    public function testWrongCredentialsAreRejected(): void
    {
        $client = new Client($this->mock->transport(), 'demo', 'api-user', 'a-wrong-fake-password');

        try {
            $client->get(Client::ENDPOINT_CONTACT, ['list' => 2]);
            self::fail('Expected an AuthenticationException');
        } catch (AuthenticationException $exception) {
            self::assertSame(401, $exception->getHttpStatus());
            self::assertStringNotContainsString('a-wrong-fake-password', $exception->getMessage());
        }
    }

    private function assertOneRequest(string $method, string $path): void
    {
        $requests = $this->mock->requests();
        self::assertCount(1, $requests);
        self::assertSame([$method, $path, true], [$requests[0]['method'], $requests[0]['path'], $requests[0]['authorized']]);
    }
}
