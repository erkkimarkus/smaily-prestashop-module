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

use PrestaShop\Module\SmailyForPrestaShop\Config\AutomationSettings;
use PrestaShop\Module\SmailyForPrestaShop\Queue\Skipped;
use PrestaShop\Module\SmailyForPrestaShop\Scope\StoreLayout;
use PrestaShop\Module\SmailyForPrestaShop\Smaily\Account;

/**
 * The workflow, and the Smaily account that sends it, for a (trigger, shop, language): the rules
 * of the Magento connector's router (ARCHITECTURE 5.2) with the shop in place of the website.
 *
 * Which rows a shop reads:
 *  - a shop of separate stores: only its own rows, never another shop's or the shared ones (0);
 *  - in one store: its own rows, then the shared ones; but a shop whose own account is another
 *    Smaily account than the shared one reads only its own rows (Magento CON-190), because the
 *    shared workflows were picked from the shared account;
 *  - of those, only the rows of the accounts the shop's language mode sends through (Magento
 *    CON-184): in mode `a` the rows of a language account and the rows of no language, in every
 *    other mode the rows of the account `default`.
 *
 * Which row matches, a shop's own row before a shared one at each step:
 *  - `single` and `c`: the row of no language; in `c` the workflow branches on the contact's
 *    `language` field, which the sender adds;
 *  - `b`: the row of the contact's language, else the default-fallback row, else the row of no
 *    language;
 *  - `a`: the row of the contact's language, else the default-fallback row, else the row of the
 *    shop's fallback language, else the row of no language.
 *
 * A row's `account_key` names the account that sends its workflow, never the contact's: `default`
 * is the account of no language, a language code that language's account, each the shop's own
 * row else (in one store) the shared one (Magento CON-189). A matched row whose account is missing
 * or has no credentials is a skip, never a send through another account. No match is a skip with
 * a reason code: retrying cannot make a workflow appear.
 */
final class Router implements WorkflowResolverInterface
{
    public const SKIPPED_UNKNOWN_TRIGGER = 'unknown_trigger';

    public const SKIPPED_AUTOMATION_OFF = 'automation_off';

    public const SKIPPED_NO_WORKFLOW = 'no_workflow';

    public const SKIPPED_NO_ACCOUNT = 'no_account';

    public const SKIPPED_NO_CREDENTIALS = 'no_credentials';

    private AutomationSettings $settings;

    private StoreLayout $layout;

    private MappingRepository $mappings;

    /** @var callable(int, string): ?Account */
    private $find;

    /**
     * @param callable(int, string): ?Account $find the account row of a shop and a language, as
     *                                              Smaily\AccountStore::find() reads it
     */
    public function __construct(AutomationSettings $settings, StoreLayout $layout, MappingRepository $mappings, callable $find)
    {
        $this->settings = $settings;
        $this->layout = $layout;
        $this->mappings = $mappings;
        $this->find = $find;
    }

    public function resolve(string $trigger, int $idShop, string $language): ?WorkflowMatch
    {
        $route = $this->route($trigger, $idShop, $language);

        return $route instanceof WorkflowMatch ? $route : null;
    }

    /**
     * The match, or the skip with the reason why the automation sends nothing for this contact.
     *
     * @param string $language the contact's two-letter ISO code, '' when unknown
     *
     * @return WorkflowMatch|Skipped
     */
    public function route(string $trigger, int $idShop, string $language)
    {
        if (!in_array($trigger, Trigger::ALL, true)) {
            return new Skipped(self::SKIPPED_UNKNOWN_TRIGGER);
        }
        if (!$this->settings->enabled($trigger, $idShop)) {
            return new Skipped(self::SKIPPED_AUTOMATION_OFF);
        }
        $mode = $this->layout->languageMode($idShop);
        $row = $this->matchingRow($trigger, $idShop, $language, $mode);
        if ($row === null) {
            return new Skipped(self::SKIPPED_NO_WORKFLOW);
        }
        $account = $this->accountFor($idShop, $row['account_key']);
        if ($account === null) {
            return new Skipped(self::SKIPPED_NO_ACCOUNT);
        }
        if (!$account->hasCredentials()) {
            return new Skipped(self::SKIPPED_NO_CREDENTIALS);
        }

        return new WorkflowMatch(
            $account,
            $row['account_key'],
            $row['workflow_id'],
            $idShop,
            $language,
            $mode === StoreLayout::MODE_BRANCH_ON_LANGUAGE
        );
    }

