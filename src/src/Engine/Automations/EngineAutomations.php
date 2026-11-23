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

namespace PrestaShop\Module\SmailyForPrestaShop\Engine\Automations;

if (!defined('_PS_VERSION_')) {
    exit;
}

use PrestaShop\Module\SmailyForPrestaShop\Automation\WorkflowList;
use PrestaShop\Module\SmailyForPrestaShop\Engine\Client;
use PrestaShop\Module\SmailyForPrestaShop\Engine\Connection;
use PrestaShop\Module\SmailyForPrestaShop\Engine\Connections;
use PrestaShop\Module\SmailyForPrestaShop\Engine\Exception\EngineException;
use PrestaShop\Module\SmailyForPrestaShop\Engine\Exception\EngineRequestException;
use PrestaShop\Module\SmailyForPrestaShop\Engine\HealthCheck;
use PrestaShop\Module\SmailyForPrestaShop\Smaily\Account;
use PrestaShop\Module\SmailyForPrestaShop\Smaily\AccountStore;

/**
 * The automated emails that Campaign Intelligence runs itself (contract §11 to §13), for the
 * connection that serves a shop: read for the Automations tab, and saved from it. The module keeps
 * no copy: every read asks the engine, and the engine's answer is what the page shows.
 *
 * - The list is the engine's catalogue of that moment with the store's configuration rows. While
 *   either cannot be read, nothing is listed and nothing can be saved, so no stored row is
 *   replaced by a default.
 * - The workflows offered are those of the Smaily account that the connection belongs to
 *   (Connection::smailySubdomain()), among the accounts of the shops that the connection serves.
 * - A save sends every row of the page with all eight keys, as the Magento connector does
 *   (ConfigRow). An enabled row without a workflow is not sent, and neither is any other row of
 *   that save. The engine checks all rows before it writes any.
 * - A refusal of the connection itself (401, 403, 409) is checked with the health check, as the
 *   ingest does: a refused connection shows as refused, not as a failed read.
 *
 * Nothing here reads the engine's messages: a failure is the exception with its HTTP status and
 * code, and a refused field is its row's key and the field's name.
 */
final class EngineAutomations
{
    /** The shop has no connection, or its stored key does not decrypt. */
    public const NOT_CONNECTED = 'not_connected';

    /** The engine does not take the connection (Connection::isTaken()). */
    public const REFUSED = 'refused';

    /** The catalogue or the configuration could not be read. */
    public const UNAVAILABLE = 'unavailable';

    /** The list was read. */
    public const READY = 'ready';

    /** A save went through. */
    public const SAVED = 'saved';

    /** The page posted no row. */
    public const NOTHING = 'nothing';

    /** A row has a field at fault; nothing was saved. */
    public const INVALID = 'invalid';

    /** The save did not go through; nothing was saved. */
    public const FAILED = 'failed';

    /** The fault of a row that is enabled without a workflow. */
    public const FAULT_WORKFLOW_REQUIRED = 'workflow_required';

    /** The fault of a field that the engine refused. */
    public const FAULT_REFUSED = 'refused';

    private Client $client;

    private Connections $connections;

    private HealthCheck $health;

    private AccountStore $accounts;

    private WorkflowList $workflows;

    public function __construct(Client $client, Connections $connections, HealthCheck $health, AccountStore $accounts, WorkflowList $workflows)
    {
        $this->client = $client;
        $this->connections = $connections;
        $this->health = $health;
        $this->accounts = $accounts;
        $this->workflows = $workflows;
    }

    /**
     * What the card shows for a shop: `state` is NOT_CONNECTED, REFUSED, UNAVAILABLE (with the
     * `failure`) or READY. READY carries the `rows` (AutomationList::rows()), the `account` (the
     * connection's Smaily account, '' when it names none), the `workflows` of that account and,
     * when their list could not be loaded, the reason code of WorkflowList as `workflow_error`.
     *
     * @param int $idShop the shop whose connection is asked (Connections::forShop())
     * @param string $language the employee's language, an ISO 639-1 code
     *
     * @return array{state: string, failure?: EngineException, rows?: list<array<string, mixed>>, account?: string, workflows?: list<array{id: int, name: string}>, workflow_error?: string|null}
     */
    public function view(int $idShop, string $language): array
    {
        return $this->read($idShop, $language, null);
    }

    /**
     * view(), with the workflow list of the connection's account when the caller read it already.
     *
     * @param array{workflows: list<array{id: int, name: string}>, error: string|null}|null $list
     *
     * @return array<string, mixed>
     */
    private function read(int $idShop, string $language, ?array $list): array
    {
        $connection = $this->connection($idShop);
        if (is_string($connection)) {
            return ['state' => $connection];
        }

        try {
            $rows = AutomationList::rows(
                $this->client->automationsCatalog($connection),
                $this->client->automationsConfig($connection),
                $language
            );
        } catch (EngineException $failure) {
            return $this->health->refuses($connection, $failure) ? ['state' => self::REFUSED] : ['state' => self::UNAVAILABLE, 'failure' => $failure];
        }
        $list = $list ?? $this->workflows->of($this->account($connection));

        return [
            'state' => self::READY,
            'rows' => $rows,
            'account' => $connection->smailySubdomain(),
            'workflows' => $list['workflows'],
            'workflow_error' => $list['error'],
        ];
    }

