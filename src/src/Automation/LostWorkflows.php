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
use PrestaShop\Module\SmailyForPrestaShop\Scope\StoreLayout;

/**
 * A workflow belongs to the Smaily account it was picked from (owner, 2026-11-26; Linear CON-397,
 * one rule for the four connectors). After a Connection save that put another Smaily account in
 * a stored one's place (another subdomain) or changed the language mode, the workflows that the
 * account sending them does not list are removed: those automations send nothing (the Router
 * skips them as `no_workflow`) until the merchant picks a workflow again, and the Automations
 * panel names them (AutomationSettings::lostWorkflows()).
 *
 * The rows checked are those of the saved scope and, for the values all shops share in one store,
 * of every shop: each scope's own rows of the mode it reads, each against the list of the account
 * it sends that row through (Router::accountFor()). A list that cannot be loaded removes nothing.
 * The 2.x opt-in row names no workflow and stays. A shop's row whose shared row of the same key
 * the shop reads becomes "no workflow" (MappingRepository::setNone()), so that the shop does not
 * fall back to the shared workflow.
 */
final class LostWorkflows
{
    private MappingRepository $mappings;

    private Router $router;

    private WorkflowList $workflows;

    private StoreLayout $layout;

    private AutomationSettings $settings;

    /** @var callable(): list<int> */
    private $shops;

    /**
     * @param callable(): list<int> $shops the ids of the store's shops
     */
    public function __construct(MappingRepository $mappings, Router $router, WorkflowList $workflows, StoreLayout $layout, AutomationSettings $settings, callable $shops)
    {
        $this->mappings = $mappings;
        $this->router = $router;
        $this->workflows = $workflows;
        $this->layout = $layout;
        $this->settings = $settings;
        $this->shops = $shops;
    }

    /**
     * Removes the workflows that their accounts do not list, after a save at the shop scope
     * $target (0 = the values all shops share), and adds each removed one to its scope's lost
     * workflows.
     *
     * @return list<array{trigger: string, language: string}> the automations of $target that lost a workflow now
     */
    public function removeAfterSave(int $target): array
    {
        $scopes = [$target];
        if ($target === 0 && !$this->layout->separateStores()) {
            foreach (($this->shops)() as $idShop) {
                $scopes[] = $idShop;
            }
        }
        $lists = [];
        $lostAtTarget = [];
        foreach ($scopes as $idShop) {
            $perLanguage = $this->layout->languageMode($idShop) === StoreLayout::MODE_ACCOUNT_PER_LANGUAGE;
            $readsShared = $idShop > 0 && in_array(0, $this->router->rowShops($idShop), true);
            $lost = [];
            foreach ($this->mappings->rows($idShop) as $row) {
                // Only the rows of the mode the scope reads: mode `a` sends the languages' accounts.
                if ($row['workflow_id'] === null || $perLanguage === ($row['account_key'] === MappingRepository::ACCOUNT_DEFAULT)) {
                    continue;
                }
                $account = $this->router->accountFor($idShop, $row['account_key']);
                if ($account === null) {
                    continue;
                }
                $list = $lists[strtolower($account->subdomain()) . "\n" . $account->username()] ??= $this->workflows->of($account);
                if ($list['error'] !== null || in_array($row['workflow_id'], array_column($list['workflows'], 'id'), true)) {
                    continue;
                }
                if ($readsShared && $this->sharedRowExists($row)) {
                    $this->mappings->setNone($idShop, $row['trigger'], $row['language'], $row['account_key']);
                } else {
                    $this->mappings->delete($idShop, $row['trigger'], $row['language'], $row['account_key']);
                }
                $lost[] = ['trigger' => $row['trigger'], 'language' => $row['language']];
            }
            if ($lost !== []) {
                $this->settings->setLostWorkflows($idShop, self::merge($this->settings->lostWorkflows($idShop), $lost));
            }
            if ($idShop === $target) {
                $lostAtTarget = $lost;
            }
        }

        return $lostAtTarget;
    }

    /**
     * @param array{trigger: string, language: string, account_key: string} $row
     */
    private function sharedRowExists(array $row): bool
    {
        foreach ($this->mappings->rows(0, $row['trigger']) as $shared) {
            if ($shared['language'] === $row['language'] && $shared['account_key'] === $row['account_key']) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<array{trigger: string, language: string}> $entries
     * @param list<array{trigger: string, language: string}> $more
     *
     * @return list<array{trigger: string, language: string}>
     */
    private static function merge(array $entries, array $more): array
    {
        foreach ($more as $entry) {
            if (!in_array($entry, $entries, true)) {
                $entries[] = $entry;
            }
        }

        return $entries;
    }
}
