<?php
declare(strict_types=1);

namespace Tests\Unit\Engine\Automations;

use PHPUnit\Framework\TestCase;
use PrestaShop\Module\SmailyForPrestaShop\Engine\Automations\AutomationList;

/**
 * The list of the Automations tab's card is the engine's catalogue of that moment (contract §11)
 * with the store's rows (§12): no automation is known by its key, one without a row is off and in
 * test mode, and its name and description are the engine's, in the employee's language.
 */
final class AutomationListTest extends TestCase
{
    private const CATALOG = [
        'triggers' => [
            ['key' => 'replenish_due', 'name_et' => 'Taastäitumine', 'name_en' => 'Replenishment due', 'description_et' => 'Kirjeldus', 'description_en' => 'Description'],
            ['key' => 'an_email_of_tomorrow', 'name_en' => 'An email of tomorrow', 'description_en' => 'Added by an engine deploy.'],
        ],
        'language_modes' => ['single', 'per_language'],
    ];

    public function testEveryCatalogueEmailIsListedInItsOrderAndNothingElse(): void
    {
        $rows = AutomationList::rows(self::CATALOG, ['configs' => [['trigger_key' => 'no_longer_offered', 'enabled' => true]]], 'en');

        self::assertSame(['replenish_due', 'an_email_of_tomorrow'], array_column($rows, 'key'));
        self::assertSame(['Replenishment due', 'An email of tomorrow'], array_column($rows, 'name'));
        self::assertSame(['Description', 'Added by an engine deploy.'], array_column($rows, 'description'));
    }

    public function testAnEmailWithoutARowIsOffAndInTestModeWithTheDefaults(): void
    {
        $row = AutomationList::rows(self::CATALOG, ['configs' => []], 'en')[0];

        self::assertFalse($row['enabled']);
        self::assertSame('off', $row['mode']);
        self::assertNull($row['setup'], 'an answer without setup[] shows no Test mode switch');
        self::assertSame('', $row['workflow_id']);
        self::assertSame('single', $row['language_mode']);
        self::assertSame([], $row['original_map']);
        self::assertSame(7, $row['cooldown_days']);
        self::assertNull($row['daily_cap']);
        self::assertSame('', $row['test_emails']);
    }

    public function testAStoredRowShowsAsTheEngineHoldsIt(): void
    {
        $row = AutomationList::rows(self::CATALOG, ['configs' => [[
            'trigger_key' => 'replenish_due',
            'enabled' => true,
            'language_mode' => 'single',
            'automation_map' => ['id' => '21'],
            'cooldown_days' => 30,
            'daily_cap' => 10,
            'test_mode' => false,
            'test_emails' => ['owner@shop.example', 'marketing@shop.example'],
            'configured_via' => 'plugin',
        ]]], 'en')[0];

        self::assertTrue($row['enabled']);
        self::assertSame('active', $row['mode']);
        self::assertSame('21', $row['workflow_id']);
        self::assertSame(['id' => '21'], $row['original_map']);
        self::assertSame(30, $row['cooldown_days']);
        self::assertSame(10, $row['daily_cap']);
        self::assertSame('owner@shop.example, marketing@shop.example', $row['test_emails']);
    }

    public function testAnEnabledRowWithoutAStoredTestModeIsInTestMode(): void
    {
        $row = AutomationList::rows(self::CATALOG, ['configs' => [['trigger_key' => 'replenish_due', 'enabled' => true]]], 'en')[0];

        self::assertSame('test', $row['mode']);
    }

