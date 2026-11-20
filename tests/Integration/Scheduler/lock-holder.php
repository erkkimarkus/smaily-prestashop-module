<?php
/**
 * Holds the tick's lock for TickTest in a process and a database connection of its own: boots the
 * shop at PS_ROOT_DIR, takes the lock, prints "locked", and keeps the lock until its standard input
 * closes.
 *
 *   php lock-holder.php
 */
declare(strict_types=1);

use PrestaShop\Module\SmailyForPrestaShop\Scheduler\Lock;

require getenv('PS_ROOT_DIR') . '/config/config.inc.php';
require_once _PS_MODULE_DIR_ . 'smailyforprestashop/vendor/autoload.php';

$lock = new Lock();
if (!$lock->acquire()) {
    fwrite(STDERR, 'The lock is taken.');
    exit(1);
}
// Straight to the stream: the shop's bootstrap may buffer what echo prints.
fwrite(STDOUT, "locked\n");
stream_get_contents(STDIN);
$lock->release();
