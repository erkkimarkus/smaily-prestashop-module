<?php
declare(strict_types=1);

namespace Tests\Unit\Log;

use PHPUnit\Framework\TestCase;
use PrestaShop\Module\SmailyForPrestaShop\Log\AttemptHistory;
use PrestaShop\Module\SmailyForPrestaShop\Log\FailureMessage;

/**
 * What a stored error says to the merchant, and the attempts a row lists, as the Magento connector's
 * Log gives them.
 */
final class FailureMessageTest extends TestCase
{
    public function testAParkedRefusalShowsItsMessageAndKeepsItsClassForDetails(): void
    {
        $same = static fn (string $text): string => $text;

        self::assertSame('Smaily API credentials were rejected', FailureMessage::forDisplay('permanent_http_401: Smaily API credentials were rejected', $same));
        self::assertSame('permanent_http_401', FailureMessage::failureClass('permanent_http_401: Smaily API credentials were rejected'));
        self::assertSame('Smaily API returned code 203', FailureMessage::forDisplay('permanent_envelope_203: Smaily API returned code 203', $same));
        self::assertSame('permanent_envelope_203', FailureMessage::failureClass('permanent_envelope_203: Smaily API returned code 203'));
        self::assertSame('Smaily API request failed with HTTP 503', FailureMessage::forDisplay('Smaily API request failed with HTTP 503', $same));
        self::assertSame('', FailureMessage::failureClass('Smaily API request failed with HTTP 503'));
        self::assertSame('', FailureMessage::forDisplay(null, $same));
    }

    public function testASkipReasonShowsAsItsSentenceInTheAdminsLanguage(): void
    {
        $estonian = static fn (string $text): string => 'ET: ' . $text;

        self::assertSame(
            'ET: Skipped: this shopper has not agreed to marketing emails in the store, and is not a subscribed contact in Smaily. Nothing was sent.',
            FailureMessage::forDisplay('no_marketing_consent', $estonian)
        );
        self::assertSame('ET: Skipped: this shopper unsubscribed in Smaily. Nothing was sent.', FailureMessage::forDisplay('unsubscribed_in_smaily', $estonian));
        self::assertSame(
            'ET: Skipped: the shopper has since changed their newsletter subscription, and the newer choice already reached Smaily. Nothing was sent.',
            FailureMessage::forDisplay('stale_subscription', $estonian)
        );
        self::assertSame('contact_sync_off', FailureMessage::forDisplay('contact_sync_off', $estonian), 'a code without a sentence shows as stored');
    }

    public function testTheErrorFilterFindsAReasonByTheWordsItShows(): void
    {
        $same = static fn (string $text): string => $text;

        self::assertSame(['no_marketing_consent'], FailureMessage::codesShowing('marketing EMAILS', $same));
        self::assertSame(['no_marketing_consent', 'unsubscribed_in_smaily', 'recently_reminded', 'customer_deleted', 'no_workflow', 'stale_subscription'], FailureMessage::codesShowing('Nothing was sent', $same));
        self::assertSame([], FailureMessage::codesShowing('HTTP 503', $same));
        self::assertSame([], FailureMessage::codesShowing('', $same));
    }

    public function testTheAttemptsListWhatTheRowStores(): void
    {
        $results = static fn (array $entries): array => array_map(
            static fn (array $entry): string => ($entry['attempt'] ?? '-') . ' ' . $entry['result'] . ' ' . ($entry['at'] ?? '-') . ($entry['latest_error'] ? ' error' : ''),
            $entries
        );

        self::assertSame(['- queued C', '1 delivered U'], $results(AttemptHistory::entries('sent', 0, 'C', 'U', null)));
        self::assertSame(['- queued C', '1 failed -', '2 failed -', '3 delivered U'], $results(AttemptHistory::entries('sent', 2, 'C', 'U', null)));
        self::assertSame(['- queued C', '1 failed U error'], $results(AttemptHistory::entries('failed', 1, 'C', 'U', null)));
        self::assertSame(['- queued C', '1 failed -', '2 failed U error', '3 scheduled N'], $results(AttemptHistory::entries('pending', 2, 'C', 'U', 'N')));
        self::assertSame(['- queued C', '1 waiting -'], $results(AttemptHistory::entries('pending', 0, 'C', 'U', null)));
        self::assertSame(['- queued C', '1 in_progress U'], $results(AttemptHistory::entries('sending', 0, 'C', 'U', null)));
        self::assertSame(['- queued C', '- skipped U'], $results(AttemptHistory::entries('skipped', 0, 'C', 'U', null)));
        self::assertSame(['- queued C', '- withdrawn U'], $results(AttemptHistory::entries('withdrawn', 0, 'C', 'U', null)));
    }
}
