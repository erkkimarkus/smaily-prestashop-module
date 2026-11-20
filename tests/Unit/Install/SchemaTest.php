<?php
declare(strict_types=1);

namespace Tests\Unit\Install;

use PHPUnit\Framework\TestCase;
use PrestaShop\Module\SmailyForPrestaShop\Install\Schema;

/**
 * Install creates every table of the module, with the shop's table prefix and storage engine.
 */
final class SchemaTest extends TestCase
{
    public function testDefinesEveryTableOfTheModule(): void
    {
        self::assertSame([
            'smaily_connect_event_queue',
            'smaily_connect_ingest_queue',
            'smaily_connect_job',
            'smaily_connect_account',
            'smaily_connect_automation_mapping',
            'smaily_connect_cart',
            'smaily_connect_connection',
            'smaily_connect_order_attribution',
            'smaily_connect_privacy',
        ], Schema::tables());
    }

    public function testCreatesEachTableOnlyWhenItIsMissing(): void
    {
        $statements = Schema::createStatements('shop_', 'InnoDB');

        self::assertSame(Schema::tables(), array_keys($statements));
        foreach ($statements as $table => $statement) {
            self::assertStringStartsWith('CREATE TABLE IF NOT EXISTS `shop_' . $table . '` (', $statement);
            self::assertStringEndsWith(') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci', $statement);
        }
    }
}
