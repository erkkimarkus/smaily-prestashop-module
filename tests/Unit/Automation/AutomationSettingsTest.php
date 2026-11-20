<?php
declare(strict_types=1);

namespace Tests\Unit\Automation;

use PHPUnit\Framework\TestCase;
use PrestaShop\Module\SmailyForPrestaShop\Automation\Trigger;
use PrestaShop\Module\SmailyForPrestaShop\Config\AutomationSettings;

require_once __DIR__ . '/FakeAutomationSettings.php';

/**
 * The Automations settings read in the keys and formats that the 3.0.0 upgrade writes, and the
 * marker field of each trigger.
 */
final class AutomationSettingsTest extends TestCase
{
    public function testEachAutomationIsOnOnlyWhereItsSwitchHoldsOne(): void
    {
        $settings = new FakeAutomationSettings([
            0 => [AutomationSettings::WELCOME_ENABLED => '1', AutomationSettings::CART_ENABLED => '0'],
            2 => [AutomationSettings::WELCOME_ENABLED => '0', AutomationSettings::FIRST_ORDER_ENABLED => '1'],
        ]);

        self::assertTrue($settings->enabled(Trigger::WELCOME, 1));
        self::assertFalse($settings->enabled(Trigger::FIRST_ORDER, 1), 'nothing stored is off');
        self::assertFalse($settings->enabled(Trigger::ABANDONED_CART, 1));
        self::assertFalse($settings->enabled(Trigger::WELCOME, 2), 'a shop\'s own value wins');
        self::assertTrue($settings->enabled(Trigger::FIRST_ORDER, 2));
        self::assertFalse($settings->enabled('order_confirmation', 1), 'an unknown trigger is off');
    }

    public function testTheCartDelayIsAtLeastFifteenMinutesAndFifteenWhenNoneIsStored(): void
    {
        self::assertSame(45, (new FakeAutomationSettings([0 => [AutomationSettings::CART_DELAY_MINUTES => '45']]))->cartDelayMinutes(1));
        self::assertSame(15, (new FakeAutomationSettings([0 => [AutomationSettings::CART_DELAY_MINUTES => '5']]))->cartDelayMinutes(1));
        self::assertSame(15, (new FakeAutomationSettings([]))->cartDelayMinutes(1));
    }

    public function testTheCartFieldsAreTheKnownNamesOfTheStoredList(): void
    {
        $settings = new FakeAutomationSettings([0 => [AutomationSettings::CART_FIELDS => 'first_name,price,unknown,name']]);

        self::assertSame(['first_name', 'price', 'name'], $settings->cartFields(1));
        self::assertSame([], (new FakeAutomationSettings([]))->cartFields(1));
    }

    public function testEachTriggerHasItsOwnMarkerFieldStampedInUtc(): void
    {
        self::assertSame(
            [Trigger::WELCOME => 'welcome_automation_at', Trigger::FIRST_ORDER => 'first_order_automation_at', Trigger::ABANDONED_CART => 'abandoned_cart_automation_at'],
            Trigger::MARKER_FIELDS
        );
        $before = gmdate('Y-m-d H:i:s');
        $marker = Trigger::marker(Trigger::FIRST_ORDER);
        self::assertSame(['first_order_automation_at'], array_keys($marker));
        self::assertMatchesRegularExpression('/^\d{4}-\d\d-\d\d \d\d:\d\d:\d\d$/', $marker['first_order_automation_at']);
        self::assertGreaterThanOrEqual($before, $marker['first_order_automation_at']);
        self::assertSame([], Trigger::marker('order_confirmation'));
    }
}
