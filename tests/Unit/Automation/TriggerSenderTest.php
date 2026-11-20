<?php
declare(strict_types=1);

namespace Tests\Unit\Automation;

use PHPUnit\Framework\TestCase;
use PrestaShop\Module\SmailyForPrestaShop\Automation\Router;
use PrestaShop\Module\SmailyForPrestaShop\Automation\Trigger;
use PrestaShop\Module\SmailyForPrestaShop\Automation\TriggerSender;
use PrestaShop\Module\SmailyForPrestaShop\Config\AutomationSettings;
use PrestaShop\Module\SmailyForPrestaShop\Http\NetworkException;
use PrestaShop\Module\SmailyForPrestaShop\Http\Response;
use PrestaShop\Module\SmailyForPrestaShop\Http\Transport;
use PrestaShop\Module\SmailyForPrestaShop\Queue\Failure;
use PrestaShop\Module\SmailyForPrestaShop\Queue\Skipped;
use PrestaShop\Module\SmailyForPrestaShop\Scope\StoreLayout;
use PrestaShop\Module\SmailyForPrestaShop\Smaily\Account;
use Tests\Unit\Scope\FakeStoreLayout;

require_once __DIR__ . '/FakeAutomationSettings.php';
require_once __DIR__ . '/FakeMappingRepository.php';
require_once __DIR__ . '/../Scope/FakeStoreLayout.php';

/**
 * The sender posts an automation trigger in 2.x's two request shapes, through the account of the
 * matched row, never re-subscribing anyone, and hands back the queue's outcome of the row.
 *
 * Shop 1 reads the shared setup: welcome workflow 101 and abandoned-cart workflow 102 through the
 * shared account, the first-order automation off. Shop 2 is on another account and keeps 2.x's
 * generic opt-in for its welcome.
 */
final class TriggerSenderTest extends TestCase
{
    /** @var list<array{method: string, host: string, auth: array{string, string}, body: array<string, mixed>}> */
    private array $requests = [];

    /** @var callable(): Response */
    private $answer;

    protected function setUp(): void
    {
        $this->answer = static fn (): Response => new Response(200, '{"code": 101, "message": "OK"}');
    }

    public function testTriggersTheMatchedWorkflowThroughItsAccount(): void
    {
        $outcome = $this->sender()->send(Trigger::ABANDONED_CART, 1, 'et', 'shopper1@example.test', ['is_abandoned_cart' => 'true', 'product_name_1' => 'Testproduct']);

        self::assertTrue($outcome);
        self::assertSame([[
            'method' => 'POST',
            'host' => 'shared',
            'auth' => ['shared-user', 'not-a-real-password'],
            'body' => [
                'autoresponder' => 102,
                'addresses' => [['email' => 'shopper1@example.test', 'is_abandoned_cart' => 'true', 'product_name_1' => 'Testproduct']],
                'force_opt_in' => false,
            ],
        ]], $this->requests);
    }

    public function testOptsInWithoutAWorkflowWhereTheWelcomeKeepsTheGenericTrigger(): void
    {
        self::assertTrue($this->sender()->send(Trigger::WELCOME, 2, '', 'shopper1@example.test', []));

        self::assertSame('other', $this->requests[0]['host']);
        self::assertSame(['addresses' => [['email' => 'shopper1@example.test']], 'force_opt_in' => false], $this->requests[0]['body']);
    }

    public function testNeverForcesAnOptIn(): void
    {
        $this->sender()->send(Trigger::WELCOME, 1, 'et', 'shopper1@example.test', ['force_opt_in' => true]);

        self::assertFalse($this->requests[0]['body']['force_opt_in']);
        self::assertSame(['email' => 'shopper1@example.test', 'force_opt_in' => false], $this->requests[0]['body']['addresses'][0]);
    }

    public function testAddsTheContactsLanguageWhereTheWorkflowBranchesOnIt(): void
    {
        $this->sender([0 => [StoreLayout::LANGUAGE_MODE => StoreLayout::MODE_BRANCH_ON_LANGUAGE]])
            ->send(Trigger::WELCOME, 1, 'et', 'shopper1@example.test', []);
        $this->sender()->send(Trigger::WELCOME, 1, 'et', 'shopper2@example.test', []);

        self::assertSame(['email' => 'shopper1@example.test', 'language' => 'et'], $this->requests[0]['body']['addresses'][0]);
        self::assertSame(['email' => 'shopper2@example.test'], $this->requests[1]['body']['addresses'][0]);
    }

