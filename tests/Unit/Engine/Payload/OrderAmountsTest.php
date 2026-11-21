<?php
declare(strict_types=1);

namespace Tests\Unit\Engine\Payload;

use PHPUnit\Framework\TestCase;
use PrestaShop\Module\SmailyForPrestaShop\Engine\Payload\OrderPayloadBuilder;

/**
 * PrestaShop keeps a voucher on the order, not on its lines. The order row shares it over the
 * lines by their totals, so that Σ line_total + shipping ≈ total_amount (contract §5 "Amount
 * semantics").
 */
final class OrderAmountsTest extends TestCase
{
    /**
     * @dataProvider orders
     *
     * @param list<float> $totals
     * @param list<float> $expected
     */
    public function testAVoucherIsSharedOverTheLinesByTheirTotals(array $totals, float $discount, array $expected): void
    {
        $shares = OrderPayloadBuilder::shares($totals, $discount);

        self::assertEqualsWithDelta($expected, $shares, 0.00005);
        self::assertEqualsWithDelta(min($discount, array_sum($totals)), array_sum($shares), 0.00005, 'the voucher is shared out in full, and never more than the lines hold');
        foreach ($shares as $index => $share) {
            self::assertGreaterThanOrEqual(0.0, $share);
            self::assertLessThanOrEqual($totals[$index] + 0.00005, $share, 'no line goes below zero');
        }
    }

    /**
     * @return iterable<string, array{list<float>, float, list<float>}>
     */
    public static function orders(): iterable
    {
        yield 'no voucher' => [[23.9, 71.8], 0.0, [0.0, 0.0]];
        yield 'two lines' => [[23.9, 71.8], 10.0, [2.4974, 7.5026]];
        yield 'thirds: the last line takes what rounding left' => [[10.0, 10.0, 10.0], 10.0, [3.3333, 3.3333, 3.3334]];
        yield 'a voucher larger than the lines (free shipping too)' => [[10.0, 5.0], 20.0, [10.0, 5.0]];
        yield 'a free line gets no share' => [[0.0, 30.0], 6.0, [0.0, 6.0]];
        yield 'one line' => [[19.12], 5.0, [5.0]];
        yield 'no lines' => [[], 5.0, []];
    }
}
