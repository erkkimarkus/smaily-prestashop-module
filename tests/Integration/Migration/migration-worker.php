<?php
/**
 * Runs Upgrade300::runPending() in a process of its own against the shop of PS_ROOT_DIR, for the
 * concurrency test of Upgrade300Test:
 *
 *   php migration-worker.php [hold]
 *
 * With "hold", it prints "started" before the first step and waits for a line on its standard input
 * before it goes on. At the end it prints the result of runPending() (1 or 0) and the seconds the
 * call took.
 */
declare(strict_types=1);

use PrestaShop\Module\SmailyForPrestaShop\Config\MigrationNotices;
use PrestaShop\Module\SmailyForPrestaShop\Migration\LegacyConfigMapper;
use PrestaShop\Module\SmailyForPrestaShop\Migration\LegacyFiles;
use PrestaShop\Module\SmailyForPrestaShop\Migration\LegacyOverride;
use PrestaShop\Module\SmailyForPrestaShop\Migration\LegacyReader;
use PrestaShop\Module\SmailyForPrestaShop\Migration\Upgrade300;
use PrestaShop\Module\SmailyForPrestaShop\Runtime\Services;
use PrestaShop\Module\SmailyForPrestaShop\Smaily\AccountStore;

require getenv('PS_ROOT_DIR') . '/config/config.inc.php';
require_once _PS_MODULE_DIR_ . 'smailyforprestashop/vendor/autoload.php';

$hold = ($argv[1] ?? '') === 'hold';
$upgrade = new Upgrade300(
    new LegacyReader(),
    new LegacyConfigMapper(),
    Services::instance()->get(AccountStore::class),
    new MigrationNotices(),
    new LegacyOverride(_PS_OVERRIDE_DIR_),
    // No such folder: the files step works on the module's folder only in a real upgrade.
    new LegacyFiles(sys_get_temp_dir() . '/smaily-no-module-folder'),
    static function () use (&$hold): void {
        if ($hold) {
            $hold = false;
            // Straight to the stream: the shop's bootstrap may buffer what echo prints.
            fwrite(STDOUT, "started\n");
            fgets(STDIN);
        }
    }
);

$start = microtime(true);
$result = $upgrade->runPending();
fwrite(STDOUT, sprintf("%d %.3f\n", $result ? 1 : 0, microtime(true) - $start));
