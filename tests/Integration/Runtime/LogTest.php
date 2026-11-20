<?php
declare(strict_types=1);

namespace Tests\Integration\Runtime;

use PrestaShop\Module\SmailyForPrestaShop\Runtime\Log;
use Tests\Integration\IntegrationTestCase;

/**
 * A failure is one line in the shop's log that names what failed, the error's class and its place,
 * and never the error's message.
 */
final class LogTest extends IntegrationTestCase
{
    public function testWritesOneLineWithoutTheErrorMessage(): void
    {
        $lastLog = (int) self::db()->getValue('SELECT MAX(`id_log`) FROM `' . _DB_PREFIX_ . 'log`', false);
        $error = new \RuntimeException('a secret message');

        Log::failure('the background job probe', $error, 2);

        $rows = self::db()->executeS('SELECT `message`, `severity` FROM `' . _DB_PREFIX_ . 'log` WHERE `id_log` > ' . $lastLog, true, false) ?: [];
        self::assertCount(1, $rows);
        self::assertSame(
            sprintf('Smaily Connect: the background job probe failed with RuntimeException at LogTest.php:%d', $error->getLine()),
            $rows[0]['message']
        );
        self::assertSame(2, (int) $rows[0]['severity']);
    }
}
