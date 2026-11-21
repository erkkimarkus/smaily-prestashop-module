<?php
declare(strict_types=1);

namespace Tests\Unit\Queue\Handler;

use PHPUnit\Framework\TestCase;
use PrestaShop\Module\SmailyForPrestaShop\Automation\Router;
use PrestaShop\Module\SmailyForPrestaShop\Automation\Trigger;
use PrestaShop\Module\SmailyForPrestaShop\Automation\TriggerSender;
use PrestaShop\Module\SmailyForPrestaShop\Config\AutomationSettings;
use PrestaShop\Module\SmailyForPrestaShop\Discount\AutomationDiscount;
use PrestaShop\Module\SmailyForPrestaShop\Discount\CodeIssuer;
use PrestaShop\Module\SmailyForPrestaShop\Discount\TemplateRules;
use PrestaShop\Module\SmailyForPrestaShop\Http\Response;
use PrestaShop\Module\SmailyForPrestaShop\Http\Transport;
use PrestaShop\Module\SmailyForPrestaShop\Queue\EventQueue;
use PrestaShop\Module\SmailyForPrestaShop\Queue\Failure;
use PrestaShop\Module\SmailyForPrestaShop\Queue\Handler\AutomationHandler;
use PrestaShop\Module\SmailyForPrestaShop\Queue\Skipped;
use PrestaShop\Module\SmailyForPrestaShop\Scope\StoreLayout;
use PrestaShop\Module\SmailyForPrestaShop\Smaily\Account;
use Tests\Unit\Automation\FakeAutomationSettings;
use Tests\Unit\Automation\FakeMappingRepository;
use Tests\Unit\Scope\FakeStoreLayout;

require_once __DIR__ . '/../../Automation/FakeAutomationSettings.php';
require_once __DIR__ . '/../../Automation/FakeMappingRepository.php';
require_once __DIR__ . '/../../Scope/FakeStoreLayout.php';

/**
 * The handler of `automation.welcome` and `automation.first_order` rows sends each row on its own
 * through the trigger sender, in the row's shop and language, and gives back the sender's outcome.
 *
 * Shop 1 reads the shared setup: welcome workflow 101 and first-order workflow 103 through the
 * shared account. Shop 2 has the welcome on and no workflow; shop 3 has the welcome off. Shops 3
 * and 4 send a discount code with the welcome; shop 4 has the welcome workflow 105.
 */
final class AutomationHandlerTest extends TestCase
{
    /** @var list<array{host: string, user: string, body: array<string, mixed>}> */
    private array $requests = [];

    /** @var array<int, array<string, string>> */
    private array $layout = [];

    /** @var callable(): Response */
    private $answer;

    protected function setUp(): void
    {
        $this->answer = static fn (): Response => new Response(200, '{"code": 101, "message": "OK"}');
    }

    public function testSendsEachRowInItsShopAndLanguageWithItsFields(): void
    {
        $outcomes = $this->handler(Trigger::WELCOME)->handle([
            self::row(7, 1, 'et', ['email' => 'handler1@example.test', 'fields' => ['store' => 'shop.example.test', 'welcome_automation_at' => '2026-11-20 10:00:00']]),
            self::row(8, 1, null, ['email' => 'handler2@example.test', 'fields' => []]),
        ]);

        self::assertSame([7 => true, 8 => true], $outcomes);
        self::assertSame([
            ['host' => 'shared', 'user' => 'shared-user', 'body' => [
                'autoresponder' => 101,
                'addresses' => [['email' => 'handler1@example.test', 'store' => 'shop.example.test', 'welcome_automation_at' => '2026-11-20 10:00:00']],
                'force_opt_in' => false,
            ]],
            ['host' => 'shared', 'user' => 'shared-user', 'body' => [
                'autoresponder' => 101,
                'addresses' => [['email' => 'handler2@example.test']],
                'force_opt_in' => false,
            ]],
        ], $this->requests);
    }