    /**
     * The account that a mapping row of the shop with $accountKey sends through: the shop's own
     * account of that key, else (in one store) the shared one; null when none exists.
     */
    public function accountFor(int $idShop, string $accountKey): ?Account
    {
        $language = $accountKey === MappingRepository::ACCOUNT_DEFAULT ? '' : $accountKey;
        foreach ($this->separate($idShop) ? [$idShop] : [$idShop, 0] as $shop) {
            $account = ($this->find)($shop, $language);
            if ($account !== null) {
                return $account;
            }
        }

        return null;
    }

    /**
     * The shop scopes whose mapping rows the shop reads, its own first: the rule at the top. The
     * Automations panel shows and checks the rows of the same scopes.
     *
     * @return list<int>
     */
    public function rowShops(int $idShop): array
    {
        return $this->separate($idShop) || $this->usesOtherAccount($idShop) ? [$idShop] : [$idShop, 0];
    }

    /**
     * @return array{id: int, id_shop: int, trigger: string, language: string, account_key: string, workflow_id: int|null, optin_trigger: bool, is_default_fallback: bool}|null
     */
    private function matchingRow(string $trigger, int $idShop, string $language, string $mode): ?array
    {
        $rows = [];
        foreach ($this->rowShops($idShop) as $shop) {
            foreach ($this->mappings->rows($shop, $trigger) as $row) {
                $sendable = $row['workflow_id'] !== null || ($row['optin_trigger'] && $trigger === Trigger::WELCOME);
                $ofMode = $mode === StoreLayout::MODE_ACCOUNT_PER_LANGUAGE
                    ? $row['account_key'] !== MappingRepository::ACCOUNT_DEFAULT || $row['language'] === ''
                    : $row['account_key'] === MappingRepository::ACCOUNT_DEFAULT;
                if ($sendable && $ofMode) {
                    $rows[] = $row;
                }
            }
        }

        $steps = [];
        if ($mode === StoreLayout::MODE_ACCOUNT_PER_LANGUAGE || $mode === StoreLayout::MODE_WORKFLOW_PER_LANGUAGE) {
            $fallback = $mode === StoreLayout::MODE_ACCOUNT_PER_LANGUAGE ? $this->layout->fallbackLanguage($idShop) : '';
            $steps = [
                static fn (array $row): bool => $language !== '' && $row['language'] === $language,
                static fn (array $row): bool => $row['is_default_fallback'],
                static fn (array $row): bool => $fallback !== '' && $row['language'] === $fallback,
            ];
        }
        $steps[] = static fn (array $row): bool => $row['language'] === '';

        foreach ($steps as $step) {
            foreach ($rows as $row) {
                if ($step($row)) {
                    return $row;
                }
            }
        }

        return null;
    }

    /**
     * Whether the shop reads only its own setup: a shop of separate stores, or the setup of all
     * shops itself.
     */
    private function separate(int $idShop): bool
    {
        return $idShop === 0 || $this->layout->separateStores();
    }

    /**
     * Whether the shop's own account of no language is another Smaily account than the shared one:
     * another subdomain or another API user.
     */
    private function usesOtherAccount(int $idShop): bool
    {
        $own = ($this->find)($idShop, '');
        $shared = ($this->find)(0, '');
        if ($own === null || $shared === null) {
            return false;
        }

        return strtolower(trim($own->subdomain())) !== strtolower(trim($shared->subdomain()))
            || trim($own->username()) !== trim($shared->username());
    }
}
