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

namespace PrestaShop\Module\SmailyForPrestaShop\Install;

if (!defined('_PS_VERSION_')) {
    exit;
}

use PrestaShop\Module\SmailyForPrestaShop\Database\Sql;
use PrestaShop\Module\SmailyForPrestaShop\Database\SqlException;

/**
 * The module's tables and the schema version that the runtime needs.
 *
 * A column change after release is a new upgrade script that raises VERSION, never an edit of a
 * table here that a store already has. While the stored version differs from VERSION, the hook
 * listeners do nothing except those registered to run before the upgrade finishes (the fallback
 * tick's), and the tick runs only the jobs made to run before it (the pending migration).
 */
final class Schema
{
    public const VERSION = '3.0.0';

    public const VERSION_KEY = 'SMAILY_CONNECT_SCHEMA_VERSION';

    /**
     * Columns and indexes of each table, without the prefix.
     *
     * The `language` of an account or a mapping row is '' for "no language" (the shop's account, the
     * default row), never NULL: MySQL lets NULL repeat under a unique key.
     */
    private const TABLES = [
        'smaily_connect_event_queue' => '
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `id_shop` INT UNSIGNED NOT NULL,
            `event_type` VARCHAR(48) NOT NULL,
            `entity_id` VARCHAR(64) NULL,
            `language` CHAR(2) NULL,
            `payload` MEDIUMTEXT NOT NULL,
            `status` VARCHAR(16) NOT NULL DEFAULT \'pending\',
            `attempts` TINYINT UNSIGNED NOT NULL DEFAULT 0,
            `next_retry_at` DATETIME NULL,
            `claim_token` CHAR(32) NULL,
            `last_error` VARCHAR(255) NULL,
            `last_request` MEDIUMTEXT NULL,
            `last_response` TEXT NULL,
            `created_at` DATETIME NOT NULL,
            `updated_at` DATETIME NOT NULL,
            `sent_at` DATETIME NULL,
            PRIMARY KEY (`id`),
            KEY `status_next_retry_at` (`status`, `next_retry_at`),
            KEY `entity_id_event_type_status` (`entity_id`, `event_type`, `status`),
            KEY `created_at` (`created_at`)',
        'smaily_connect_ingest_queue' => '
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `id_connection` INT UNSIGNED NOT NULL,
            `domain` VARCHAR(16) NOT NULL,
            `entity_key` VARCHAR(80) NOT NULL,
            `payload` MEDIUMTEXT NULL,
            `status` VARCHAR(16) NOT NULL DEFAULT \'pending\',
            `attempts` TINYINT UNSIGNED NOT NULL DEFAULT 0,
            `next_retry_at` DATETIME NULL,
            `claim_token` CHAR(32) NULL,
            `last_error` VARCHAR(255) NULL,
            `last_request` MEDIUMTEXT NULL,
            `last_response` TEXT NULL,
            `created_at` DATETIME NOT NULL,
            `updated_at` DATETIME NOT NULL,
            `sent_at` DATETIME NULL,
            PRIMARY KEY (`id`),
            KEY `domain_status_next_retry_at` (`domain`, `status`, `next_retry_at`),
            KEY `entity_key_domain_status` (`entity_key`, `domain`, `status`),
            KEY `created_at` (`created_at`)',
        'smaily_connect_job' => '
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `type` VARCHAR(32) NOT NULL,
            `id_shop` INT UNSIGNED NOT NULL,
            `id_connection` INT UNSIGNED NULL,
            `status` VARCHAR(16) NOT NULL DEFAULT \'queued\',
            `cursor` VARCHAR(255) NULL,
            `counts` TEXT NULL,
            `import_id` VARCHAR(64) NULL,
            `started_at` DATETIME NULL,
            `updated_at` DATETIME NOT NULL,
            `finished_at` DATETIME NULL,
            `last_error` VARCHAR(255) NULL,
            PRIMARY KEY (`id`),
            KEY `status_type` (`status`, `type`)',
        'smaily_connect_account' => '
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `id_shop` INT UNSIGNED NOT NULL DEFAULT 0,
            `language` CHAR(2) NOT NULL DEFAULT \'\',
            `subdomain` VARCHAR(63) NOT NULL,
            `username` VARCHAR(128) NOT NULL,
            `password_enc` TEXT NOT NULL,
            `date_add` DATETIME NOT NULL,
            `date_upd` DATETIME NOT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `id_shop_language` (`id_shop`, `language`)',
        'smaily_connect_automation_mapping' => '
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `id_shop` INT UNSIGNED NOT NULL DEFAULT 0,
            `trigger` VARCHAR(32) NOT NULL,
            `language` CHAR(2) NOT NULL DEFAULT \'\',
            `account_key` VARCHAR(16) NOT NULL,
            `workflow_id` INT UNSIGNED NULL,
            `optin_trigger` TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
            `is_default_fallback` TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
            `date_upd` DATETIME NOT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `id_shop_trigger_language_account_key` (`id_shop`, `trigger`, `language`, `account_key`)',
        'smaily_connect_cart' => '
            `id_cart` INT UNSIGNED NOT NULL,
            `id_shop` INT UNSIGNED NOT NULL,
            `id_customer` INT UNSIGNED NULL,
            `email` VARCHAR(255) NULL,
            `id_lang` INT UNSIGNED NOT NULL,
            `status` VARCHAR(16) NOT NULL DEFAULT \'open\',
            `last_activity_at` DATETIME NOT NULL,
            `mailed_at` DATETIME NULL,
            `checkout_optin` TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
            `event_id` BIGINT UNSIGNED NULL,
            `skip_reason` VARCHAR(64) NULL,
            PRIMARY KEY (`id_cart`),
            KEY `status_last_activity_at` (`status`, `last_activity_at`),
            KEY `email` (`email`)',
        'smaily_connect_connection' => '
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `id_shop` INT UNSIGNED NOT NULL DEFAULT 0,
            `smaily_subdomain` VARCHAR(63) NOT NULL,
            `tenant_id` CHAR(36) NOT NULL,
            `api_key_enc` TEXT NOT NULL,
            `settings` MEDIUMTEXT NOT NULL,
            `date_add` DATETIME NOT NULL,
            `date_upd` DATETIME NOT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `id_shop` (`id_shop`)',
        'smaily_connect_order_attribution' => '
            `id_order` INT UNSIGNED NOT NULL,
            `rec_id` CHAR(36) NULL,
            `context` VARCHAR(64) NULL,
            `visitor_token` VARCHAR(64) NULL,
            `created_at` DATETIME NOT NULL,
            PRIMARY KEY (`id_order`)',
        'smaily_connect_privacy' => '
            `address_key` CHAR(64) NOT NULL,
            `opted_out` TINYINT(1) UNSIGNED NOT NULL,
            `changed_at` DATETIME NOT NULL,
            `source` VARCHAR(16) NOT NULL,
            PRIMARY KEY (`address_key`)',
    ];