    public function testANoMatchIsASkipWithItsReasonAndSendsNothing(): void
    {
        $outcome = $this->sender()->send(Trigger::FIRST_ORDER, 1, 'et', 'shopper1@example.test', []);

        self::assertEquals(new Skipped(Router::SKIPPED_AUTOMATION_OFF), $outcome);
        self::assertSame([], $this->requests);
    }

    public function testAFailedSendIsTheQueuesFailureOfIt(): void
    {
        $this->answer = static fn (): Response => new Response(200, '{"code": 203, "message": "Invalid data"}');
        $outcome = $this->sender()->send(Trigger::WELCOME, 1, 'et', 'not-an-address', []);
        self::assertInstanceOf(Failure::class, $outcome);
        self::assertTrue($outcome->isPermanent());

        $this->answer = static fn (): Response => new Response(429, '', ['retry-after' => ['120']]);
        $outcome = $this->sender()->send(Trigger::WELCOME, 1, 'et', 'shopper1@example.test', []);
        self::assertFalse($outcome->isPermanent());
        self::assertSame(120, $outcome->retryAfter());
    }

    /**
     * Acceptance E: no outcome names the contact or quotes the request or Smaily's answer.
     */
    public function testNoOutcomeNamesTheContact(): void
    {
        $reasons = [];
        $answers = [
            static fn (): Response => new Response(200, '{"code": 203, "message": "Invalid: shopper1@example.test Testfirst"}'),
            static fn (): Response => new Response(400, '{"message": "Testfirst shopper1@example.test"}'),
            static fn (): Response => new Response(503, 'Testfirst shopper1@example.test'),
            static function (): Response {
                throw NetworkException::of(new \RuntimeException('Could not resolve https://shared.sendsmaily.net/api/autoresponder.php?email=shopper1%40example.test'));
            },
        ];
        foreach ($answers as $answer) {
            $this->answer = $answer;
            foreach ([[Trigger::WELCOME, 1], [Trigger::FIRST_ORDER, 1], [Trigger::WELCOME, 3]] as [$trigger, $idShop]) {
                $outcome = $this->sender()->send($trigger, $idShop, 'et', 'shopper1@example.test', ['first_name' => 'Testfirst']);
                self::assertTrue($outcome instanceof Failure || $outcome instanceof Skipped);
                $reasons[] = $outcome->reason();
            }
        }

        foreach ($reasons as $reason) {
            foreach (['shopper1', 'example.test', 'Testfirst', '{'] as $quoted) {
                self::assertStringNotContainsString($quoted, $reason);
            }
        }
    }

    /**
     * @param array<int, array<string, string>> $layout
     */
    private function sender(array $layout = []): TriggerSender
    {
        $accounts = [
            0 => new Account(0, '', 'shared', 'shared-user', 'not-a-real-password'),
            2 => new Account(2, '', 'other', 'other-user', 'not-a-real-password'),
        ];
        $router = new Router(
            new FakeAutomationSettings([0 => [AutomationSettings::WELCOME_ENABLED => '1', AutomationSettings::CART_ENABLED => '1']]),
            new FakeStoreLayout($layout),
            new FakeMappingRepository([
                [0, Trigger::WELCOME, '', 'default', 101],
                [0, Trigger::ABANDONED_CART, '', 'default', 102],
                [2, Trigger::WELCOME, '', 'default', null, true],
            ]),
            static fn (int $idShop, string $language): ?Account => $language === '' ? $accounts[$idShop] ?? null : null
        );

        return new TriggerSender($router, $this->transport());
    }

    private function transport(): Transport
    {
        $test = $this;

        return new class($test) implements Transport {
            private TriggerSenderTest $test;

            public function __construct(TriggerSenderTest $test)
            {
                $this->test = $test;
            }

            public function request(string $method, string $url, array $options = []): Response
            {
                return $this->test->answer($method, (string) parse_url($url, PHP_URL_HOST), $options['auth_basic'], $options['json']);
            }
        };
    }

    /**
     * Records a request the fake transport received and answers it.
     *
     * @param array{string, string} $auth
     * @param array<string, mixed> $body
     */
    public function answer(string $method, string $host, array $auth, array $body): Response
    {
        $this->requests[] = ['method' => $method, 'host' => explode('.', $host)[0], 'auth' => $auth, 'body' => $body];

        return ($this->answer)();
    }
}