    public function testTheFirstOrderRowGoesToTheFirstOrderWorkflow(): void
    {
        $outcomes = $this->handler(Trigger::FIRST_ORDER)->handle([
            self::row(7, 1, 'et', ['email' => 'handler1@example.test', 'fields' => ['order_id' => 'ABCDEFGHI', 'first_order_automation_at' => '2026-11-20 10:00:00']]),
        ]);

        self::assertSame([7 => true], $outcomes);
        self::assertSame(103, $this->requests[0]['body']['autoresponder']);
        self::assertSame(['email' => 'handler1@example.test', 'order_id' => 'ABCDEFGHI', 'first_order_automation_at' => '2026-11-20 10:00:00'], $this->requests[0]['body']['addresses'][0]);
    }

    public function testTheContactsLanguageOfTheRowGoesWhereTheWorkflowBranchesOnIt(): void
    {
        $this->layout = [0 => [StoreLayout::LANGUAGE_MODE => StoreLayout::MODE_BRANCH_ON_LANGUAGE]];

        $this->handler(Trigger::WELCOME)->handle([self::row(7, 1, 'et', ['email' => 'handler1@example.test', 'fields' => []])]);

        self::assertSame(['email' => 'handler1@example.test', 'language' => 'et'], $this->requests[0]['body']['addresses'][0]);
    }

    /**
     * In separate stores shop 2 reads only its own rows (a first-order workflow, no account of its
     * own), and shop 3 has the welcome off.
     */
    public function testTheRoutersSkipsPassThroughWithTheirReasons(): void
    {
        $this->layout = [0 => [StoreLayout::SEPARATE_STORES => '1']];

        $welcome = $this->handler(Trigger::WELCOME)->handle([
            self::row(7, 2, 'et', ['email' => 'handler1@example.test', 'fields' => []]),
            self::row(8, 3, 'et', ['email' => 'handler1@example.test', 'fields' => []]),
        ]);
        $firstOrder = $this->handler(Trigger::FIRST_ORDER)->handle([self::row(9, 2, 'et', ['email' => 'handler1@example.test', 'fields' => []])]);

        self::assertEquals([7 => new Skipped(Router::SKIPPED_NO_WORKFLOW), 8 => new Skipped(Router::SKIPPED_AUTOMATION_OFF)], $welcome);
        self::assertEquals([9 => new Skipped(Router::SKIPPED_NO_ACCOUNT)], $firstOrder);
        self::assertSame([], $this->requests, 'nothing goes through another shop\'s account');
    }

    public function testAFailedSendIsTheRowsFailure(): void
    {
        $this->answer = static fn (): Response => new Response(503, 'handler1@example.test');

        $outcomes = $this->handler(Trigger::WELCOME)->handle([self::row(7, 1, 'et', ['email' => 'handler1@example.test', 'fields' => []])]);

        self::assertInstanceOf(Failure::class, $outcomes[7]);
        self::assertFalse($outcomes[7]->isPermanent());
        self::assertStringNotContainsString('handler1', $outcomes[7]->reason());
    }

