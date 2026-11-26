<?php
declare(strict_types=1);

namespace Tests\Integration\Engine;

use PrestaShop\Module\SmailyForPrestaShop\Admin\Settings\EngineAutomationsPanel;
use PrestaShop\Module\SmailyForPrestaShop\Admin\Settings\SettingsPanels;
use PrestaShop\Module\SmailyForPrestaShop\Automation\WorkflowList;
use PrestaShop\Module\SmailyForPrestaShop\Engine\Automations\EngineAutomations;
use PrestaShop\Module\SmailyForPrestaShop\Engine\Client;
use PrestaShop\Module\SmailyForPrestaShop\Engine\HealthCheck;
use PrestaShop\Module\SmailyForPrestaShop\Http\NetworkException;
use PrestaShop\Module\SmailyForPrestaShop\Http\Response;
use PrestaShop\Module\SmailyForPrestaShop\Http\Transport;
use PrestaShop\Module\SmailyForPrestaShop\Scope\StoreLayout;
use PrestaShop\Module\SmailyForPrestaShop\Smaily\AccountStore;
use Tests\Integration\Fixtures\EngineMockServer;
use Tests\Integration\Fixtures\SmailyMockServer;

require_once __DIR__ . '/EngineTestCase.php';
require_once __DIR__ . '/../Fixtures/SmailyMockServer.php';

/**
 * The card "Campaign Intelligence Automations" against the engine mock (contract §11 to §13) and
 * the Smaily mock: it lists what the engine offers at that moment, shows after a save what the
 * engine holds, refuses an enabled automation without a workflow, leaves another connector's
 * per-language setup as it is, and saves nothing while the list cannot be read or the account is
 * not active. No sentence of the card carries the engine's text.
 */
final class EngineAutomationsTest extends EngineTestCase
{
    private const CONFIG_PATH = '/api/v1/automations/config';

    private const TOMORROW = ['key' => 'an_email_of_tomorrow', 'name_en' => 'An email of tomorrow', 'description_en' => 'Added by an engine deploy.'];

    private SmailyMockServer $smaily;

