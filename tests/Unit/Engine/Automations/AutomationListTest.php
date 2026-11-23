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
        self::assertTrue($row['test_mode']);
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
        self::assertFalse($row['test_mode']);
        self::assertSame('21', $row['workflow_id']);
        self::assertSame(['id' => '21'], $row['original_map']);
        self::assertSame(30, $row['cooldown_days']);
        self::assertSame(10, $row['daily_cap']);
        self::assertSame('owner@shop.example, marketing@shop.example', $row['test_emails']);
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