    public function testASentRowTellsItsRequestAndTheAnswerAndASkippedRowNothing(): void
    {
        $handler = $this->handler(Trigger::WELCOME);
        $handler->handle([
            self::row(7, 1, 'et', ['email' => 'handler1@example.test', 'fields' => ['store' => 'shop.example.test']]),
            self::row(8, 3, 'et', ['email' => 'handler2@example.test', 'fields' => []]),
        ]);

        self::assertSame([7 => [
            'request' => ['autoresponder' => 101, 'addresses' => [['email' => 'handler1@example.test', 'store' => 'shop.example.test']], 'force_opt_in' => false],
            'response' => ['http_status' => 200, 'body' => ['code' => 101]],
        ]], $handler->exchanges());

        $this->answer = static fn (): Response => new Response(503, 'Unavailable');
        $handler->handle([self::row(9, 1, 'et', ['email' => 'handler1@example.test', 'fields' => []])]);

        self::assertSame([9], array_keys($handler->exchanges()));
        self::assertSame(['http_status' => 503, 'body' => null], $handler->exchanges()[9]['response']);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public function malformedPayloads(): iterable
    {
        yield 'not JSON' => ['{'];
        yield 'no address' => ['{"fields": {}}'];
        yield 'an empty address' => ['{"email": " ", "fields": {}}'];
        yield 'fields that are no object' => ['{"email": "handler1@example.test", "fields": "x"}'];
        yield 'a field that is no value' => ['{"email": "handler1@example.test", "fields": {"store": ["x"]}}'];
    }

    /**
     * @dataProvider malformedPayloads
     */
    public function testAMalformedPayloadFailsForGoodWithoutNamingTheContact(string $payload): void
    {
        $row = self::row(7, 1, 'et', []);
        $row['payload'] = $payload;

        $outcome = $this->handler(Trigger::WELCOME)->handle([$row])[7];

        self::assertInstanceOf(Failure::class, $outcome);
        self::assertTrue($outcome->isPermanent());
        self::assertSame(AutomationHandler::FAILED_MALFORMED_PAYLOAD, $outcome->reason());
        self::assertSame([], $this->requests);
    }

    /**
     * Records a request the fake transport received and answers it.
     *
     * @param array<string, mixed> $body
     */
    public function answer(string $host, string $user, array $body): Response
    {
        $this->requests[] = ['host' => $host, 'user' => $user, 'body' => $body];

        return ($this->answer)();
    }

    public function testARowThatIsSkippedAsksForNoDiscountCode(): void
    {
        $outcomes = $this->handler(Trigger::WELCOME)->handle([self::row(7, 3, 'et', ['email' => 'handler7@example.test', 'fields' => []])]);

        self::assertInstanceOf(Skipped::class, $outcomes[7], 'the router skips before a code is made');
        self::assertSame([], $this->requests);
    }

    public function testARowThatCarriesItsDiscountCodeSendsItAgainAndAsksForNoOther(): void
    {
        $fields = ['welcome_coupon_code' => 'ABCDEFGH23', 'welcome_coupon_expires_at' => '2026-11-27'];

        $outcomes = $this->handler(Trigger::WELCOME)->handle([self::row(7, 4, 'et', ['email' => 'handler8@example.test', 'fields' => $fields])]);

        self::assertSame([7 => true], $outcomes);
        self::assertSame([['email' => 'handler8@example.test'] + $fields], $this->requests[0]['body']['addresses']);
    }

    private function handler(string $trigger): AutomationHandler
    {
        $accounts = [0 => new Account(0, '', 'shared', 'shared-user', 'not-a-real-password')];
        $settings = new FakeAutomationSettings([
            0 => [AutomationSettings::WELCOME_ENABLED => '1', AutomationSettings::FIRST_ORDER_ENABLED => '1'],
            3 => [AutomationSettings::WELCOME_ENABLED => '0', AutomationSettings::WELCOME_DISCOUNT_ENABLED => '1', AutomationSettings::WELCOME_DISCOUNT_RULE => '5'],
            4 => [AutomationSettings::WELCOME_DISCOUNT_ENABLED => '1', AutomationSettings::WELCOME_DISCOUNT_RULE => '5'],
        ]);
        $router = new Router(
            $settings,
            new FakeStoreLayout($this->layout),
            new FakeMappingRepository([
                [0, Trigger::WELCOME, '', 'default', 101],
                [0, Trigger::FIRST_ORDER, '', 'default', 103],
                [2, Trigger::FIRST_ORDER, '', 'default', 104],
                [4, Trigger::WELCOME, '', 'default', 105],
            ]),
            static fn (int $idShop, string $language): ?Account => $language === '' ? $accounts[$idShop] ?? null : null
        );
        $test = $this;
        $transport = new class($test) implements Transport {
            private AutomationHandlerTest $test;

            public function __construct(AutomationHandlerTest $test)
            {
                $this->test = $test;
            }

            public function request(string $method, string $url, array $options = []): Response
            {
                return $this->test->answer(explode('.', (string) parse_url($url, PHP_URL_HOST))[0], $options['auth_basic'][0], $options['json']);
            }
        };

        return new AutomationHandler($trigger, new TriggerSender($router, $transport), new AutomationDiscount($settings, new CodeIssuer(new TemplateRules()), new EventQueue()));
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    private static function row(int $id, int $idShop, ?string $language, array $payload): array
    {
        return ['id' => (string) $id, 'id_shop' => (string) $idShop, 'language' => $language, 'payload' => json_encode($payload)];
    }
}
