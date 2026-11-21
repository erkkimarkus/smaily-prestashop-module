<?php
/**
 * 2024 Smaily
 *
 * NOTICE OF LICENSE
 *
 * Smaily for PrestaShop is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * any later version.
 *
 * Smaily for PrestaShop is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with Smaily for PrestaShop. If not, see <http://www.gnu.org/licenses/>.
 *
 * @author    Smaily <info@smaily.com>
 * @copyright 2024 Smaily
 * @license   GPL3
 */
declare(strict_types=1);

namespace PrestaShop\Module\SmailyForPrestaShop\Admin\Log;

if (!defined('_PS_VERSION_')) {
    exit;
}

use PrestaShop\Module\SmailyForPrestaShop\Admin\Dashboard\EntityLabel;
use PrestaShop\Module\SmailyForPrestaShop\Contacts\Import\JobStore;
use PrestaShop\Module\SmailyForPrestaShop\Discount\AutomationDiscount;
use PrestaShop\Module\SmailyForPrestaShop\Log\AttemptHistory;
use PrestaShop\Module\SmailyForPrestaShop\Log\FailureMessage;
use PrestaShop\Module\SmailyForPrestaShop\Log\LogRows;
use PrestaShop\Module\SmailyForPrestaShop\Log\LogStatus;
use PrestaShop\Module\SmailyForPrestaShop\Log\PayloadRedactor;
use PrestaShop\Module\SmailyForPrestaShop\Log\Resend;
use PrestaShop\Module\SmailyForPrestaShop\Log\ResendGuard;
use PrestaShop\Module\SmailyForPrestaShop\Queue\RetryPolicy;

/**
 * Words the Log for its templates, in the Magento connector's words where Magento has them
 * (view/adminhtml/templates/log/details.phtml, Ui/Component/*): a row of the list, and the Details
 * of a row. No SQL and no PrestaShop class: every text goes through the callable that translates
 * an EN string. Stored times are UTC and show in the shop's time zone.
 *
 * Details show the request and the answer in full to the admin, without secrets
 * (Log\PayloadRedactor), as Magento does. A row whose email went without its discount code says so
 * in a sentence (Discount\AutomationDiscount::NOTICE_KEY in the row's payload).
 */
final class LogView
{
    /** @var callable(string, array<string, string|int>=): string */
    private $translate;

    /**
     * @param callable(string, array<string, string|int>=): string $translate
     */
    public function __construct(callable $translate)
    {
        $this->translate = $translate;
    }

    /**
     * A row of the list.
     *
     * @param array<string, mixed> $row a row of LogRows::page()
     * @param bool $resend whether the resend guard cleared the row
     *
     * @return array<string, mixed>
     */
    public function row(array $row, bool $resend): array
    {
        $status = $this->status($row);

        return [
            'log_id' => $row['kind'] . '-' . $row['id'],
            'source' => $this->sourceLabel((string) $row['source']),
            'type' => (string) $row['type'],
            'entity' => $this->entity($row),
            'status' => ['label' => $this->statusLabel($status), 'variant' => LogStatus::pill($status)],
            'attempts' => $row['attempts'] === null ? '—' : (string) (int) $row['attempts'],
            'error' => $status === LogStatus::WITHDRAWN ? '' : FailureMessage::forDisplay($row['last_error'], $this->translate, $row['kind'] === LogRows::JOB),
            'created' => self::time((string) $row['created_at']),
            'updated' => self::time((string) $row['updated_at']),
            'selectable' => $row['kind'] === LogRows::SMAILY && $status === LogStatus::FAILED,
            'resend' => $resend,
        ];
    }