    public function testTheSetupItemDecidesTheStateOfAnEmail(): void
    {
        $config = [
            'configs' => [['trigger_key' => 'replenish_due', 'enabled' => true, 'test_mode' => true]],
            'setup' => [
                ['trigger_key' => 'replenish_due', 'setup_state' => 'live', 'daily_limit' => 25, 'go_live_blockers' => [], 'notice' => 'starts_when_sender_verified'],
                ['trigger_key' => 'an_email_of_tomorrow', 'setup_state' => 'set_up', 'daily_limit' => 10, 'notice' => 'a_notice_of_tomorrow'],
            ],
        ];

        $rows = AutomationList::rows(self::CATALOG, $config, 'en');

        self::assertSame('active', $rows[0]['mode'], 'the setup item wins over the stored row');
        self::assertSame(['test_mode' => false, 'daily_limit' => 25, 'stopped' => false, 'notice' => 'starts_when_sender_verified'], $rows[0]['setup']);
        self::assertSame('off', $rows[1]['mode']);
        self::assertSame(['test_mode' => true, 'daily_limit' => null, 'stopped' => false, 'notice' => ''], $rows[1]['setup'], 'an unknown notice shows nothing, and only a live email has a limit');
    }

    /**
     * @dataProvider setupStates
     *
     * @param array<string, mixed> $item
     */
    public function testEveryStateButLiveIsTestMode(array $item, string $mode, bool $testMode, bool $stopped): void
    {
        $state = AutomationList::states(['setup' => [$item + ['trigger_key' => 'replenish_due']]])['replenish_due'];

        self::assertSame($mode, $state['mode']);
        self::assertSame($testMode, $state['test_mode']);
        self::assertSame($stopped, $state['stopped']);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string, bool, bool}>
     */
    public static function setupStates(): iterable
    {
        yield 'off' => [['setup_state' => 'off'], 'off', true, false];
        yield 'set up' => [['setup_state' => 'set_up'], 'off', true, false];
        yield 'test' => [['setup_state' => 'test'], 'test', true, false];
        yield 'live' => [['setup_state' => 'live'], 'active', false, false];
        yield 'stopped' => [['setup_state' => 'stopped', 'go_live_blockers' => ['stopped_needs_review']], 'test', true, true];
        yield 'a stop that waits in test' => [['setup_state' => 'test', 'go_live_blockers' => ['stopped_needs_review']], 'test', true, true];
        yield 'unknown state' => [['setup_state' => 'paused'], 'off', true, false];
    }

    public function testAPerLanguageRowOfAnotherConnectorShowsItsFallbackWorkflow(): void
    {
        $map = ['et' => '31', 'en' => '32', 'fallback' => '33'];
        $row = AutomationList::rows(self::CATALOG, ['configs' => [[
            'trigger_key' => 'replenish_due',
            'enabled' => true,
            'language_mode' => 'per_language',
            'automation_map' => $map,
        ]]], 'en')[0];

        self::assertSame('per_language', $row['language_mode']);
        self::assertSame('33', $row['workflow_id']);
        self::assertSame($map, $row['original_map']);
    }

    public function testTheTextsAreInTheEmployeesLanguageElseInEnglishElseTheKey(): void
    {
        $catalog = ['triggers' => [
            ['key' => 'replenish_due', 'name_et' => 'Taastäitumine', 'name_en' => 'Replenishment due', 'description_et' => ' ', 'description_en' => 'Description'],
            ['key' => 'nameless'],
        ]];

        $estonian = AutomationList::rows($catalog, [], 'et');
        $finnish = AutomationList::rows($catalog, [], 'fi');

        self::assertSame('Taastäitumine', $estonian[0]['name']);
        self::assertSame('Description', $estonian[0]['description'], 'a blank text falls back to English');
        self::assertSame('Replenishment due', $finnish[0]['name']);
        self::assertSame('nameless', $estonian[1]['name']);
        self::assertSame('', $estonian[1]['description']);
    }

    public function testAnEntryWithoutAPlainKeyIsLeftOut(): void
    {
        $rows = AutomationList::rows(['triggers' => [
            ['name_en' => 'No key'],
            ['key' => 'a key"><script>', 'name_en' => 'Not a key'],
            ['key' => 17],
            'not an entry',
            ['key' => 'life_stage'],
        ]], [], 'en');

        self::assertSame(['life_stage'], array_column($rows, 'key'));
    }

    public function testAnAnswerOfAnotherShapeListsNothing(): void
    {
        self::assertSame([], AutomationList::rows([], [], 'en'));
        self::assertSame([], AutomationList::rows(['triggers' => 'none'], ['configs' => 'none'], 'en'));
    }
}
