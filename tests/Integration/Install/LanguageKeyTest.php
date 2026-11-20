<?php
declare(strict_types=1);

namespace Tests\Integration\Install;

use Tests\Integration\IntegrationTestCase;

/**
 * An account or a mapping row without a language holds '' and stays unique per shop: the database
 * refuses a second one.
 */
final class LanguageKeyTest extends IntegrationTestCase
{
    private const SHOP = 990;

    protected function setUp(): void
    {
        parent::setUp();
        $this->clean();
    }

    protected function tearDown(): void
    {
        if (defined('_PS_ROOT_DIR_')) {
            $this->clean();
        }
        parent::tearDown();
    }

    public function testASecondAccountWithoutALanguageIsRefused(): void
    {
        $row = '(' . self::SHOP . ', \'demo\', \'api-user\', \'x\', NOW(), NOW())';
        $insert = 'INSERT INTO `' . _DB_PREFIX_ . 'smaily_connect_account`'
            . ' (`id_shop`, `subdomain`, `username`, `password_enc`, `date_add`, `date_upd`) VALUES ' . $row;

        self::assertTrue(self::db()->execute($insert));
        self::assertSame('', self::db()->getValue(
            'SELECT `language` FROM `' . _DB_PREFIX_ . 'smaily_connect_account` WHERE `id_shop` = ' . self::SHOP
        ));
        self::assertFalse($this->executes($insert));
        self::assertSame(1, $this->rows('smaily_connect_account'));
    }

    public function testASecondMappingWithoutALanguageIsRefused(): void
    {
        $insert = 'INSERT INTO `' . _DB_PREFIX_ . 'smaily_connect_automation_mapping`'
            . ' (`id_shop`, `trigger`, `account_key`, `workflow_id`, `date_upd`)'
            . ' VALUES (' . self::SHOP . ', \'welcome\', \'default\', 7, NOW())';

        self::assertTrue(self::db()->execute($insert));
        self::assertFalse($this->executes($insert));
        self::assertSame(1, $this->rows('smaily_connect_automation_mapping'));
    }

    /**
     * Db reports a failed statement as false, or throws: a PrestaShopDatabaseException in debug
     * mode, a PrestaShopException from PDO on PrestaShop 8.0.
     */
    private function executes(string $sql): bool
    {
        try {
            return (bool) self::db()->execute($sql);
        } catch (\PrestaShopException $exception) {
            return false;
        }
    }

    private function rows(string $table): int
    {
        return (int) self::db()->getValue(
            'SELECT COUNT(*) FROM `' . _DB_PREFIX_ . $table . '` WHERE `id_shop` = ' . self::SHOP
        );
    }

    private function clean(): void
    {
        foreach (['smaily_connect_account', 'smaily_connect_automation_mapping'] as $table) {
            self::db()->execute('DELETE FROM `' . _DB_PREFIX_ . $table . '` WHERE `id_shop` = ' . self::SHOP);
        }
    }
}
