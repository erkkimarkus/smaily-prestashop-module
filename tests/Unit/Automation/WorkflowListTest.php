<?php
declare(strict_types=1);

namespace Tests\Unit\Automation;

use PHPUnit\Framework\TestCase;
use PrestaShop\Module\SmailyForPrestaShop\Automation\WorkflowList;
use PrestaShop\Module\SmailyForPrestaShop\Http\NetworkException;
use PrestaShop\Module\SmailyForPrestaShop\Http\Response;
use PrestaShop\Module\SmailyForPrestaShop\Http\Transport;
use PrestaShop\Module\SmailyForPrestaShop\Smaily\Account;

/**
 * The workflows of an account for the admin's dropdowns, and a failure as a reason code.
 */
final class WorkflowListTest extends TestCase
{
    /** @var list<array{host: string, path: string, query: array<string, mixed>}> */
    private array $requests = [];

    public function testListsTheActiveWorkflowsOfTheAccount(): void
    {
        $list = $this->list(new Response(200, '[{"id": 101, "name": "Welcome", "status": "ACTIVE"}, {"id": "102", "name": "Cart"}, {"name": "No id"}, "x"]'))
            ->of(new Account(0, '', 'shared', 'api-user', 'not-a-real-password'));

        self::assertSame(['workflows' => [['id' => 101, 'name' => 'Welcome'], ['id' => 102, 'name' => 'Cart']], 'error' => null], $list);
        self::assertSame([['host' => 'shared.sendsmaily.net', 'path' => '/api/autoresponder.php', 'query' => ['status' => 'ACTIVE']]], $this->requests);
    }

    /**
     * @return array<string, array{Response|\Throwable, string}>
     */
    public function failures(): array
    {
        return [
            'wrong credentials' => [new Response(401, ''), WorkflowList::ERROR_AUTHENTICATION],
            'no API in the package' => [new Response(403, '{"code": 227}'), WorkflowList::ERROR_PLAN_BLOCKED],
            'another refusal' => [new Response(404, ''), WorkflowList::ERROR_REFUSED],
            'Smaily unavailable' => [new Response(503, ''), WorkflowList::ERROR_UNAVAILABLE],
            'no answer' => [NetworkException::of(new \RuntimeException('timeout')), WorkflowList::ERROR_UNAVAILABLE],
            'an envelope code' => [new Response(200, '{"code": 203, "message": "Invalid data"}'), WorkflowList::ERROR_API],
        ];
    }

    /**
     * @dataProvider failures
     *
     * @param Response|\Throwable $answer
     */
    public function testAFailureIsAReasonCode($answer, string $error): void
    {
        self::assertSame(
            ['workflows' => [], 'error' => $error],
            $this->list($answer)->of(new Account(0, '', 'shared', 'api-user', 'not-a-real-password'))
        );
    }

    public function testAnAccountThatCannotCallSmailyIsAskedNothing(): void
    {
        $list = $this->list(new Response(200, '[]'));

        self::assertSame(['workflows' => [], 'error' => WorkflowList::ERROR_NO_ACCOUNT], $list->of(null));
        self::assertSame(['workflows' => [], 'error' => WorkflowList::ERROR_NO_CREDENTIALS], $list->of(new Account(0, '', 'shared', 'api-user', '')));
        self::assertSame(['workflows' => [], 'error' => WorkflowList::ERROR_INVALID_SUBDOMAIN], $list->of(new Account(0, '', 'shared.example', 'api-user', 'not-a-real-password')));
        self::assertSame([], $this->requests);
    }

    /**
     * @param Response|\Throwable $answer
     */
    private function list($answer): WorkflowList
    {
        $test = $this;

        return new WorkflowList(new class($test, $answer) implements Transport {
            private WorkflowListTest $test;

            /** @var Response|\Throwable */
            private $answer;

            /**
             * @param Response|\Throwable $answer
             */
            public function __construct(WorkflowListTest $test, $answer)
            {
                $this->test = $test;
                $this->answer = $answer;
            }

            public function request(string $method, string $url, array $options = []): Response
            {
                $this->test->record($url, $options['query'] ?? []);
                if ($this->answer instanceof \Throwable) {
                    throw $this->answer;
                }

                return $this->answer;
            }
        });
    }

    /**
     * @param array<string, mixed> $query
     */
    public function record(string $url, array $query): void
    {
        $this->requests[] = ['host' => (string) parse_url($url, PHP_URL_HOST), 'path' => (string) parse_url($url, PHP_URL_PATH), 'query' => $query];
    }
}
