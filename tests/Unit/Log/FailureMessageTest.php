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
        $same = static fn (string $text, array $values = []): string => strtr($text, $values);

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
        self::assertSame('ET: Skipped: contact synchronization is off for this shop. Nothing was sent.', FailureMessage::forDisplay('contact_sync_off', $estonian));
        self::assertSame('ET: The data stored with this row is incomplete, so it cannot be sent.', FailureMessage::forDisplay('malformed_payload', $estonian));
        self::assertSame('some_new_code', FailureMessage::forDisplay('some_new_code', $estonian), 'a code without a sentence shows as stored');
    }

    public function testEveryReasonCodeOfAQueueRowHasASentence(): void
    {
        $codes = [
            'no_marketing_consent', 'unsubscribed_in_smaily', 'recently_reminded', 'customer_deleted', 'capture_limit', 'no_workflow',
            'contact_sync_off', 'not_in_audience', 'no_account', 'no_credentials', 'automation_off', 'unknown_trigger',
            'too_old', 'already_reminded', 'not_tracked', 'payload_failed', 'malformed_payload',
            'preference_replaced', 'no_connection',
            'engine_product_gone', 'engine_product_exists', 'engine_customer_gone', 'engine_customer_not_in_scope',
            'engine_order_gone', 'engine_item_refused', 'engine_manifest_too_many', 'engine_opted_out', 'engine_nothing_to_merge', 'engine_customer_not_sent',
            'engine_customer_refused', 'engine_customer_not_found',
        ];

        self::assertSame($codes, array_keys(FailureMessage::REASONS));
        foreach (FailureMessage::REASONS + FailureMessage::JOB_REASONS as $code => $sentence) {
            self::assertStringNotContainsString($code, $sentence);
            self::assertMatchesRegularExpression('/^[A-Z].*\.$/', $sentence);
        }
    }

    public function testAnImportJobHasItsOwnSentenceWhereOnlySomeContactsWereLeftOut(): void
    {
        $same = static fn (string $text, array $values = []): string => strtr($text, $values);

        self::assertSame('Skipped: no Smaily account is saved for this shop and language. Nothing was sent.', FailureMessage::forDisplay('no_account', $same));
        self::assertSame('Some contacts were left out: no Smaily account is saved for their shop and language.', FailureMessage::forDisplay('no_account', $same, true));
        self::assertSame(
            'Some contacts were left out: the Smaily account for their shop and language has no subdomain, username or password.',
            FailureMessage::forDisplay('no_credentials', $same, true)
        );
        self::assertSame('Skipped: contact synchronization is off for this shop. Nothing was sent.', FailureMessage::forDisplay('contact_sync_off', $same, true));
        self::assertSame('Smaily API request failed with HTTP 500', FailureMessage::forDisplay('Smaily API request failed with HTTP 500', $same, true));
    }

    public function testAStoredMessageShowsTranslatedWithItsValues(): void
    {
        $estonian = static fn (string $text, array $values = []): string => 'ET: ' . strtr($text, $values);
        $stored = [
            'permanent_http_401: Smaily API credentials were rejected' => 'ET: Smaily API credentials were rejected',
            'permanent_http_403: Smaily refused the request: the account\'s package does not include API access.' => 'ET: Smaily refused the request: the account\'s package does not include API access.',
            'Smaily API request failed with HTTP 503' => 'ET: Smaily API request failed with HTTP 503',
            'permanent_http_404: Smaily API request failed with HTTP 404' => 'ET: Smaily API request failed with HTTP 404',
            'Smaily API request failed: no answer within 30 seconds' => 'ET: Smaily API request failed: no answer within 30 seconds',
            'Smaily API request failed: could not connect to Smaily' => 'ET: Smaily API request failed: could not connect to Smaily',
            'Smaily API returned a malformed response body' => 'ET: Smaily API returned a malformed response body',
            'permanent_envelope_203: Smaily API returned code 203' => 'ET: Smaily API returned code 203',
            'Smaily API returned code 225' => 'ET: Smaily API returned code 225',
            'permanent_http_400: Campaign Intelligence answered HTTP 400 (validation_failed).' => 'ET: Campaign Intelligence answered HTTP 400 (validation_failed).',
            'The Campaign Intelligence request failed (network_error).' => 'ET: The Campaign Intelligence request failed (network_error).',
            'No handler takes the event type "contact.unknown"' => 'ET: No handler takes the event type "contact.unknown"',
            'The handler returned no outcome for the row' => 'ET: The handler returned no outcome for the row',
            'RuntimeException at Flusher.php:120 (code 0)' => 'ET: RuntimeException at Flusher.php:120 (code 0)',
            // Stored by earlier code, with the HTTP client's own text: the wording is translated.
            'Smaily API request failed: Could not resolve host: demo.sendsmaily.net' => 'ET: Smaily API request failed: Could not resolve host: demo.sendsmaily.net',
            // Not one of the module's messages: shown as stored.
            'Something nobody worded' => 'Something nobody worded',
        ];

        foreach ($stored as $lastError => $shown) {
            self::assertSame($shown, FailureMessage::forDisplay($lastError, $estonian), $lastError);
        }
    }

    public function testAMessageIsReadAsItsSourceWithItsValues(): void
    {
        $sources = [];
        $record = static function (string $text, array $values = []) use (&$sources): string {
            $sources[] = [$text, $values];

            return $text;
        };

        FailureMessage::forDisplay('Smaily API request failed: no answer within 30 seconds', $record);
        FailureMessage::forDisplay('Smaily API request failed: Connection refused', $record);
        FailureMessage::forDisplay('permanent_http_401: Campaign Intelligence answered HTTP 401 (unauthorized).', $record);

        self::assertSame([
            ['Smaily API request failed: no answer within %seconds% seconds', ['%seconds%' => '30']],
            ['Smaily API request failed: %error%', ['%error%' => 'Connection refused']],
            ['Campaign Intelligence answered HTTP %status% (%code%).', ['%status%' => '401', '%code%' => 'unauthorized']],
        ], $sources);
    }

    public function testTheErrorFilterFindsAReasonByTheWordsItShows(): void
    {
        $same = static fn (string $text): string => $text;

        self::assertSame(['no\\_marketing\\_consent'], FailureMessage::patternsShowing('marketing EMAILS', $same));
        $withoutThoseWords = [
            'capture_limit', 'malformed_payload',
            'engine_item_refused', 'engine_manifest_too_many', 'engine_customer_not_sent', 'engine_customer_refused', 'engine_customer_not_found',
        ];
        $codes = static fn (array $codes): array => array_map(static fn (string $code): string => str_replace('_', '\\_', $code), $codes);
        self::assertSame($codes(array_values(array_diff(array_keys(FailureMessage::REASONS), $withoutThoseWords))), FailureMessage::patternsShowing('Nothing was sent', $same));
        self::assertSame($codes(['no_account', 'no_credentials']), FailureMessage::patternsShowing('shop and language', $same), 'a queue row\'s sentence and an import job\'s');
        self::assertSame($codes(['no_account', 'no_credentials']), FailureMessage::patternsShowing('were left out', $same));
        self::assertSame([], FailureMessage::patternsShowing('HTTP 503', $same), 'a value is found in the stored text itself');
        self::assertSame([], FailureMessage::patternsShowing('', $same));
    }

    public function testTheErrorFilterFindsAMessageByItsTranslatedWording(): void
    {
        $estonian = static fn (string $text): string => strtr($text, [
            'Smaily API request failed' => 'Smaily API päring ebaõnnestus',
            'could not connect to Smaily' => 'Smailyga ei saadud ühendust',
        ]);

        self::assertSame(
            ['%Smaily API request failed with HTTP %', '%Smaily API request failed: no answer within % seconds', '%Smaily API request failed: could not connect to Smaily', '%Smaily API request failed: %'],
            FailureMessage::patternsShowing('päring EBAÕNNESTUS', $estonian)
        );
        self::assertSame(['%Smaily API request failed: could not connect to Smaily'], FailureMessage::patternsShowing('ei saadud', $estonian));
        self::assertSame(['%No handler takes the event type "%"'], FailureMessage::patternsShowing('event type', $estonian));
        self::assertSame([], FailureMessage::patternsShowing('30', $estonian), 'a value is not part of the wording');
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
