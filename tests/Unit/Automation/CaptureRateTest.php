<?php
declare(strict_types=1);

namespace Tests\Unit\Automation;

use PHPUnit\Framework\TestCase;
use PrestaShop\Module\SmailyForPrestaShop\Automation\AbandonedCart\Capture\CaptureEndpoint;
use PrestaShop\Module\SmailyForPrestaShop\Log\FailureMessage;

/**
 * The checkout capture's limit per visitor: CaptureEndpoint::LIMIT captures in each minute.
 */
final class CaptureRateTest extends TestCase
{
    private const NOW = 1795000000;

    public function testTheFirstCaptureOfAMinuteStartsItsCount(): void
    {
        $minute = intdiv(self::NOW, 60);

        self::assertSame($minute . ':1', CaptureEndpoint::count('', self::NOW));
        self::assertSame($minute . ':1', CaptureEndpoint::count(($minute - 1) . ':6', self::NOW));
        self::assertSame($minute . ':1', CaptureEndpoint::count('not-a-count', self::NOW));
    }

    public function testEachCaptureOfTheMinuteCountsUpToTheLimit(): void
    {
        $minute = intdiv(self::NOW, 60);
        $stored = '';
        for ($i = 1; $i <= CaptureEndpoint::LIMIT; ++$i) {
            $stored = CaptureEndpoint::count($stored, self::NOW);
            self::assertSame($minute . ':' . $i, $stored);
        }

        self::assertNull(CaptureEndpoint::count($stored, self::NOW + 59 - self::NOW % 60));
        self::assertSame(($minute + 1) . ':1', CaptureEndpoint::count($stored, self::NOW + 60));
    }

    public function testTheLogSentenceOfTheHourlyLimitNamesItInEnglishAndEstonian(): void
    {
        $sentence = FailureMessage::REASONS[CaptureEndpoint::SKIPPED_CAPTURE_LIMIT];
        self::assertStringContainsString(' ' . CaptureEndpoint::HOURLY_LIMIT . ' ', $sentence);

        $catalogue = simplexml_load_file(__DIR__ . '/../../../src/translations/et-EE/ModulesSmailyforprestashopAdmin.et-EE.xlf');
        $targets = [];
        foreach ($catalogue->file->body->{'trans-unit'} as $unit) {
            if ((string) $unit->source === $sentence) {
                $targets[] = (string) $unit->target;
            }
        }
        self::assertCount(1, $targets);
        self::assertStringContainsString(' ' . CaptureEndpoint::HOURLY_LIMIT . ' ', $targets[0]);
        self::assertStringStartsWith('Vahele jäetud: ', $targets[0]);
    }
}
