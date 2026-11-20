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

use PrestaShop\Module\SmailyForPrestaShop\Http\Transport;
use PrestaShop\Module\SmailyForPrestaShop\Smaily\Account;
use PrestaShop\Module\SmailyForPrestaShop\Smaily\Client;
use PrestaShop\Module\SmailyForPrestaShop\Smaily\Exception\ApiException;
use PrestaShop\Module\SmailyForPrestaShop\Smaily\Exception\AuthenticationException;
use PrestaShop\Module\SmailyForPrestaShop\Smaily\Exception\InvalidSubdomainException;
use PrestaShop\Module\SmailyForPrestaShop\Smaily\Exception\PlanBlockedException;
use PrestaShop\Module\SmailyForPrestaShop\Smaily\Exception\RequestRefusedException;
use PrestaShop\Module\SmailyForPrestaShop\Smaily\Exception\SmailyClientException;

/**
 * The workflows of one Smaily account that a mapping row can pick: `GET workflows.php` with
 * `trigger_type=form_submitted`, read as the Magento connector reads it
 * (SmailyClient::getAutomationWorkflows()). `POST autoresponder.php` starts only a workflow with
 * the "form submitted" trigger; it refuses any other with code 221, though `GET autoresponder.php`
 * lists it as active. A workflow that is switched off (`is_enabled` false) is left out: Smaily
 * accepts its trigger and sends nothing. The name is the workflow's `title`, else its `name`, else
 * its id. The answer is one list, without pages. Asked each time, as the sibling connectors ask;
 * nothing is cached. A failure is a reason code, never Smaily's text.
 */
final class WorkflowList
{
    public const ERROR_NO_ACCOUNT = 'no_account';

    public const ERROR_NO_CREDENTIALS = 'no_credentials';

    public const ERROR_INVALID_SUBDOMAIN = 'invalid_subdomain';

    public const ERROR_AUTHENTICATION = 'authentication_failed';

    public const ERROR_PLAN_BLOCKED = 'plan_blocked';

    public const ERROR_REFUSED = 'request_refused';

    public const ERROR_API = 'api_error';

    public const ERROR_UNAVAILABLE = 'unavailable';

    private Transport $transport;

    public function __construct(Transport $transport)
    {
        $this->transport = $transport;
    }

    /**
     * The account's workflows by id and name, or none and the reason code.
     *
     * @param Account|null $account the account a row sends through (Router::accountFor())
     *
     * @return array{workflows: list<array{id: int, name: string}>, error: string|null}
     */
    public function of(?Account $account): array
    {
        if ($account === null) {
            return ['workflows' => [], 'error' => self::ERROR_NO_ACCOUNT];
        }
        if (!$account->hasCredentials()) {
            return ['workflows' => [], 'error' => self::ERROR_NO_CREDENTIALS];
        }

        try {
            $rows = (new Client($this->transport, $account->subdomain(), $account->username(), $account->password()))
                ->get(Client::ENDPOINT_WORKFLOWS, ['trigger_type' => 'form_submitted']);
        } catch (SmailyClientException $error) {
            return ['workflows' => [], 'error' => self::reason($error)];
        }

        $workflows = [];
        foreach ($rows as $row) {
            if (!is_array($row) || (int) ($row['id'] ?? 0) <= 0) {
                continue;
            }
            if (array_key_exists('is_enabled', $row) && !$row['is_enabled']) {
                continue;
            }
            $workflows[] = ['id' => (int) $row['id'], 'name' => (string) ($row['title'] ?? $row['name'] ?? $row['id'])];
        }

        return ['workflows' => $workflows, 'error' => null];
    }

    private static function reason(SmailyClientException $error): string
    {
        if ($error instanceof InvalidSubdomainException) {
            return self::ERROR_INVALID_SUBDOMAIN;
        }
        if ($error instanceof PlanBlockedException) {
            return self::ERROR_PLAN_BLOCKED;
        }
        if ($error instanceof AuthenticationException) {
            return self::ERROR_AUTHENTICATION;
        }
        if ($error instanceof RequestRefusedException) {
            return self::ERROR_REFUSED;
        }
        if ($error instanceof ApiException) {
            return self::ERROR_API;
        }

        return self::ERROR_UNAVAILABLE;
    }
}
