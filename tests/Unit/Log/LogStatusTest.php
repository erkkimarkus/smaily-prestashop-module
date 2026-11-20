<?php
declare(strict_types=1);

namespace Tests\Unit\Log;

use PHPUnit\Framework\TestCase;
use PrestaShop\Module\SmailyForPrestaShop\Log\LogFilter;
use PrestaShop\Module\SmailyForPrestaShop\Log\LogStatus;

/**
 * The Log names a row's status as the Magento connector does, and the filter's values stay within
 * what the list can show.
 */
final class LogStatusTest extends TestCase
{
    /**
     * @return array<string, array{string, string, ?string, string}>
     */
    public function queueRows(): array
    {
        return [
            'pending' => ['pending', 'contact.sync', null, 'pending'],
            'pending after a failure' => ['pending', 'contact.sync', 'Smaily API request failed with HTTP 503', 'pending'],
            'processing' => ['processing', 'contact.sync', null, 'sending'],
            'sent' => ['sent', 'automation.welcome', null, 'sent'],
            'failed' => ['failed', 'contact.sync', 'permanent_http_401: Smaily API credentials were rejected', 'failed'],
            'skipped' => ['skipped', 'automation.abandoned_cart', 'no_marketing_consent', 'skipped'],
            'a reminder closed by an order' => ['skipped', 'automation.abandoned_cart', 'ordered', 'withdrawn'],
            'the same reason on another type' => ['skipped', 'automation.first_order', 'ordered', 'skipped'],
        ];
    }

    /**
     * @dataProvider queueRows
     */
    public function testAQueueRowShowsItsStatus(string $stored, string $type, ?string $lastError, string $shown): void
    {
        self::assertSame($shown, LogStatus::ofQueueRow($stored, $type, $lastError));
    }

    public function testEachStatusHasItsPill(): void
    {
        self::assertSame(
            ['pending', 'pending', 'sent', 'failed', 'neutral', 'neutral', 'pending', 'pending', 'sent', 'neutral'],
            array_map([LogStatus::class, 'pill'], array_merge(LogStatus::QUEUE, LogStatus::JOB_ONLY))
        );
    }

    public function testTheStatusFilterSelectsWhatTheListShows(): void
    {
        self::assertSame('`status` = \'processing\'', LogStatus::queueCondition('sending', '`event_type`', '`last_error`'));
        self::assertSame('`status` = \'failed\'', LogStatus::queueCondition('failed', '`event_type`', '`last_error`'));
        $withdrawn = '(`status` = \'skipped\' AND `event_type` = \'automation.abandoned_cart\' AND COALESCE(`last_error`, \'\') = \'ordered\')';
        self::assertSame($withdrawn, LogStatus::queueCondition('withdrawn', '`event_type`', '`last_error`'));
        self::assertSame('(`status` = \'skipped\' AND NOT ' . $withdrawn . ')', LogStatus::queueCondition('skipped', '`event_type`', '`last_error`'));
        self::assertNull(LogStatus::queueCondition('done', '`event_type`', '`last_error`'), 'no queue row shows a job\'s status');
    }

    public function testTheFilterKeepsOnlyValuesOfItsForm(): void
    {
        $filter = new LogFilter([
            'source' => 'smaily',
            'type' => '  contact.sync ',
            'entity' => ['not', 'text'],
            'status' => 'withdrawn',
            'last_error' => '',
            'error' => 'PrestaShop reads this one',
            'created_from' => '2026-11-01',
            'created_to' => '2026-13-45',
            'updated_from' => 'yesterday',
            'page' => '3',
            '_token' => 'abc',
        ]);

        self::assertSame(['type' => 'contact.sync', 'source' => 'smaily', 'status' => 'withdrawn', 'created_from' => '2026-11-01'], $filter->values());
        self::assertSame('', $filter->get('entity'));
        self::assertSame([], (new LogFilter(['source' => 'other', 'status' => 'no-such-status']))->values());
        self::assertSame(['status' => 'cancelled'], (new LogFilter(['status' => 'cancelled']))->values());
    }
}
