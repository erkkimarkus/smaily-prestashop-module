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

    /**
     * @return array<string, array{string|null, int}>
     */
    public function storedCartDelays(): array
    {
        return [
            'nothing stored reads the default' => [null, 30],
            'an empty value reads the default' => ['', 30],
            'the minimum' => ['10', 10],
            'what 2.x installed' => ['15', 15],
            'inside the bounds' => ['600', 600],
            'the maximum' => ['1435', 1435],
            'below the minimum reads the minimum' => ['5', 10],
            'above the maximum, which no code stores, reads as stored' => ['2880', 2880],
        ];
    }

    /**
     * @dataProvider storedCartDelays
     */
    public function testTheCartDelayIsTheStoredMinutesFromTheMinimumUpAndThirtyWhenNoneIsStored(?string $stored, int $delay): void
    {
        $values = $stored === null ? [] : [0 => [AutomationSettings::CART_DELAY_MINUTES => $stored]];

        self::assertSame($delay, (new FakeAutomationSettings($values))->cartDelayMinutes(1));
    }

    /**
     * @dataProvider refusedCartDelays
     */
    public function testACartDelayOutsideTenTo1435MinutesIsRefused(int $minutes): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new FakeAutomationSettings([]))->setCartDelayMinutes($minutes, 0);
    }

    /**
     * @return array<string, array{int}>
     */
    public function refusedCartDelays(): array
    {
        return ['9 minutes' => [9], '1436 minutes' => [1436], 'none' => [0], 'a day' => [1440]];
    }

    public function testEachAutomationsDiscountCodeReadsItsOwnSwitchRuleAndDays(): void
    {
        $settings = new FakeAutomationSettings([
            0 => [
                AutomationSettings::WELCOME_DISCOUNT_ENABLED => '1',
                AutomationSettings::WELCOME_DISCOUNT_RULE => '12',
                AutomationSettings::WELCOME_DISCOUNT_DAYS => '14',
                AutomationSettings::CART_DISCOUNT_ENABLED => '0',
                AutomationSettings::CART_DISCOUNT_DAYS => '900',
            ],
            2 => [AutomationSettings::WELCOME_DISCOUNT_ENABLED => '0', AutomationSettings::FIRST_ORDER_DISCOUNT_RULE => '7'],
        ]);

        self::assertTrue($settings->discountEnabled(Trigger::WELCOME, 1));
        self::assertSame(12, $settings->discountRule(Trigger::WELCOME, 1));
        self::assertSame(14, $settings->discountDays(Trigger::WELCOME, 1));
        self::assertFalse($settings->discountEnabled(Trigger::FIRST_ORDER, 1), 'nothing stored is off');
        self::assertSame(0, $settings->discountRule(Trigger::FIRST_ORDER, 1), 'no rule is 0');
        self::assertSame(7, $settings->discountDays(Trigger::FIRST_ORDER, 1), 'no days read the default');
        self::assertFalse($settings->discountEnabled(Trigger::ABANDONED_CART, 1));
        self::assertSame(365, $settings->discountDays(Trigger::ABANDONED_CART, 1), 'more days than the maximum read the maximum');
        self::assertFalse($settings->discountEnabled(Trigger::WELCOME, 2), 'a shop\'s own value wins');
        self::assertSame(12, $settings->discountRule(Trigger::WELCOME, 2), 'a shop without its own value reads the shared one');
        self::assertSame(7, $settings->discountRule(Trigger::FIRST_ORDER, 2));
        self::assertFalse($settings->discountEnabled('order_confirmation', 1), 'an unknown trigger has none');
        self::assertSame(0, $settings->discountRule('order_confirmation', 1));
    }

    /**
     * @dataProvider refusedDiscountDays
     */
    public function testDiscountDaysOutsideOneTo365AreRefused(int $days): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new FakeAutomationSettings([]))->setDiscount(Trigger::WELCOME, true, 12, $days, 0);
    }

    /**
     * @return array<string, array{int}>
     */
    public function refusedDiscountDays(): array
    {
        return ['none' => [0], '366 days' => [366], 'negative' => [-1]];
    }

    public function testEachTriggerHasItsOwnDiscountCodeFieldsAsTheSiblingConnectors(): void
    {
        self::assertSame(
            [
                Trigger::WELCOME => ['welcome_coupon_code', 'welcome_coupon_expires_at'],
                Trigger::FIRST_ORDER => ['first_order_coupon_code', 'first_order_coupon_expires_at'],
                Trigger::ABANDONED_CART => ['abandoned_cart_coupon_code', 'abandoned_cart_coupon_expires_at'],
            ],
            Trigger::COUPON_FIELDS
        );
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