    /**
     * The Details of a row.
     *
     * @param array<string, mixed> $row a row of LogRows::find()
     * @param string|null $refusal the resend guard's reason for an event-queue row, null when the
     *                             guard cleared it; ignored for any other row
     *
     * @return array<string, mixed>
     */
    public function details(array $row, ?string $refusal): array
    {
        $status = $this->status($row);
        $details = [
            'log_id' => $row['kind'] . '-' . $row['id'],
            'title' => $this->trans($row['kind'] === LogRows::JOB ? 'Import #%id%' : 'Event #%id%', ['%id%' => (int) $row['id']]),
            'type' => (string) $row['type'],
            'status' => ['label' => $this->statusLabel($status), 'variant' => LogStatus::pill($status)],
            'source' => $this->sourceLabel((string) $row['source']),
            'entity' => $row['entity'] === null && $row['kind'] !== LogRows::JOB ? '' : $this->entity($row),
            'created' => self::time((string) $row['created_at']),
            'updated' => self::time((string) $row['updated_at']),
        ];
        if ($row['kind'] === LogRows::JOB) {
            return $details + [
                'job' => true,
                'outcome' => ['text' => $this->jobOutcome($row), 'terminal' => !in_array($row['status'], JobStore::ACTIVE, true)],
                'error' => FailureMessage::forDisplay($row['last_error'], $this->translate, true),
                'error_title' => $this->trans($row['status'] === JobStore::STATUS_FAILED ? 'Last error' : 'Reason'),
                'error_is_reason' => $row['status'] !== JobStore::STATUS_FAILED,
            ];
        }

        $attempts = (int) $row['attempts'];
        $lastError = $status === LogStatus::WITHDRAWN ? '' : FailureMessage::forDisplay($row['last_error'], $this->translate);
        $sentence = $refusal === ResendGuard::REASON_WITHDRAWN || ($refusal !== null && $status === LogStatus::FAILED)
            ? ResendGuard::message($refusal)
            : '';
        $payload = json_decode((string) $row['payload'], true);
        $record = is_array($payload) ? Resend::recordOf($payload) : null;
        $notice = is_array($payload) ? ($payload[AutomationDiscount::NOTICE_KEY] ?? null) : null;
        $request = PayloadRedactor::forDisplay($row['last_request']);
        $attempted = $attempts > 0 || !in_array($status, [LogStatus::PENDING, LogStatus::SENDING], true);
        // A privacy erasure cleared what the row held of its contact (ARCHITECTURE 11.2).
        $erased = $row['entity'] === ResendGuard::ERASED_ENTITY;
        $history = [];
        foreach (AttemptHistory::entries($status, $attempts, (string) $row['created_at'], (string) $row['updated_at'], $row['next_retry_at']) as $entry) {
            $history[] = [
                'result' => $entry['result'],
                'title' => $entry['attempt'] === null ? $this->resultLabel($entry['result']) : $this->trans('Attempt %number%', ['%number%' => $entry['attempt']]),
                'badge' => $entry['attempt'] === null ? '' : $this->resultLabel($entry['result']),
                'time' => $entry['at'] === null ? '' : self::time($entry['at']),
                'detail' => $entry['latest_error'] ? mb_strimwidth($lastError, 0, 140, '…') : '',
                'partial' => $entry['result'] === AttemptHistory::FAILED && $entry['at'] === null,
            ];
        }

        return $details + [
            'job' => false,
            'attempts' => $this->trans('%attempts% of %max%', ['%attempts%' => $attempts, '%max%' => RetryPolicy::MAX_ATTEMPTS]),
            'resent' => $record === null ? '' : $this->trans('Re-sent from event #%id% by %employee% on %date%', [
                '%id%' => $record['of'],
                '%employee%' => $record['by'],
                '%date%' => date('Y-m-d H:i:s', $record['at']),
            ]),
            'discount_notice' => !is_array($notice) ? '' : $this->trans(
                'Without a discount code: cart price rule %rule% gave no code for this shop (it was deleted, is past its end date, starts after the code would end, has no uses left, is limited to one customer, or is not for this shop). The email goes without one.',
                ['%rule%' => (int) ($notice['rule'] ?? 0)]
            ),
            'history' => $history,
            'history_is_partial' => in_array(true, array_column($history, 'partial'), true),
            'outcome' => [
                'text' => $sentence !== '' ? $this->trans($sentence) : $this->outcome($status, $attempts, $row['next_retry_at']),
                'terminal' => $sentence !== '' || in_array($status, [LogStatus::SENT, LogStatus::FAILED, LogStatus::SKIPPED], true),
            ],
            'error' => $lastError,
            'error_title' => $this->trans($status === LogStatus::SKIPPED ? 'Reason' : 'Last error'),
            'error_is_reason' => $status === LogStatus::SKIPPED,
            'failure_class' => $status === LogStatus::SKIPPED ? '' : FailureMessage::failureClass($row['last_error']),
            'payload_title' => $this->trans($erased ? 'Payload (erased on a privacy request)' : ($request !== '' ? 'Payload (as sent)' : ($attempted ? 'Payload (queued, nothing was sent for this row)' : 'Payload (queued, not sent yet)'))),
            'payload' => $request !== '' ? $request : PayloadRedactor::forDisplay($row['payload']),
            'response' => PayloadRedactor::forDisplay($row['last_response']),
            'no_response' => $this->trans($erased
                ? 'No response is kept — it was erased on a privacy request.'
                : (!$attempted
                    ? 'No response recorded — this row has not been attempted yet.'
                    : ($request !== '' ? 'No response received — the server did not answer. The last error says why.' : 'No response — nothing was sent for this row.'))),
            'response_is_error' => $status === LogStatus::FAILED || ($attempts > 0 && in_array($status, [LogStatus::PENDING, LogStatus::SENDING], true)),
            'resend' => $refusal === null && $row['kind'] === LogRows::SMAILY,
        ];
    }

    public function statusLabel(string $status): string
    {
        switch ($status) {
            case LogStatus::PENDING:
                return $this->trans('Pending');
            case LogStatus::SENDING:
                return $this->trans('Sending');
            case LogStatus::SENT:
                return $this->trans('Sent');
            case LogStatus::FAILED:
                return $this->trans('Failed');
            case LogStatus::WITHDRAWN:
                return $this->trans('Withdrawn');
            case LogStatus::SKIPPED:
                return $this->trans('Skipped');
            case JobStore::STATUS_QUEUED:
                return $this->trans('Queued');
            case JobStore::STATUS_RUNNING:
                return $this->trans('Running');
            case JobStore::STATUS_DONE:
                return $this->trans('Done');
            case JobStore::STATUS_CANCELLED:
                return $this->trans('Cancelled');
            default:
                return $status;
        }
    }

