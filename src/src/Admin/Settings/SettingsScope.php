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

use PrestaShop\Module\SmailyForPrestaShop\Admin\Setup\SetupScope;
use PrestaShop\Module\SmailyForPrestaShop\Scope\StoreLayout;

/**
 * Which values Settings > Connection, Contacts and Automations read and save for the shop of
 * PrestaShop's shop selector, as Magento's Settings decides it for its website selector:
 *
 * - one shop, or separate stores: the Initial setup's scope (SetupScope), with nothing more;
 * - one store in several versions with "All shops" selected: the values all shops share;
 * - one store in several versions with a shop selected: the shared values, or the shop's own ones
 *   behind the switch "Use its own … on this shop" (ownShop()). A shop's own values are read and
 *   saved as a separate store's are, so an account per language is offered there (ARCHITECTURE
 *   5.1: mode `a` is a shop's own setting).
 */
final class SettingsScope
{
    private SetupScope $shared;

    private ?SetupScope $own;

    private function __construct(SetupScope $shared, ?SetupScope $own)
    {
        $this->shared = $shared;
        $this->own = $own;
    }

    /**
     * @param int $selectedShop the shop of PrestaShop's shop selector, 0 for "All shops"
     *                          (Api\AbstractJsonAction::shopId())
     */
    public static function current(StoreLayout $layout, int $selectedShop): self
    {
        $setup = SetupScope::current($layout, $selectedShop);
        if (!$setup->multishop() || $setup->separateStores() || $selectedShop <= 0) {
            return new self($setup, null);
        }
        $chosen = $layout->layoutChosen();

        // The scope all shops share differs from the selected shop's in the shop alone.
        return new self(
            new SetupScope(true, false, $chosen, 0, $setup->languages(), $setup->shopNames()),
            new SetupScope(true, true, $chosen, $selectedShop, SetupScope::languagesOf($selectedShop), $setup->shopNames())
        );
    }

    /**
     * The shop that may hold its own values (the switch shows), or null.
     */
    public function ownShop(): ?int
    {
        return $this->own === null ? null : $this->own->targetShop();
    }

    /**
     * The scope a panel reads or saves: the shop's own values when $own and a shop is selected in
     * one store, else the scope the page edits.
     */
    public function scope(bool $own): SetupScope
    {
        return $own && $this->own !== null ? $this->own : $this->shared;
    }

    /** The scope with "All shops" or no own values: what the setup would edit. */
    public function shared(): SetupScope
    {
        return $this->shared;
    }

    /**
     * The scope Settings > Connection shows: a selected shop's own account where it holds one,
     * else the shared one.
     */
    public function connectionScope(ShopOverrides $overrides): SetupScope
    {
        return $this->scope($this->own !== null && $overrides->hasOwn(ShopOverrides::CONNECTION, (int) $this->ownShop()));
    }

    /**
     * The scope Settings > Automations shows and saves: a selected shop's own automations where
     * $own (its switch, or the own values it holds), and always for a shop that keeps its own
     * (ShopOverrides::keepsOwnAutomations(), Magento CON-190); else the shared ones.
     */
    public function automationsScope(ShopOverrides $overrides, bool $own): SetupScope
    {
        return $this->scope($own || ($this->own !== null && $overrides->keepsOwnAutomations((int) $this->ownShop())));
    }

    /**
     * The language modes Settings > Connection offers: a shop that may hold its own account is
     * offered a shop's own modes, which its switch shows and hides.
     *
     * @return list<string>
     */
    public function languageModes(): array
    {
        return ($this->own ?? $this->shared)->languageModes();
    }

    /** An account per language is offered for one shop only, and the page edits all shops. */
    public function accountPerLanguageElsewhere(): bool
    {
        return $this->own === null && $this->shared->multilingual() && !$this->shared->offersAccountPerLanguage();
    }
}