    /** @var string|false */
    private $savedLanguageMode = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->savedLanguageMode = \Configuration::getGlobalValue(StoreLayout::LANGUAGE_MODE);
        \Configuration::deleteByName(StoreLayout::LANGUAGE_MODE);
        $this->smaily = SmailyMockServer::start();
        $this->smaily->acceptCredentials('api-user', 'not-a-real-password');
        $this->smaily->setWorkflows(array_map(
            static fn (int $id): array => ['id' => $id, 'title' => 'Workflow ' . $id, 'trigger_type' => 'form_submitted', 'is_enabled' => true],
            [21, 24]
        ));
        $engine = $this->engine->transport();
        $smaily = $this->smaily->transport();
        $this->services->set(Transport::class, static fn (): Transport => new class($engine, $smaily) implements Transport {
            private Transport $engine;

            private Transport $smaily;

            public function __construct(Transport $engine, Transport $smaily)
            {
                $this->engine = $engine;
                $this->smaily = $smaily;
            }

            public function request(string $method, string $url, array $options = []): Response
            {
                if (strpos($url, EngineMockServer::ENGINE . '/') === 0) {
                    return $this->engine->request($method, $url, $options);
                }
                if (preg_match('~^https://[a-z0-9-]+\.sendsmaily\.net/~', $url) === 1) {
                    return $this->smaily->request($method, $url, $options);
                }

                throw NetworkException::of(new \RuntimeException('The test transport reaches the two mocks only.'));
            }
        });
        $this->engine->set('smaily_subdomain', 'demo');
        $this->storeAccount(0, 'demo');
    }

    protected function tearDown(): void
    {
        if (defined('_PS_ROOT_DIR_')) {
            \Configuration::deleteByName(StoreLayout::LANGUAGE_MODE);
            \Configuration::deleteByName(StoreLayout::FALLBACK_LANGUAGE);
            if ($this->savedLanguageMode !== false) {
                \Configuration::updateGlobalValue(StoreLayout::LANGUAGE_MODE, (string) $this->savedLanguageMode);
            }
        }
        parent::tearDown();
    }

    public function testWithoutAConnectionTheCardSaysSoAndAsksNothing(): void
    {
        $card = $this->panel()->card(0, 'en');
        $save = $this->panel()->save(0, ['replenish_due' => ['enabled' => true, 'workflow_id' => '21']], 'en');

        self::assertSame('not_connected', $card['state']);
        self::assertSame([], $card['rows']);
        self::assertFalse($save['saved']);
        self::assertSame('card_changed', $save['code']);
        self::assertSame([], $this->engine->requests());
    }

    public function testTheCardListsWhatTheEngineOffersNowEveryAutomationOff(): void
    {
        $this->connect();

        $card = $this->panel()->card(0, 'en');

        self::assertSame('ready', $card['state']);
        self::assertSame(['replenish_due', 'winback_rescue'], array_column($card['rows'], 'key'));
        self::assertSame(['Replenishment due', 'Win-back'], array_column($card['rows'], 'name'));
        self::assertSame([false, false], array_column($card['rows'], 'enabled'));
        self::assertSame([true, true], array_column($card['rows'], 'test_mode'));
        self::assertSame([['id' => 21, 'name' => 'Workflow 21'], ['id' => 24, 'name' => 'Workflow 24']], $card['workflows']);
        self::assertSame('', $card['workflow_error']);
        self::assertSame('', $card['account'], 'the shop\'s own account is not named');
    }

    public function testAnAutomationThatTheEngineAddsLaterIsListedAtTheNextRead(): void
    {
        $this->connect();
        self::assertCount(2, $this->panel()->card(0, 'en')['rows']);

        $this->engine->set('automations', [['key' => 'replenish_due', 'name_en' => 'Replenishment due'], self::TOMORROW]);
        $card = $this->panel()->card(0, 'et');

        self::assertSame(['replenish_due', 'an_email_of_tomorrow'], array_column($card['rows'], 'key'));
        self::assertSame('An email of tomorrow', $card['rows'][1]['name'], 'English where the engine gives no Estonian');
        self::assertSame('Added by an engine deploy.', $card['rows'][1]['description']);
    }

    public function testTheNamesAreInTheEmployeesLanguage(): void
    {
        $this->connect();

        self::assertSame(['Taastäitumine', 'Tagasivõitmine'], array_column($this->panel()->card(0, 'et')['rows'], 'name'));
    }

    public function testASaveSendsEveryRowWithAllEightKeysAndShowsWhatTheEngineHolds(): void
    {
        $this->connect();

        $result = $this->panel()->save(0, [
            'replenish_due' => self::posted(['enabled' => true, 'test_mode' => true, 'workflow_id' => '21', 'cooldown_days' => '30', 'daily_cap' => '10', 'test_emails' => 'owner@shop.example, marketing@shop.example']),
            'winback_rescue' => self::posted(),
        ], 'en');

        self::assertTrue($result['saved']);
        self::assertSame('Saved.', $result['message']);
        $puts = $this->puts();
        self::assertCount(1, $puts);
        self::assertSame([
            [
                'trigger_key' => 'replenish_due',
                'enabled' => true,
                'language_mode' => 'single',
                'automation_map' => ['id' => '21'],
                'cooldown_days' => 30,
                'daily_cap' => 10,
                'test_mode' => true,
                'test_emails' => ['owner@shop.example', 'marketing@shop.example'],
            ],
            [
                'trigger_key' => 'winback_rescue',
                'enabled' => false,
                'language_mode' => 'single',
                'automation_map' => [],
                'cooldown_days' => 7,
                'daily_cap' => null,
                'test_mode' => true,
                'test_emails' => [],
            ],
        ], $puts[0]['body']['configs']);
        self::assertStringContainsString('"automation_map":{}', $puts[0]['raw'], 'an empty map is an object');
        self::assertSame([true, false], array_column($result['card']['rows'], 'enabled'));
        self::assertSame('21', $result['card']['rows'][0]['workflow_id']);
        self::assertSame(30, $result['card']['rows'][0]['cooldown_days']);
    }

    public function testARequestForRealSendsShowsTheTestModeThatTheEngineKeeps(): void
    {
        $this->connect();

        $result = $this->panel()->save(0, ['replenish_due' => self::posted(['enabled' => true, 'test_mode' => false, 'workflow_id' => '21'])], 'en');

        self::assertTrue($result['saved']);
        self::assertFalse($this->puts()[0]['body']['configs'][0]['test_mode'], 'the request asked for real sends');
        self::assertTrue($result['card']['rows'][0]['enabled']);
        self::assertTrue($result['card']['rows'][0]['test_mode'], 'the page shows the engine\'s state');
        self::assertSame('Saved. Smaily switches real sends on after you confirm. Until then the trigger runs in test mode.', $result['message']);
    }

    public function testAnAutomationWhoseRealSendsAreOnStaysActiveAtASave(): void
    {
        $connection = $this->connect();
        $this->panel()->save(0, ['replenish_due' => self::posted(['enabled' => true, 'test_mode' => true, 'workflow_id' => '21'])], 'en');
        $this->engine->set('automations_live', [$connection->tenantId() => ['replenish_due']]);

        $result = $this->panel()->save(0, ['replenish_due' => self::posted(['enabled' => true, 'test_mode' => false, 'workflow_id' => '21'])], 'en');

        self::assertFalse($result['card']['rows'][0]['test_mode']);
        self::assertSame('Saved.', $result['message']);
    }

    public function testAnEnabledAutomationWithoutAWorkflowIsNotSaved(): void
    {
        $this->connect();

        $result = $this->panel()->save(0, [
            'replenish_due' => self::posted(['enabled' => true, 'test_mode' => true]),
            'winback_rescue' => self::posted(['enabled' => true, 'test_mode' => true, 'workflow_id' => '24']),
        ], 'en');

        self::assertFalse($result['saved']);
        self::assertSame('invalid_fields', $result['code']);
        self::assertSame([['field' => 'replenish_due.workflow_id', 'message' => 'A workflow is required when this trigger is enabled.']], $result['fields']);
        self::assertSame('1 automation(s) could not be saved. Pick a Smaily workflow for every enabled trigger, then save again.', $result['message']);
        self::assertSame([], $this->puts(), 'no row of the save was sent');
    }

    public function testASaveLeavesAPerLanguageSetupOfAnotherConnectorAsItIs(): void
    {
        $this->connect();
        $map = ['et' => '31', 'en' => '32', 'fallback' => '24'];
        $this->putAsAnotherConnector(['trigger_key' => 'winback_rescue', 'enabled' => true, 'language_mode' => 'per_language', 'automation_map' => $map, 'cooldown_days' => 14, 'daily_cap' => null, 'test_mode' => true, 'test_emails' => []]);

        $card = $this->panel()->card(0, 'en');
        $row = $card['rows'][1];
        self::assertSame(['per_language', '24', $map], [$row['language_mode'], $row['workflow_id'], $row['original_map']]);

        $result = $this->panel()->save(0, [
            'replenish_due' => self::posted(['enabled' => true, 'test_mode' => true, 'workflow_id' => '21']),
            'winback_rescue' => self::posted(['enabled' => true, 'test_mode' => true, 'workflow_id' => '24', 'cooldown_days' => '14', 'language_mode' => 'per_language', 'original_map' => $map]),
        ], 'en');

        self::assertTrue($result['saved']);
        $sent = $this->puts()[1]['body']['configs'][1];
        self::assertSame(['per_language', $map], [$sent['language_mode'], $sent['automation_map']]);
        $stored = $this->panel()->card(0, 'en')['rows'][1];
        self::assertSame(['per_language', $map], [$stored['language_mode'], $stored['original_map']]);
    }

    public function testAStoredWorkflowStaysWhileTheSmailyListCannotBeLoaded(): void
    {
        $this->connect();
        $this->panel()->save(0, ['replenish_due' => self::posted(['enabled' => true, 'test_mode' => true, 'workflow_id' => '21'])], 'en');
        $this->smaily->acceptCredentials('another-user', 'another-password');

        $card = $this->panel()->card(0, 'en');
        $result = $this->panel()->save(0, ['replenish_due' => self::posted(['enabled' => true, 'test_mode' => true, 'workflow_id' => '', 'original_map' => ['id' => '21']])], 'en');

        self::assertSame([], $card['workflows']);
        self::assertStringStartsWith('Could not load the workflow list: Smaily rejected these credentials.', $card['workflow_error']);
        self::assertSame('21', $card['rows'][0]['workflow_id']);
        self::assertTrue($result['saved']);
        self::assertSame(['id' => '21'], $this->puts()[1]['body']['configs'][0]['automation_map']);
    }

    public function testAFieldThatTheEngineRefusesIsSaidInTheModulesWords(): void
    {
        $this->connect();

        $result = $this->panel()->save(0, [
            'replenish_due' => self::posted(['enabled' => true, 'test_mode' => true, 'workflow_id' => '21', 'test_emails' => 'owner@shop.example, not-an-address']),
            'no_longer_offered' => self::posted(),
        ], 'en');

        self::assertFalse($result['saved']);
        self::assertSame('invalid_fields', $result['code']);
        self::assertSame('Campaign Intelligence did not accept the marked fields. Nothing was saved.', $result['message']);
        self::assertSame([
            ['field' => 'replenish_due.test_emails', 'message' => 'Campaign Intelligence did not accept these test emails. Enter email addresses separated by commas, at most 50.'],
            ['field' => 'no_longer_offered.enabled', 'message' => 'Campaign Intelligence did not accept this automation. Reload the page and try again.'],
        ], $result['fields']);
        self::assertStringNotContainsString('Invalid email', (string) json_encode($result));
        self::assertStringNotContainsString('tundmatu', (string) json_encode($result));
        self::assertSame([], $this->panel()->card(0, 'en')['rows'][0]['original_map'], 'nothing was saved');
    }

    public function testAWorkflowMapThatTheEngineRefusesMarksTheWorkflow(): void
    {
        $this->connect();
        $map = ['et' => 'x1', 'fallback' => '24'];

        $result = $this->panel()->save(0, ['winback_rescue' => self::posted(['enabled' => true, 'workflow_id' => '24', 'language_mode' => 'per_language', 'original_map' => $map])], 'en');

        self::assertSame([['field' => 'winback_rescue.workflow_id', 'message' => 'Campaign Intelligence did not accept this workflow. Pick another workflow, then save again.']], $result['fields']);
        self::assertStringNotContainsString('automatsiooni', (string) json_encode($result));
    }

    /**
     * @dataProvider unreadable
     */
    public function testWhileTheListCannotBeReadNothingIsListedAndNoDefaultIsShown(string $route, int $status, ?string $error, string $reason): void
    {
        $this->connect();
        $this->panel()->save(0, ['replenish_due' => self::posted(['enabled' => true, 'test_mode' => true, 'workflow_id' => '21'])], 'en');
        $this->engine->fail($route, $status, $error, 'engine text that no screen shows');

        $card = $this->panel()->card(0, 'en');

        self::assertSame('unavailable', $card['state']);
        self::assertSame($reason, $card['reason']);
        self::assertSame([], $card['rows']);
        self::assertStringNotContainsString('engine text', (string) json_encode($card));

        $this->engine->set('fail', []);
        $row = $this->panel()->card(0, 'en')['rows'][0];
        self::assertSame([true, '21'], [$row['enabled'], $row['workflow_id']], 'nothing was lost');
    }

    /**
     * @return array<string, array{string, int, string|null, string}>
     */
    public function unreadable(): array
    {
        return [
            'the catalogue does not answer' => ['automations_catalog', 503, null, 'Campaign Intelligence did not answer.'],
            'the configuration does not answer' => ['automations_config', 500, null, 'Campaign Intelligence did not answer.'],
            'the catalogue is refused' => ['automations_catalog', 404, 'not_found', 'Campaign Intelligence refused the request (not_found).'],
        ];
    }

    public function testASaveThatTheEngineDoesNotAnswerSavesNothing(): void
    {
        $this->connect();
        $this->engine->fail('automations_save', 503);

        $result = $this->panel()->save(0, ['replenish_due' => self::posted(['enabled' => true, 'test_mode' => true, 'workflow_id' => '21'])], 'en');

        self::assertFalse($result['saved']);
        self::assertSame('not_saved', $result['code']);
        self::assertSame('Nothing was saved: Campaign Intelligence did not answer.', $result['message']);
        $this->engine->set('fail', []);
        self::assertFalse($this->panel()->card(0, 'en')['rows'][0]['enabled']);
    }

    public function testASaveWhoseStateCannotBeReadBackSaysToReload(): void
    {
        $this->connect();
        $this->engine->fail('automations_config', 503);

        $result = $this->panel()->save(0, ['replenish_due' => self::posted(['enabled' => true, 'test_mode' => true, 'workflow_id' => '21'])], 'en');

        self::assertTrue($result['saved']);
        self::assertNull($result['card']);
        self::assertSame('Saved. Reload the page to see the state of each trigger.', $result['message']);
    }

    public function testAnAccountThatIsNotActiveIsNeitherReadNorSaved(): void
    {
        $this->connect();
        $this->engine->set('tenant_inactive', true);

        // The read meets the refusal, and the health check records it with the connection.
        $card = $this->panel()->card(0, 'en');
        self::assertSame('refused', $card['state']);
        self::assertNotSame('', $this->connections()->forShop(0)->refusedAt());

        // From then on the engine is not asked.
        $before = count($this->engine->requests());
        $again = $this->panel()->card(0, 'en');
        $save = $this->panel()->save(0, ['replenish_due' => self::posted(['enabled' => true, 'test_mode' => true, 'workflow_id' => '21'])], 'en');

        self::assertSame('refused', $again['state']);
        self::assertFalse($save['saved']);
        self::assertSame('card_changed', $save['code']);
        self::assertCount($before, $this->engine->requests());
    }

    public function testASaveOnAPageOpenedBeforeTheRefusalSavesNothing(): void
    {
        $this->connect();
        $this->engine->set('tenant_inactive', true);

        $save = $this->panel()->save(0, ['replenish_due' => self::posted(['enabled' => true, 'test_mode' => true, 'workflow_id' => '21'])], 'en');

        self::assertFalse($save['saved']);
        self::assertSame('card_changed', $save['code']);
        self::assertSame('refused', $this->panel()->card(0, 'en')['state']);
    }

    public function testTheCardNamesTheConnectionsAccountWhereItIsNotTheShopsOwn(): void
    {
        $this->connect();
        $layout = $this->services->get(StoreLayout::class);
        $layout->setLanguageMode(StoreLayout::MODE_ACCOUNT_PER_LANGUAGE, 0);
        $this->storeAccount(0, 'another', 'et');
        self::assertSame('', $this->panel()->card(0, 'en')['account'], 'the account of no language is the shop\'s own');

        // With an account per language the shop's own account is the fallback language's (CON-445, T9).
        $layout->setFallbackLanguage('et', 0);
        $card = $this->panel()->card(0, 'en');

        self::assertSame('demo', $card['account']);
        self::assertCount(2, $card['workflows']);
        $lists = array_values(array_filter($this->smaily->requests(), static fn (array $request): bool => $request['path'] === '/api/workflows.php'));
        self::assertCount(2, $lists, 'one list for each read of the card');
    }

    public function testTheWorkflowsComeFromTheLanguageAccountThatTheConnectionBelongsTo(): void
    {
        // The engine store belongs to the Estonian account, not to the account of no language.
        $this->storeAccount(0, 'another');
        $this->storeAccount(0, 'demo', 'et');
        $this->connect();

        $view = $this->automations()->view(0, 'en');

        self::assertSame('demo', $view['account']);
        self::assertNull($view['workflow_error']);
        self::assertCount(2, $view['workflows']);
    }

    private function panel(): EngineAutomationsPanel
    {
        return (new SettingsPanels($this->services, \Context::getContext()->getTranslator()))->engineAutomations();
    }

    private function automations(): EngineAutomations
    {
        return new EngineAutomations(
            $this->services->get(Client::class),
            $this->connections(),
            $this->services->get(HealthCheck::class),
            $this->services->get(AccountStore::class),
            $this->services->get(WorkflowList::class)
        );
    }

    /**
     * A row as the page posts it, with the values of a row that was never saved.
     *
     * @param array<string, mixed> $values
     *
     * @return array<string, mixed>
     */
    private static function posted(array $values = []): array
    {
        return $values + ['enabled' => false, 'test_mode' => true, 'workflow_id' => '', 'cooldown_days' => '7', 'daily_cap' => '', 'test_emails' => '', 'language_mode' => 'single', 'original_map' => []];
    }

    /**
     * Saves a row with the connection's key, as another connector of the same engine store does.
     *
     * @param array<string, mixed> $row
     */
    private function putAsAnotherConnector(array $row): void
    {
        $this->services->get(Client::class)->saveAutomationsConfig($this->connections()->forShop(0), [$row]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function puts(): array
    {
        return array_values(array_filter($this->engine->requestsTo(self::CONFIG_PATH), static fn (array $request): bool => $request['method'] === 'PUT'));
    }
}
