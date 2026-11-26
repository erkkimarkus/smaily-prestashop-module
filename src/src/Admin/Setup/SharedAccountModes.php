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

namespace PrestaShop\Module\SmailyForPrestaShop\Admin\Setup;

if (!defined('_PS_VERSION_')) {
    exit;
}

use PrestaShop\Module\SmailyForPrestaShop\Config\ContactsSettings;
use PrestaShop\Module\SmailyForPrestaShop\Scope\StoreLayout;
use PrestaShop\Module\SmailyForPrestaShop\Smaily\AccountStore;

/**
 * The shops of a multishop store that sync contacts to one Smaily account (one subdomain) with
 * different consent modes (owner, 2026-11-26; Linear CON-433). Smaily keeps one subscription
 * status per address, so the reconcile of one shop can mark subscribed a contact whom another
 * shop sent (ARCHITECTURE 14, row 29): the Contacts panel warns each shop of such a group, from
 * the saved settings.
 *
 * A shop's account is its account of no language, else in one store the one all shops share; a
 * shop whose contact sync is not active sends nobody and is left out.
 */
final class SharedAccountModes
{
    private AccountStore $accounts;

    private StoreLayout $layout;

    private ContactsSettings $settings;

    /** @var callable(): array<int, string> */
    private $shops;

    /**
     * @param callable(): array<int, string> $shops the shops of a multishop store by id, with their
     *                                              names; none while multistore is off
     */
    public function __construct(AccountStore $accounts, StoreLayout $layout, ContactsSettings $settings, callable $shops)
    {
        $this->accounts = $accounts;
        $this->layout = $layout;
        $this->settings = $settings;
        $this->shops = $shops;
    }

    /**
     * The shops on the Smaily account of the shop scope $target (0 = the values all shops share)
     * with their consent modes, when the modes differ; else none.
     *
     * @return list<array{shop: string, mode: string}>
     */
    public function of(int $target): array
    {
        $shops = ($this->shops)();
        $subdomain = count($shops) > 1 ? $this->subdomainOf($target) : null;
        if ($subdomain === null) {
            return [];
        }
        $group = [];
        foreach ($shops as $idShop => $name) {
            if ($this->subdomainOf($idShop) === $subdomain && $this->settings->syncActive($idShop)) {
                $group[] = ['shop' => $name, 'mode' => $this->settings->mode($idShop)->mode()];
            }
        }

        return count(array_unique(array_column($group, 'mode'))) > 1 ? $group : [];
    }

    private function subdomainOf(int $idShop): ?string
    {
        $account = $this->accounts->find($idShop, '');
        if ($account === null && $idShop > 0 && !$this->layout->separateStores()) {
            $account = $this->accounts->find(0, '');
        }

        return $account !== null ? strtolower(trim($account->subdomain())) : null;
    }
}