    public function sourceLabel(string $source): string
    {
        return $source === LogRows::INTELLIGENCE ? $this->trans('Campaign Intelligence') : $this->trans('Smaily');
    }

    /**
     * The row's shown status (LogStatus); a job keeps its stored status.
     *
     * @param array<string, mixed> $row
     */
    private function status(array $row): string
    {
        return $row['kind'] === LogRows::JOB
            ? (string) $row['status']
            : LogStatus::ofQueueRow((string) $row['status'], (string) $row['type'], $row['last_error']);
    }

    /**
     * The Entity: a queue row's as the Dashboard shows it, an import job's number of contacts.
     *
     * @param array<string, mixed> $row
     */
    private function entity(array $row): string
    {
        if ($row['kind'] !== LogRows::JOB) {
            return EntityLabel::forDisplay($row['entity'] === null ? null : (string) $row['entity']);
        }
        $counts = json_decode((string) $row['counts'], true);
        $total = (int) ($counts['total'] ?? 0);

        return $this->trans($total === 1 ? '%count% contact' : '%count% contacts', ['%count%' => $total]);
    }

    /**
     * What happens to a queue row next, by its state.
     */
    private function outcome(string $status, int $attempts, ?string $nextRetryAt): string
    {
        $max = RetryPolicy::MAX_ATTEMPTS;
        switch ($status) {
            case LogStatus::SKIPPED:
                return $this->trans('Skipped — nothing was sent, and this row will not retry: retrying could not change the reason shown below.');
            case LogStatus::SENT:
                return $this->trans('Delivered — no retries needed.');
            case LogStatus::SENDING:
                return $this->trans('A delivery attempt is in progress right now.');
            case LogStatus::FAILED:
                return $attempts < $max
                    ? $this->trans('Stopped after %attempts% of %max% attempts — retrying could not change the outcome, so this row will NOT retry on its own. Fix the cause shown below, then select it in the log and press Retry.', ['%attempts%' => $attempts, '%max%' => $max])
                    : $this->trans('All %max% automatic attempts are used up — this row will NOT retry on its own. Select it in the log and press Retry to queue it again.', ['%max%' => $max]);
            default:
                return $nextRetryAt !== null
                    ? $this->trans('Next automatic retry: %date% (attempt %attempt% of %max%).', ['%date%' => self::time($nextRetryAt), '%attempt%' => $attempts + 1, '%max%' => $max])
                    : $this->trans('Waiting for the next queue flush (runs every minute).');
        }
    }

    /**
     * Where an import job stands, in the words of the import's progress line.
     *
     * @param array<string, mixed> $row
     */
    private function jobOutcome(array $row): string
    {
        $counts = json_decode((string) $row['counts'], true);
        $numbers = [
            '%sent%' => (int) ($counts['sent'] ?? 0),
            '%total%' => (int) ($counts['total'] ?? 0),
        ];
        switch ($row['status']) {
            case JobStore::STATUS_QUEUED:
                return $this->trans('Queued — the import starts on the next scheduled run…');
            case JobStore::STATUS_RUNNING:
                return $this->trans('%sent% of %total%', $numbers);
            case JobStore::STATUS_CANCELLED:
                return $this->trans('Canceled — %sent% of %total% synced. Starting again begins a fresh import.', $numbers);
            case JobStore::STATUS_FAILED:
                return $this->trans('Stopped before an error — %sent% of %total% synced so far. Press Run again to run it again.', $numbers);
            default:
                return (int) ($counts['failed'] ?? 0) > 0
                    ? $this->trans('Done, %sent% of %total% synced — %failed% failed.', $numbers + ['%failed%' => (int) $counts['failed']])
                    : $this->trans('Done, %sent% of %total% synced.', $numbers);
        }
    }

    private function resultLabel(string $result): string
    {
        switch ($result) {
            case AttemptHistory::QUEUED:
                return $this->trans('Queued');
            case AttemptHistory::FAILED:
                return $this->trans('Failed');
            case AttemptHistory::DELIVERED:
                return $this->trans('Delivered');
            case AttemptHistory::SKIPPED:
                return $this->trans('Skipped');
            case AttemptHistory::WITHDRAWN:
                return $this->trans('Withdrawn');
            case AttemptHistory::SCHEDULED:
                return $this->trans('Scheduled');
            case AttemptHistory::WAITING:
                return $this->trans('Waiting');
            default:
                return $this->trans('In progress');
        }
    }

    /**
     * A stored UTC DATETIME in the shop's time zone.
     */
    private static function time(string $utc): string
    {
        return date('Y-m-d H:i:s', (int) strtotime($utc . ' UTC'));
    }

    /**
     * @param array<string, string|int> $parameters
     */
    private function trans(string $id, array $parameters = []): string
    {
        return ($this->translate)($id, $parameters);
    }
}
