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

namespace PrestaShop\Module\SmailyForPrestaShop\Log;

if (!defined('_PS_VERSION_')) {
    exit;
}

use PrestaShop\Module\SmailyForPrestaShop\Automation\AbandonedCart\AbandonedCartHandler;
use PrestaShop\Module\SmailyForPrestaShop\Contacts\Import\JobStore;
use PrestaShop\Module\SmailyForPrestaShop\Queue\EventQueue;

/**
 * The status the Log shows for a row, as the Magento connector's Log names it.
 *
 * A queue row: `pending` → Pending, `processing` → Sending, `sent` → Sent, `failed` → Failed,
 * `skipped` → Skipped. An abandoned-cart reminder that was closed because the shopper ordered
 * (`skipped` with the reason `ordered`) shows as Withdrawn: it was neither delivered nor failed, and
 * it is never sent again. An import job keeps its own statuses: Queued, Running, Done, Failed,
 * Cancelled.
 */
final class LogStatus
{
    public const PENDING = 'pending';

    public const SENDING = 'sending';

    public const SENT = 'sent';

    public const FAILED = 'failed';

    public const WITHDRAWN = 'withdrawn';

    public const SKIPPED = 'skipped';

    /** The statuses of a queue row, in the order of the status filter. */
    public const QUEUE = [self::PENDING, self::SENDING, self::SENT, self::FAILED, self::WITHDRAWN, self::SKIPPED];

    /** The statuses of an import job that no queue row has. */
    public const JOB_ONLY = [JobStore::STATUS_QUEUED, JobStore::STATUS_RUNNING, JobStore::STATUS_DONE, JobStore::STATUS_CANCELLED];

    /** The pill each status is drawn with; any other status is grey. */
    private const PILLS = [
        self::PENDING => 'pending',
        self::SENDING => 'pending',
        self::SENT => 'sent',
        self::FAILED => 'failed',
        JobStore::STATUS_QUEUED => 'pending',
        JobStore::STATUS_RUNNING => 'pending',
        JobStore::STATUS_DONE => 'sent',
    ];

    /**
     * The shown status of a queue row.
     */
    public static function ofQueueRow(string $status, string $type, ?string $lastError): string
    {
        if ($status === EventQueue::STATUS_PROCESSING) {
            return self::SENDING;
        }
        if ($status === EventQueue::STATUS_SKIPPED && self::isWithdrawal($type, $lastError)) {
            return self::WITHDRAWN;
        }

        return $status;
    }

    public static function pill(string $status): string
    {
        return self::PILLS[$status] ?? 'neutral';
    }

    /**
     * The SQL condition on a queue table that selects the rows shown with $status, or null when no
     * queue row shows it. $type and $error are the table's quoted type and error columns.
     */
    public static function queueCondition(string $status, string $type, string $error): ?string
    {
        $withdrawn = sprintf(
            '(`status` = \'%s\' AND %s = \'%s\' AND COALESCE(%s, \'\') = \'%s\')',
            EventQueue::STATUS_SKIPPED,
            $type,
            AbandonedCartHandler::EVENT_TYPE,
            $error,
            AbandonedCartHandler::SKIPPED_ORDERED
        );
        switch ($status) {
            case self::SENDING:
                return '`status` = \'' . EventQueue::STATUS_PROCESSING . '\'';
            case self::WITHDRAWN:
                return $withdrawn;
            case self::SKIPPED:
                return '(`status` = \'' . EventQueue::STATUS_SKIPPED . '\' AND NOT ' . $withdrawn . ')';
            case self::PENDING:
            case self::SENT:
            case self::FAILED:
                return '`status` = \'' . $status . '\'';
            default:
                return null;
        }
    }

    private static function isWithdrawal(string $type, ?string $lastError): bool
    {
        return $type === AbandonedCartHandler::EVENT_TYPE && $lastError === AbandonedCartHandler::SKIPPED_ORDERED;
    }
}
