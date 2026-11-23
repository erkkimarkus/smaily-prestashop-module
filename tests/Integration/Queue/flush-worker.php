<?php
/**
 * One flushing process for ConcurrentFlushTest: boots the shop at PS_ROOT_DIR, prints "ready",
 * reads the start time (a Unix time, one line) from its input and waits until then, then flushes
 * the event queue in small batches until nothing is due. Its handler "sends" a row of the given
 * event type by appending the row id to the output file. Prints the number of rows it claimed.
 *
 *   php flush-worker.php <output file> <event type>
 */
declare(strict_types=1);

use PrestaShop\Module\SmailyForPrestaShop\Queue\EventQueue;
use PrestaShop\Module\SmailyForPrestaShop\Queue\Flusher;
use PrestaShop\Module\SmailyForPrestaShop\Queue\HandlerInterface;

[, $output, $eventType] = $argv;

require getenv('PS_ROOT_DIR') . '/config/config.inc.php';
require_once _PS_MODULE_DIR_ . 'smailyforprestashop/vendor/autoload.php';

$handler = new class($output) implements HandlerInterface {
    private string $output;

    public function __construct(string $output)
    {
        $this->output = $output;
    }

    public function handle(array $events): array
    {
        $outcomes = [];
        foreach ($events as $row) {
            file_put_contents($this->output, $row['id'] . "\n", FILE_APPEND | LOCK_EX);
            usleep(1000);
            $outcomes[(int) $row['id']] = true;
        }

        return $outcomes;
    }
};
$flusher = new Flusher(new EventQueue(), fn (string $type): ?HandlerInterface => $type === $eventType ? $handler : null);

// A flush that loses every row of its batch to the other one claims none, so the worker goes on
// while any row is still pending.
$pending = sprintf(
    'SELECT COUNT(*) FROM `%s%s` WHERE `status` = \'%s\'',
    _DB_PREFIX_,
    EventQueue::TABLE,
    EventQueue::STATUS_PENDING
);
// The test names the start only after every worker is ready, however long a boot took.
fwrite(STDOUT, "ready\n");
$wait = (float) fgets(STDIN) - microtime(true);
if ($wait > 0) {
    usleep((int) ($wait * 1000000));
}
$claimed = 0;
do {
    $claimed += $flusher->flush(3);
} while ((int) Db::getInstance()->getValue($pending, false) > 0);
fwrite(STDOUT, (string) $claimed);
