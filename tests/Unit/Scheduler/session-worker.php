<?php
/**
 * A process of its own that starts a PHP session before anything is printed, runs the fallback
 * tick's after-response step with a stand-in tick, and prints the session status that the tick saw
 * (PHP_SESSION_NONE = 1, PHP_SESSION_ACTIVE = 2). It boots no shop.
 */
declare(strict_types=1);

define('_PS_VERSION_', '0.0.0');
require __DIR__ . '/../../../src/vendor/autoload.php';

use PrestaShop\Module\SmailyForPrestaShop\Config\TickSettings;
use PrestaShop\Module\SmailyForPrestaShop\Scheduler\TrafficTick;

ini_set('session.use_cookies', '0');
ini_set('session.cache_limiter', '');
ini_set('session.save_path', sys_get_temp_dir());
session_start();

$seen = null;
$tick = new class($seen) {
    /** @var int|null */
    private $seen;

    public function __construct(?int &$seen)
    {
        $this->seen = &$seen;
    }

    public function run(): void
    {
        $this->seen = session_status();
    }
};
(new TrafficTick(new TickSettings(), static fn () => $tick, static fn (): int => 0, static function (): void {
}, static function (): void {
}))->afterResponse();
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
session_destroy();

fwrite(STDOUT, (string) $seen);
