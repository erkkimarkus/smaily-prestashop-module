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

namespace PrestaShop\Module\SmailyForPrestaShop\Admin\Settings;

if (!defined('_PS_VERSION_')) {
    exit;
}

use PrestaShop\Module\SmailyForPrestaShop\Admin\Setup\SetupPanels;

/**
 * Saves Settings > Connection, Contacts and Automations through the Initial setup's panels (one
 * writer for the setup and Settings), at the scope SettingsScope names, with Magento's rules for a
 * tab's switch
 * "Use its own … on this shop" (CON-179):
 *
 * - ticked, the tab writes the shop's own values;
 * - unticked on a shop that holds own values, the save only removes them (`returned`: the page
 *   reloads to show the shared values);
 * - unticked on a shop without, the tab writes the shared values it shows.
 *
 * A shop that takes its own Smaily account starts from a copy of the shared one, so an unchanged
 * account keeps its password. When a save moves the shop onto another Smaily account than the
 * shared one, its own automations go, as they do when a shop on another account returns to the
 * shared account: their workflows were picked from the other account (Magento CON-190).
 * `own_account_started` makes the page reload.
 *
 * A shop in mode `a`, or on another Smaily account than the shared one, always saves its own
 * automations, whatever its switch says (SettingsScope::automationsScope()).
 */
final class TabSaver
{
    private SetupPanels $panels;

    private ShopOverrides $overrides;

    public function __construct(SetupPanels $panels, ShopOverrides $overrides)
    {
        $this->panels = $panels;
        $this->overrides = $overrides;
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array{errors: list<array{field: string, message: string}>, connected: ?bool, error: ?string, returned: bool, own_account_started: bool}
     */
    public function saveConnection(SettingsScope $context, array $data, bool $own): array
    {
        $shop = $context->ownShop();
        if ($shop !== null && !$own) {
            if ($this->overrides->hasOwn(ShopOverrides::CONNECTION, $shop)) {
                $other = $this->overrides->usesOtherAccount($shop);
                $this->overrides->removeOwn(ShopOverrides::CONNECTION, $shop);
                if ($other) {
                    $this->overrides->removeOwn(ShopOverrides::AUTOMATIONS, $shop);
                }

                return ['errors' => [], 'connected' => null, 'error' => null, 'returned' => true, 'own_account_started' => false];
            }
        }

        $ownSave = $shop !== null && $own;
        $copied = $ownSave && $this->overrides->copySharedAccount($shop);
        $usedOther = $ownSave && $this->overrides->usesOtherAccount($shop);
        $result = $this->panels->connection()->save($context->scope($own), $data);
        if ($result['errors'] !== []) {
            if ($copied) {
                $this->overrides->removeAccount($shop, '');
            }

            return $result + ['returned' => false, 'own_account_started' => false];
        }
        $started = $ownSave && !$usedOther && $this->overrides->usesOtherAccount($shop);
        if ($started) {
            $this->overrides->removeOwn(ShopOverrides::AUTOMATIONS, $shop);
        }

        return $result + ['returned' => false, 'own_account_started' => $started];
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array{errors: list<array{field: string, message: string}>, returned: bool}
     */
    public function saveContacts(SettingsScope $context, array $data, bool $own): array
    {
        $shop = $context->ownShop();
        if ($shop !== null && !$own && $this->overrides->hasOwn(ShopOverrides::CONTACTS, $shop)) {
            $this->overrides->removeOwn(ShopOverrides::CONTACTS, $shop);

            return ['errors' => [], 'returned' => true];
        }

        return ['errors' => $this->panels->contacts()->save($context->scope($own), $data), 'returned' => false];
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array{errors: list<array{field: string, message: string}>, returned: bool}
     */
    public function saveAutomations(SettingsScope $context, array $data, bool $own): array
    {
        $shop = $context->ownShop();
        $scope = $context->automationsScope($this->overrides, $own);
        if ($shop !== null && $scope === $context->shared() && $this->overrides->hasOwn(ShopOverrides::AUTOMATIONS, $shop)) {
            $this->overrides->removeOwn(ShopOverrides::AUTOMATIONS, $shop);

            return ['errors' => [], 'returned' => true];
        }

        return ['errors' => $this->panels->automations()->save($scope, $data), 'returned' => false];
    }
}
