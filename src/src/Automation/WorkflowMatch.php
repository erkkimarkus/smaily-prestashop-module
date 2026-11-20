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

use PrestaShop\Module\SmailyForPrestaShop\Smaily\Account;

/**
 * The workflow that an automation starts for a contact, and the Smaily account that sends it: the
 * account that the matched mapping row names, never one taken from the contact or the request.
 */
final class WorkflowMatch
{
    private Account $account;

    private string $accountKey;

    private ?int $workflowId;

    private int $idShop;

    private string $language;

    private bool $branchesOnLanguage;

    /**
     * @param string $accountKey the matched row's account key: 'default' or a language code
     * @param int|null $workflowId null: the welcome's generic opt-in trigger of 2.x
     * @param string $language the contact's two-letter ISO code it was resolved for, '' when unknown
     * @param bool $branchesOnLanguage the shop's workflow branches on the contact's `language` field
     *                                 (language mode `c`)
     */
    public function __construct(Account $account, string $accountKey, ?int $workflowId, int $idShop, string $language, bool $branchesOnLanguage)
    {
        $this->account = $account;
        $this->accountKey = $accountKey;
        $this->workflowId = $workflowId;
        $this->idShop = $idShop;
        $this->language = $language;
        $this->branchesOnLanguage = $branchesOnLanguage;
    }

    /**
     * The Smaily account to post the trigger through.
     */
    public function account(): Account
    {
        return $this->account;
    }

    public function accountKey(): string
    {
        return $this->accountKey;
    }

    /**
     * The Smaily workflow id, or null for the generic opt-in trigger.
     */
    public function workflowId(): ?int
    {
        return $this->workflowId;
    }

    /**
     * Whether the trigger is 2.x's generic opt-in: no workflow id, and Smaily starts every workflow
     * whose trigger is "subscriber opted in".
     */
    public function isOptInTrigger(): bool
    {
        return $this->workflowId === null;
    }

    public function idShop(): int
    {
        return $this->idShop;
    }

    public function language(): string
    {
        return $this->language;
    }

    public function branchesOnLanguage(): bool
    {
        return $this->branchesOnLanguage;
    }
}
