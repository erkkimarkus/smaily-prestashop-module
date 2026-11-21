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

namespace PrestaShop\Module\SmailyForPrestaShop\Scheduler;

if (!defined('_PS_VERSION_')) {
    exit;
}

use PrestaShop\Module\SmailyForPrestaShop\Discount\IssuedCodes;
use PrestaShop\Module\SmailyForPrestaShop\Queue\EventQueue;

/**
 * The retention sweep of the event queue, as on the sibling connectors: a sent or skipped row is
 * kept 30 days and a failed row 90 days after its last change. Pending and processing rows are
 * never deleted. The job table is not swept, as on Magento: the card of the first import reads
 * the newest import job. Last, it removes the discount codes of the store automations that nobody
 * used, 30 days after their end date (Discount\IssuedCodes).
 */
final class QueueJanitor
{
    public const BATCH_SIZE = 1000;

    /**
     * Seconds each closed status is kept.
     */
    public const RETENTION = [
        EventQueue::STATUS_SENT => 30 * 86400,
        EventQueue::STATUS_SKIPPED => 30 * 86400,
        EventQueue::STATUS_FAILED => 90 * 86400,
    ];

    private EventQueue $queue;

    private IssuedCodes $codes;

    private int $batchSize;

    public function __construct(EventQueue $queue, IssuedCodes $codes, int $batchSize = self::BATCH_SIZE)
    {
        $this->queue = $queue;
        $this->codes = $codes;
        $this->batchSize = $batchSize;
    }

    /**
     * Deletes batches of rows past retention until none is left or the deadline passes.
     *
     * @return int the number of rows and discount codes deleted
     */
    public function run(Deadline $deadline): int
    {
        $deleted = 0;
        foreach (self::RETENTION as $status => $age) {
            do {
                $batch = $this->queue->deleteClosed($status, $age, $this->batchSize);
                $deleted += $batch;
                if ($deadline->passed()) {
                    return $deleted;
                }
            } while ($batch === $this->batchSize);
        }

        return $deleted + $this->unusedDiscountCodes($deadline);
    }

    /**
     * Removes batches of the unused discount codes past their keeping time (IssuedCodes) until
     * none is left or the deadline passes.
     */
    private function unusedDiscountCodes(Deadline $deadline): int
    {
        $deleted = 0;
        do {
            $batch = $deadline->passed() ? 0 : $this->codes->deleteExpiredUnused();
            $deleted += $batch;
        } while ($batch === IssuedCodes::BATCH_SIZE);

        return $deleted;
    }
}
