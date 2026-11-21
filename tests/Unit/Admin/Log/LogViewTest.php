<?php
declare(strict_types=1);

namespace Tests\Unit\Admin\Log;

use PHPUnit\Framework\TestCase;
use PrestaShop\Module\SmailyForPrestaShop\Admin\Log\LogView;
use PrestaShop\Module\SmailyForPrestaShop\Log\ResendGuard;

/**
 * The Log's rows and Details in the Magento connector's words: what each state says, which payload
 * shows, and that no secret shows while the contact's data does.
 */
final class LogViewTest extends TestCase
{
    private string $timezone;

    protected function setUp(): void
    {
        $this->timezone = date_default_timezone_get();
        date_default_timezone_set('Europe/Tallinn');
    }

    protected function tearDown(): void
    {
        date_default_timezone_set($this->timezone);
    }

    public function testAQueueRowOfTheList(): void
    {
        $row = $this->view()->row([
            'source' => 'smaily', 'kind' => 'smaily', 'id' => '7', 'type' => 'contact.sync', 'entity' => 'shopper1@example.test',
            'status' => 'failed', 'attempts' => '1', 'last_error' => 'permanent_http_401: Smaily API credentials were rejected',
            'created_at' => '2026-11-20 10:00:00', 'updated_at' => '2026-11-20 10:00:05', 'counts' => null,
        ], true);

        self::assertSame([
            'log_id' => 'smaily-7',
            'source' => 'Smaily',
            'type' => 'contact.sync',
            'entity' => 'shopper1@example.test',
            'status' => ['label' => 'Failed', 'variant' => 'failed'],
            'attempts' => '1',
            'error' => 'Smaily API credentials were rejected',
            'created' => '2026-11-20 12:00:00',
            'updated' => '2026-11-20 12:00:05',
            'selectable' => true,
            'resend' => true,
        ], $row);
    }

    public function testAWithdrawnReminderAHashedEntityAndAnIngestRow(): void
    {
        $withdrawn = $this->view()->row(self::listRow(['type' => 'automation.abandoned_cart', 'status' => 'skipped', 'last_error' => 'ordered']), false);
        self::assertSame(['label' => 'Withdrawn', 'variant' => 'neutral'], $withdrawn['status']);
        self::assertSame('', $withdrawn['error']);
        self::assertFalse($withdrawn['selectable']);

        $skipped = $this->view()->row(self::listRow(['type' => 'automation.abandoned_cart', 'status' => 'skipped', 'last_error' => 'no_marketing_consent']), false);
        self::assertSame('Skipped', $skipped['status']['label']);
        self::assertSame('Skipped: this shopper has not agreed to marketing emails in the store, and is not a subscribed contact in Smaily. Nothing was sent.', $skipped['error']);

        self::assertSame('ab12ab12ab12…', $this->view()->row(self::listRow(['entity' => str_repeat('ab12', 16)]), false)['entity']);
        self::assertSame('—', $this->view()->row(self::listRow(['entity' => null]), false)['entity']);
        self::assertSame('Sending', $this->view()->row(self::listRow(['status' => 'processing']), false)['status']['label']);

        $ingest = $this->view()->row(self::listRow(['source' => 'intelligence', 'kind' => 'intelligence', 'type' => 'catalog', 'entity' => 'ps-12', 'status' => 'failed']), false);
        self::assertSame(['intelligence-7', 'Campaign Intelligence', 'ps-12', false], [$ingest['log_id'], $ingest['source'], $ingest['entity'], $ingest['selectable']]);
    }

    public function testAnImportJobShowsItsContactsAndItsStatus(): void
    {
        $job = ['source' => 'smaily', 'kind' => 'job', 'id' => '3', 'type' => 'contacts_import', 'entity' => null, 'status' => 'done', 'attempts' => null,
            'last_error' => null, 'created_at' => '2026-11-20 10:00:00', 'updated_at' => '2026-11-20 10:05:00', 'counts' => '{"total":128,"sent":126,"failed":0,"skipped":2}', ];

        $row = $this->view()->row($job, false);
        self::assertSame(['job-3', '128 contacts', ['label' => 'Done', 'variant' => 'sent'], '—', false], [$row['log_id'], $row['entity'], $row['status'], $row['attempts'], $row['selectable']]);
        self::assertSame('1 contact', $this->view()->row(['counts' => '{"total":1}'] + $job, false)['entity']);
        self::assertSame(['Queued', 'Running', 'Failed', 'Cancelled'], array_map(
            fn (string $status): string => $this->view()->row(['status' => $status] + $job, false)['status']['label'],
            ['queued', 'running', 'failed', 'cancelled']
        ));

        $details = $this->view()->details($job, null);
        self::assertTrue($details['job']);
        self::assertSame(['Import #3', '128 contacts', 'Done, 126 of 128 synced.'], [$details['title'], $details['entity'], $details['outcome']['text']]);
        self::assertArrayNotHasKey('resend', $details);

        $failed = $this->view()->details(['status' => 'failed', 'last_error' => 'Smaily API request failed with HTTP 500'] + $job, null);
        self::assertSame(['Stopped before an error — 126 of 128 synced so far. Press Run again to run it again.', 'Last error', false], [$failed['outcome']['text'], $failed['error_title'], $failed['error_is_reason']]);
    }

