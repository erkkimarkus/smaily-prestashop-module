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

use PrestaShop\Module\SmailyForPrestaShop\Automation\AbandonedCart\CartTracker;
use PrestaShop\Module\SmailyForPrestaShop\Discount\IssuedCodes;
use PrestaShop\Module\SmailyForPrestaShop\Engine\Ingest\IngestQueue;
use PrestaShop\Module\SmailyForPrestaShop\Queue\EventQueue;

/**
 * The retention sweep of the event queue and of the Campaign Intelligence ingest queue, as on the
 * sibling connectors: a sent or skipped row is kept 30 days and a failed row 90 days after its
 * last change. Pending and processing rows are never deleted. It then deletes the closed rows of the abandoned-cart tracker 30 days after the
 * cart's last change (CartTracker::deleteClosed()); it never reads 2.x's `smaily_cart`. The job
 * table is not swept, as on Magento: the card of the first import reads the newest import job.
 * Last, it removes the discount codes of the store automations that nobody used, 30 days after
 * their end date (Discount\IssuedCodes).
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

    private CartTracker $carts;

    private IssuedCodes $codes;

    private int $batchSize;

    private ?IngestQueue $ingest;

    /**
     * @param IngestQueue|null $ingest the Campaign Intelligence ingest queue, swept by the same
     *                                 periods as the event queue
     */
    public function __construct(EventQueue $queue, CartTracker $carts, IssuedCodes $codes, int $batchSize = self::BATCH_SIZE, ?IngestQueue $ingest = null)
    {
        $this->queue = $queue;
        $this->carts = $carts;
        $this->codes = $codes;
        $this->batchSize = $batchSize;
        $this->ingest = $ingest;
    }

    /**
     * Deletes batches of rows past retention until none is left or the deadline passes.
     *
     * @return int the number of rows, carts and discount codes deleted
     */
    public function run(Deadline $deadline): int
    {
        $deleted = 0;
        foreach (array_filter([$this->queue, $this->ingest]) as $queue) {
            foreach (self::RETENTION as $status => $age) {
                do {
                    $batch = $queue->deleteClosed($status, $age, $this->batchSize);
                    $deleted += $batch;
                    if ($deadline->passed()) {
                        return $deleted;
                    }
                } while ($batch === $this->batchSize);
            }
        }

        return $deleted + $this->closedCarts($deadline) + $this->unusedDiscountCodes($deadline);
    }

    /**
     * Deletes batches of the tracker's closed rows past their keeping time (CartTracker) until
     * none is left or the deadline passes.
     */
    private function closedCarts(Deadline $deadline): int
    {
        $deleted = 0;
        do {
            $batch = $deadline->passed() ? 0 : $this->carts->deleteClosed($this->batchSize);
            $deleted += $batch;
        } while ($batch === $this->batchSize);

        return $deleted;
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