    /**
     * Saves the rows that the page posted, by catalogue key (ConfigRow::normalize() names a row's
     * fields). `outcome` is:
     * - SAVED, with the `view` that the engine answers now and `kept_in_test`: whether a row asked
     *   for real sends and the engine keeps it in test mode;
     * - INVALID, with the `faults`: each a row's `key`, its `field` and the `fault`
     *   (FAULT_WORKFLOW_REQUIRED, or FAULT_REFUSED for a field that the engine refused);
     * - FAILED, with the `failure`;
     * - NOTHING, NOT_CONNECTED or REFUSED.
     * Nothing was saved unless the outcome is SAVED.
     *
     * @param array<mixed> $posted
     *
     * @return array{outcome: string, view?: array<string, mixed>, kept_in_test?: bool, faults?: list<array{key: string, field: string, fault: string}>, failure?: EngineException}
     */
    public function save(int $idShop, array $posted, string $language): array
    {
        $connection = $this->connection($idShop);
        if (is_string($connection)) {
            return ['outcome' => $connection];
        }

        $list = $this->workflows->of($this->account($connection));
        $offered = array_map('strval', array_column($list['workflows'], 'id'));
        $rows = [];
        $faults = [];
        foreach ($posted as $key => $data) {
            // PHP reads a key of digits as a number.
            $key = (string) $key;
            if (preg_match(AutomationList::KEY_PATTERN, $key) !== 1 || !is_array($data)) {
                continue;
            }
            $row = ConfigRow::normalize($key, $data, $offered);
            // The workflow that the row's select shows: an enabled row needs one, as in Magento.
            if ($row['enabled'] && ($row['automation_map']['id'] ?? $row['automation_map']['fallback'] ?? '') === '') {
                $faults[] = ['key' => $key, 'field' => 'workflow_id', 'fault' => self::FAULT_WORKFLOW_REQUIRED];
            }
            $rows[] = $row;
        }
        if ($rows === []) {
            return ['outcome' => self::NOTHING];
        }
        if ($faults !== []) {
            return ['outcome' => self::INVALID, 'faults' => $faults];
        }

        try {
            // An empty map is sent as `{}`: the contract's map is an object.
            $this->client->saveAutomationsConfig($connection, array_map(
                static fn (array $row): array => array_replace($row, ['automation_map' => (object) $row['automation_map']]),
                $rows
            ));
        } catch (EngineRequestException $refusal) {
            if ($this->health->refuses($connection, $refusal)) {
                return ['outcome' => self::REFUSED];
            }
            foreach ($refusal->fields() as $field) {
                $key = $field['key'] !== '' ? $field['key'] : ($field['index'] !== null ? ($rows[$field['index']]['trigger_key'] ?? '') : '');
                if ($key !== '') {
                    $faults[] = ['key' => $key, 'field' => explode('.', $field['field'])[0], 'fault' => self::FAULT_REFUSED];
                }
            }

            return $faults !== [] ? ['outcome' => self::INVALID, 'faults' => $faults] : ['outcome' => self::FAILED, 'failure' => $refusal];
        } catch (EngineException $failure) {
            return ['outcome' => self::FAILED, 'failure' => $failure];
        }

        $view = $this->read($idShop, $language, $list);
        $stored = array_column($view['rows'] ?? [], null, 'key');
        $keptInTest = false;
        foreach ($rows as $row) {
            $now = $stored[$row['trigger_key']] ?? null;
            $keptInTest = $keptInTest || ($row['enabled'] && !$row['test_mode'] && $now !== null && $now['enabled'] && $now['test_mode']);
        }

        return ['outcome' => self::SAVED, 'view' => $view, 'kept_in_test' => $keptInTest];
    }

    /**
     * The connection that serves the shop, or why it cannot be asked: NOT_CONNECTED or REFUSED.
     *
     * @return Connection|string
     */
    private function connection(int $idShop)
    {
        $connection = $this->connections->forShop($idShop);
        if ($connection === null || $connection->apiKey() === '') {
            return self::NOT_CONNECTED;
        }

        return $connection->isTaken() ? $connection : self::REFUSED;
    }

    /**
     * The stored Smaily account that the connection belongs to: the first account with the
     * connection's subdomain among the shops that the connection serves (every shop for the
     * installation-wide connection), the account of no language before a language's. A connection
     * that names no account takes the account of no language of its own shop.
     */
    private function account(Connection $connection): ?Account
    {
        $subdomain = $connection->smailySubdomain();
        if ($subdomain === '') {
            return $this->accounts->find($connection->idShop());
        }
        foreach ($this->accounts->servedBy($connection->idShop()) as $account) {
            if (strtolower(trim($account->subdomain())) === $subdomain) {
                return $account;
            }
        }

        return null;
    }
}
