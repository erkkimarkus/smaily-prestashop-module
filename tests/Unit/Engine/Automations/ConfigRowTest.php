<?php
declare(strict_types=1);

namespace Tests\Unit\Engine\Automations;

use PHPUnit\Framework\TestCase;
use PrestaShop\Module\SmailyForPrestaShop\Engine\Automations\ConfigRow;

/**
 * A row of the card goes to the engine with all eight keys of contract §13, and a save never drops
 * a workflow that the page could not show as a choice: a per-language map of another connector,
 * or a workflow that the Smaily list does not offer.
 */
final class ConfigRowTest extends TestCase
{
    private const OFFERED = ['21', '24'];

    public function testARowCarriesEveryKeyButTestMode(): void
    {
        $row = ConfigRow::normalize('replenish_due', [
            'enabled' => true,
            'test_mode' => true,
            'workflow_id' => '21',
            'cooldown_days' => '30',
            'daily_cap' => '10',
            'test_emails' => 'owner@shop.example,  marketing@shop.example ,',
            'language_mode' => 'single',
            'original_map' => [],
        ], self::OFFERED);

        self::assertSame([
            'trigger_key' => 'replenish_due',
            'enabled' => true,
            'language_mode' => 'single',
            'automation_map' => ['id' => '21'],
            'cooldown_days' => 30,
            'daily_cap' => 10,
            'test_emails' => ['owner@shop.example', 'marketing@shop.example'],
        ], $row, 'a posted test_mode is not sent: the Test mode switch is §20, and a save never switches real sends off');
    }

    public function testARowWithNothingPostedIsOffWithTheDefaults(): void
    {
        self::assertSame([
            'trigger_key' => 'winback_rescue',
            'enabled' => false,
            'language_mode' => 'single',
            'automation_map' => [],
            'cooldown_days' => 7,
            'daily_cap' => null,
            'test_emails' => [],
        ], ConfigRow::normalize('winback_rescue', [], self::OFFERED));
    }

    public function testTheNumbersAreKeptInsideTheContractsLimits(): void
    {
        $low = ConfigRow::normalize('k', ['cooldown_days' => '0', 'daily_cap' => '0'], self::OFFERED);
        $high = ConfigRow::normalize('k', ['cooldown_days' => '900', 'daily_cap' => '2000000'], self::OFFERED);

        self::assertSame([1, 1], [$low['cooldown_days'], $low['daily_cap']]);
        self::assertSame([365, 100000], [$high['cooldown_days'], $high['daily_cap']]);
    }

    public function testAtMostFiftyTestAddressesAreSent(): void
    {
        $addresses = implode(',', array_map(static fn (int $n): string => 'tester' . $n . '@shop.example', range(1, 60)));

        self::assertCount(50, ConfigRow::normalize('k', ['test_emails' => $addresses], self::OFFERED)['test_emails']);
    }

    public function testAPerLanguageMapStaysWhileItsFallbackWorkflowIsNotChanged(): void
    {
        $map = ['et' => '31', 'en' => '32', 'fallback' => '21'];

        $row = ConfigRow::normalize('k', ['enabled' => true, 'workflow_id' => '21', 'language_mode' => 'per_language', 'original_map' => $map], self::OFFERED);

        self::assertSame('per_language', $row['language_mode']);
        self::assertSame($map, $row['automation_map']);
    }

    public function testAPerLanguageMapStaysWhileItsFallbackWorkflowIsNotInTheList(): void
    {
        $map = ['et' => '31', 'fallback' => '99'];

        $row = ConfigRow::normalize('k', ['workflow_id' => '', 'language_mode' => 'per_language', 'original_map' => $map], self::OFFERED);
        $unknownList = ConfigRow::normalize('k', ['workflow_id' => '', 'language_mode' => 'per_language', 'original_map' => ['fallback' => '21']], []);

        self::assertSame(['per_language', $map], [$row['language_mode'], $row['automation_map']]);
        self::assertSame(['per_language', ['fallback' => '21']], [$unknownList['language_mode'], $unknownList['automation_map']]);
    }

    public function testAnotherWorkflowPickedHereReplacesAPerLanguageMap(): void
    {
        $row = ConfigRow::normalize('k', ['workflow_id' => '24', 'language_mode' => 'per_language', 'original_map' => ['et' => '31', 'fallback' => '21']], self::OFFERED);

        self::assertSame(['single', ['id' => '24']], [$row['language_mode'], $row['automation_map']]);
    }

    public function testAStoredWorkflowThatTheListDoesNotOfferStaysWhereNoneIsPosted(): void
    {
        $deleted = ConfigRow::normalize('k', ['workflow_id' => '', 'language_mode' => 'single', 'original_map' => ['id' => '99']], self::OFFERED);
        $unknownList = ConfigRow::normalize('k', ['workflow_id' => '', 'language_mode' => 'single', 'original_map' => ['id' => '21']], []);

        self::assertSame(['id' => '99'], $deleted['automation_map']);
        self::assertSame(['id' => '21'], $unknownList['automation_map']);
    }

    public function testAWorkflowThatTheListOffersAndTheMerchantClearedIsCleared(): void
    {
        $row = ConfigRow::normalize('k', ['workflow_id' => '', 'language_mode' => 'single', 'original_map' => ['id' => '21']], self::OFFERED);

        self::assertSame([], $row['automation_map']);
    }

    public function testAValueThatIsNoWorkflowIdReadsAsNone(): void
    {
        foreach (['abc', '12a', '-3', ['21']] as $value) {
            self::assertSame([], ConfigRow::normalize('k', ['workflow_id' => $value], self::OFFERED)['automation_map']);
        }
    }
}
