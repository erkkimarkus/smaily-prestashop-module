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

namespace PrestaShop\Module\SmailyForPrestaShop\Automation;

if (!defined('_PS_VERSION_')) {
    exit;
}

use PrestaShop\Module\SmailyForPrestaShop\Database\Sql;

/**
 * The rows of the automation mapping table: which Smaily workflow a trigger starts, per shop (0 =
 * all shops), language ('' = no language) and account key ('default' = the shop's account of no
 * language, else the language whose own account sends it, mode `a`). The one place that reads and
 * writes the table.
 *
 * A row's workflow id is NULL with `optin_trigger` 1 where the welcome keeps 2.x's generic opt-in
 * trigger (no workflow chosen; Smaily starts every "subscriber opted in" workflow). A shop's row
 * with a NULL workflow id and `optin_trigger` 0 says "no workflow here": it hides the shared row of
 * the same trigger, language and account key from that shop (setNone()), as a website's stored "0"
 * hides the store-wide workflow in the Magento connector. A write names its row by the table's
 * unique key (id_shop, trigger, language, account_key).
 *
 * Not final: the unit tests stand in for the table.
 */
class MappingRepository
{
    public const ACCOUNT_DEFAULT = 'default';

    private const TABLE = 'smaily_connect_automation_mapping';

    /**
     * The rows stored at exactly one shop scope (0 = the rows all shops share), of one trigger or of
     * all.
     *
     * @return list<array{id: int, id_shop: int, trigger: string, language: string, account_key: string, workflow_id: int|null, optin_trigger: bool, is_default_fallback: bool}>
     */
    public function rows(int $idShop, ?string $trigger = null): array
    {
        $rows = Sql::rows(
            'SELECT `id`, `id_shop`, `trigger`, `language`, `account_key`, `workflow_id`, `optin_trigger`, `is_default_fallback`'
            . ' FROM `' . _DB_PREFIX_ . self::TABLE . '`'
            . ' WHERE `id_shop` = ' . $idShop
            . ($trigger === null ? '' : ' AND `trigger` = \'' . pSQL($trigger) . '\'')
            . ' ORDER BY `id`'
        );

        return array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'id_shop' => (int) $row['id_shop'],
            'trigger' => (string) $row['trigger'],
            'language' => (string) $row['language'],
            'account_key' => (string) $row['account_key'],
            'workflow_id' => $row['workflow_id'] === null ? null : (int) $row['workflow_id'],
            'optin_trigger' => (bool) $row['optin_trigger'],
            'is_default_fallback' => (bool) $row['is_default_fallback'],
        ], $rows);
    }

    /**
     * Writes the row of (shop, trigger, language, account key), replacing the one stored before.
     *
     * @param int|null $workflowId a Smaily workflow id; null only with $optinTrigger, the welcome's
     *                             generic opt-in trigger of 2.x
     */
    public function upsert(int $idShop, string $trigger, string $language, string $accountKey, ?int $workflowId, bool $optinTrigger = false, bool $isDefaultFallback = false): bool
    {
        if (!in_array($trigger, Trigger::ALL, true)
            || preg_match('/^([a-z]{2})?$/', $language) !== 1
            || preg_match('/^(default|[a-z]{2})$/', $accountKey) !== 1
            || ($workflowId === null) !== $optinTrigger
            || ($workflowId !== null && $workflowId <= 0)
            || ($optinTrigger && $trigger !== Trigger::WELCOME)
        ) {
            throw new \InvalidArgumentException('Not a valid automation mapping row.');
        }

        Sql::write(
            'INSERT INTO `' . _DB_PREFIX_ . self::TABLE . '`'
            . ' (`id_shop`, `trigger`, `language`, `account_key`, `workflow_id`, `optin_trigger`, `is_default_fallback`, `date_upd`)'
            . ' VALUES (' . $idShop
            . ', \'' . pSQL($trigger) . '\''
            . ', \'' . pSQL($language) . '\''
            . ', \'' . pSQL($accountKey) . '\''
            . ', ' . ($workflowId === null ? 'NULL' : $workflowId)
            . ', ' . (int) $optinTrigger
            . ', ' . (int) $isDefaultFallback
            . ', NOW())'
            . ' ON DUPLICATE KEY UPDATE `workflow_id` = VALUES(`workflow_id`), `optin_trigger` = VALUES(`optin_trigger`),'
            . ' `is_default_fallback` = VALUES(`is_default_fallback`), `date_upd` = VALUES(`date_upd`)'
        );

        return true;
    }

    /**
     * Writes a shop's row of (trigger, language, account key) as "no workflow": the shop then starts
     * nothing through the shared row of that key. Only a shop holds such a row; the rows all shops
     * share (0) have nothing to hide.
     */
    public function setNone(int $idShop, string $trigger, string $language, string $accountKey): bool
    {
        if ($idShop <= 0
            || !in_array($trigger, Trigger::ALL, true)
            || preg_match('/^([a-z]{2})?$/', $language) !== 1
            || preg_match('/^(default|[a-z]{2})$/', $accountKey) !== 1
        ) {
            throw new \InvalidArgumentException('Not a valid automation mapping row.');
        }

        Sql::write(
            'INSERT INTO `' . _DB_PREFIX_ . self::TABLE . '`'
            . ' (`id_shop`, `trigger`, `language`, `account_key`, `workflow_id`, `optin_trigger`, `is_default_fallback`, `date_upd`)'
            . ' VALUES (' . $idShop
            . ', \'' . pSQL($trigger) . '\''
            . ', \'' . pSQL($language) . '\''
            . ', \'' . pSQL($accountKey) . '\''
            . ', NULL, 0, 0, NOW())'
            . ' ON DUPLICATE KEY UPDATE `workflow_id` = NULL, `optin_trigger` = 0, `is_default_fallback` = 0, `date_upd` = VALUES(`date_upd`)'
        );

        return true;
    }

    /**
     * Deletes a shop scope's rows of one account key that hold a workflow: their workflows were
     * picked from the Smaily account that the key named until now. The welcome's generic opt-in
     * row and a shop's rows of "no workflow" name no workflow of any account and stay.
     */
    public function removeWorkflows(int $idShop, string $accountKey): bool
    {
        Sql::write(
            'DELETE FROM `' . _DB_PREFIX_ . self::TABLE . '`'
            . ' WHERE `id_shop` = ' . $idShop
            . ' AND `account_key` = \'' . pSQL($accountKey) . '\''
            . ' AND `workflow_id` IS NOT NULL'
        );

        return true;
    }

    /**
     * Deletes the row of (shop, trigger, language, account key), if one is stored.
     */
    public function delete(int $idShop, string $trigger, string $language, string $accountKey): bool
    {
        Sql::write(
            'DELETE FROM `' . _DB_PREFIX_ . self::TABLE . '`'
            . ' WHERE `id_shop` = ' . $idShop
            . ' AND `trigger` = \'' . pSQL($trigger) . '\''
            . ' AND `language` = \'' . pSQL($language) . '\''
            . ' AND `account_key` = \'' . pSQL($accountKey) . '\''
        );

        return true;
    }
}