    public function testTheDetailsOfADeliveredRowShowTheRequestInFullWithoutSecrets(): void
    {
        $details = $this->view()->details(self::storedRow([
            'status' => 'sent',
            'last_request' => '[{"email":"shopper1@example.test","first_name":"Testfirst","api_key":"not-a-real-key"}]',
            'last_response' => '{"http_status":200,"body":{"code":101}}',
        ]), ResendGuard::REASON_NOT_FAILED);

        self::assertSame('Event #7', $details['title']);
        self::assertSame('0 of 5', $details['attempts']);
        self::assertSame('Delivered — no retries needed.', $details['outcome']['text']);
        self::assertTrue($details['outcome']['terminal']);
        self::assertSame('Payload (as sent)', $details['payload_title']);
        self::assertStringContainsString('"email": "shopper1@example.test"', $details['payload']);
        self::assertStringContainsString('"first_name": "Testfirst"', $details['payload']);
        self::assertStringContainsString('"api_key": "[redacted]"', $details['payload']);
        self::assertStringNotContainsString('not-a-real-key', (string) json_encode($details));
        self::assertStringContainsString('"http_status": 200', $details['response']);
        self::assertFalse($details['response_is_error']);
        self::assertFalse($details['resend']);
        self::assertSame(['Queued', 'Attempt 1'], array_column($details['history'], 'title'));
        self::assertSame(['', 'Delivered'], array_column($details['history'], 'badge'));
    }

    public function testTheDetailsOfAFailedRowSayWhatHappensNext(): void
    {
        $parked = $this->view()->details(self::storedRow([
            'status' => 'failed', 'attempts' => '1', 'last_error' => 'permanent_http_401: Smaily API credentials were rejected',
            'last_request' => '[{"email":"shopper1@example.test"}]', 'last_response' => '{"http_status":401,"body":null}',
        ]), null);
        self::assertSame('Stopped after 1 of 5 attempts — retrying could not change the outcome, so this row will NOT retry on its own. Fix the cause shown below, then select it in the log and press Retry.', $parked['outcome']['text']);
        self::assertSame(['Smaily API credentials were rejected', 'Last error', 'permanent_http_401'], [$parked['error'], $parked['error_title'], $parked['failure_class']]);
        self::assertTrue($parked['resend']);
        self::assertTrue($parked['response_is_error']);
        self::assertSame('Smaily API credentials were rejected', $parked['history'][1]['detail']);

        $exhausted = $this->view()->details(self::storedRow(['status' => 'failed', 'attempts' => '5', 'last_error' => 'Smaily API request failed with HTTP 500']), null);
        self::assertSame('All 5 automatic attempts are used up — this row will NOT retry on its own. Select it in the log and press Retry to queue it again.', $exhausted['outcome']['text']);
        self::assertTrue($exhausted['history_is_partial']);
        self::assertSame('Payload (queued, nothing was sent for this row)', $exhausted['payload_title']);
        self::assertSame('No response — nothing was sent for this row.', $exhausted['no_response']);

        $superseded = $this->view()->details(self::storedRow(['status' => 'failed', 'attempts' => '1', 'last_error' => 'x']), ResendGuard::REASON_SUPERSEDED);
        self::assertSame('A later message of this kind already reached this contact; sending again would deliver it twice.', $superseded['outcome']['text']);
        self::assertFalse($superseded['resend']);

        $queued = $this->view()->details(self::storedRow(['status' => 'failed', 'attempts' => '5', 'last_error' => 'x']), ResendGuard::REASON_QUEUED);
        self::assertStringStartsWith('All 5 automatic attempts are used up', $queued['outcome']['text']);
        self::assertFalse($queued['resend']);
    }