    /**
     * @return list<string> the table names without the prefix
     */
    public static function tables(): array
    {
        return array_keys(self::TABLES);
    }

    /**
     * @return array<string, string> one CREATE TABLE IF NOT EXISTS statement per table name
     */
    public static function createStatements(string $prefix, string $engine): array
    {
        $statements = [];
        foreach (self::TABLES as $table => $definition) {
            $statements[$table] = sprintf(
                'CREATE TABLE IF NOT EXISTS `%s%s` (%s
        ) ENGINE=%s DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci',
                $prefix,
                $table,
                $definition,
                $engine
            );
        }

        return $statements;
    }

    public static function create(): bool
    {
        try {
            foreach (self::createStatements(_DB_PREFIX_, _MYSQL_ENGINE_) as $statement) {
                Sql::write($statement);
            }
        } catch (SqlException $error) {
            return false;
        }

        return true;
    }

    public static function drop(): bool
    {
        try {
            foreach (self::tables() as $table) {
                Sql::write('DROP TABLE IF EXISTS `' . _DB_PREFIX_ . $table . '`');
            }
        } catch (SqlException $error) {
            return false;
        }

        return true;
    }

    public static function isCurrent(): bool
    {
        return \Configuration::getGlobalValue(self::VERSION_KEY) === self::VERSION;
    }

    public static function markCurrent(): bool
    {
        return \Configuration::updateGlobalValue(self::VERSION_KEY, self::VERSION);
    }
}