    public function testTheDetailsOfWaitingSkippedAndWithdrawnRows(): void
    {
        $fresh = $this->view()->details(self::storedRow(['status' => 'pending']), ResendGuard::REASON_NOT_FAILED);
        self::assertSame(
            ['Waiting for the next queue flush (runs every minute).', 'Payload (queued, not sent yet)', 'No response recorded — this row has not been attempted yet.', false],
            [$fresh['outcome']['text'], $fresh['payload_title'], $fresh['no_response'], $fresh['outcome']['terminal']]
        );
        self::assertStringContainsString('"email": "shopper1@example.test"', $fresh['payload'], 'the queued payload shows while nothing was sent');

        $retrying = $this->view()->details(self::storedRow([
            'status' => 'pending', 'attempts' => '2', 'next_retry_at' => '2026-11-20 10:15:00', 'last_error' => 'Smaily API request failed with HTTP 503',
            'last_request' => '[{"email":"shopper1@example.test"}]',
        ]), ResendGuard::REASON_NOT_FAILED);
        self::assertSame('Next automatic retry: 2026-11-20 12:15:00 (attempt 3 of 5).', $retrying['outcome']['text']);
        self::assertSame('No response received — the server did not answer. The last error says why.', $retrying['no_response']);
        self::assertSame(['Queued', 'Attempt 1', 'Attempt 2', 'Attempt 3'], array_column($retrying['history'], 'title'));
        self::assertSame('Scheduled', $retrying['history'][3]['badge']);

        $sending = $this->view()->details(self::storedRow(['status' => 'processing']), ResendGuard::REASON_NOT_FAILED);
        self::assertSame('A delivery attempt is in progress right now.', $sending['outcome']['text']);

        $skipped = $this->view()->details(self::storedRow(['type' => 'automation.abandoned_cart', 'status' => 'skipped', 'last_error' => 'no_marketing_consent']), ResendGuard::REASON_NOT_FAILED);
        self::assertSame('Skipped — nothing was sent, and this row will not retry: retrying could not change the reason shown below.', $skipped['outcome']['text']);
        self::assertSame(['Reason', true, ''], [$skipped['error_title'], $skipped['error_is_reason'], $skipped['failure_class']]);
        self::assertStringStartsWith('Skipped: this shopper has not agreed to marketing emails', $skipped['error']);

        $withdrawn = $this->view()->details(self::storedRow(['type' => 'automation.abandoned_cart', 'status' => 'skipped', 'last_error' => 'ordered']), ResendGuard::REASON_WITHDRAWN);
        self::assertSame('This reminder was withdrawn because the shopper completed the purchase; it cannot be re-sent.', $withdrawn['outcome']['text']);
        self::assertSame(['Withdrawn', ''], [$withdrawn['status']['label'], $withdrawn['error']]);
        self::assertSame('Withdrawn', $withdrawn['history'][1]['title']);
    }

    public function testARowThatSendAgainQueuedNamesItsOrigin(): void
    {
        $details = $this->view()->details(self::storedRow([
            'payload' => '{"email":"shopper1@example.test","_resend":{"of":7,"by":"Testfirst Testlast","at":"2026-11-20T10:00:00Z"}}',
        ]), ResendGuard::REASON_NOT_FAILED);

        self::assertSame('Re-sent from event #7 by Testfirst Testlast on 2026-11-20 12:00:00', $details['resent']);
        self::assertSame('', $this->view()->details(self::storedRow([]), null)['resent']);
    }

    public function testARowWhoseEmailWentWithoutItsDiscountCodeSaysSo(): void
    {
        $details = $this->view()->details(self::storedRow([
            'type' => 'automation.welcome',
            'payload' => '{"email":"shopper1@example.test","fields":{},"discount_notice":{"reason":"rule_unavailable","rule":12}}',
        ]), ResendGuard::REASON_NOT_FAILED);

        self::assertSame(
            'Without a discount code: cart price rule 12 gave no code for this shop (it was deleted, is past its end date, or is not for this shop). The email goes without one.',
            $details['discount_notice']
        );
        self::assertSame('', $this->view()->details(self::storedRow([]), null)['discount_notice']);
    }

    private function view(): LogView
    {
        return new LogView(static fn (string $id, array $parameters = []): string => strtr($id, $parameters));
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private static function listRow(array $overrides): array
    {
        return $overrides + [
            'source' => 'smaily', 'kind' => 'smaily', 'id' => '7', 'type' => 'contact.sync', 'entity' => 'shopper1@example.test', 'status' => 'sent',
            'attempts' => '0', 'last_error' => null, 'created_at' => '2026-11-20 10:00:00', 'updated_at' => '2026-11-20 10:00:05', 'counts' => null,
        ];
    }

    /**
     * A row as LogRows::find() gives it.
     *
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private static function storedRow(array $overrides): array
    {
        return $overrides + self::listRow([]) + [
            'payload' => '{"email":"shopper1@example.test","source":"customer"}',
            'next_retry_at' => null,
            'last_request' => null,
            'last_response' => null,
        ];
    }
}
