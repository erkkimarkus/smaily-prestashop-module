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

use PrestaShop\Module\SmailyForPrestaShop\Scope\StoreLayout;

/**
 * Changes how the shops of a configured store are run, from Settings (Magento's
 * StoreLayout::toSeparateStores() and toOneStore(), CON-279):
 *
 * - One store to separate stores: each shop keeps the values it reads now as its own (the shared
 *   values it does not hold are copied to it, the password still encrypted), and the shared values
 *   go, so a shop added later starts with nothing set. Every shop syncs exactly as it did.
 * - Separate stores to one store: the values of the shop the merchant looks at (the default shop
 *   with "All shops" selected) become the shared values, and every shop's own values of the Smaily
 *   account (with its accounts of a language), the Contacts settings and the automations are
 *   removed, as Magento removes every website's (owner, 2026-11-20). The page asks first. The
 *   contacts confirmation is not an own value and stays (ShopOverrides).
 *
 * The Campaign Intelligence connection is not moved (it is not built yet).
 */
final class LayoutChange
{
    private StoreLayout $layout;

    private ShopOverrides $overrides;

    /** @var callable(): list<int> */
    private $shops;

    /**
     * @param callable(): list<int> $shops the ids of the store's shops
     */
    public function __construct(StoreLayout $layout, ShopOverrides $overrides, callable $shops)
    {
        $this->layout = $layout;
        $this->overrides = $overrides;
        $this->shops = $shops;
    }

    public function toSeparateStores(): void
    {
        if ($this->layout->separateStores()) {
            return;
        }
        $shops = ($this->shops)();
        $otherAccount = [];
        foreach ($shops as $idShop) {
            $otherAccount[$idShop] = $this->overrides->usesOtherAccount($idShop);
        }
        foreach ($shops as $idShop) {
            $this->overrides->copySharedToShop($idShop, !$otherAccount[$idShop]);
        }
        $this->overrides->removeShared();
        $this->layout->setSeparateStores(true);
    }

    /**
     * @param int $idShop the shop whose values become the shared ones
     */
    public function toOneStore(int $idShop): void
    {
        if (!$this->layout->separateStores()) {
            // A store that became multishop after its setup answers the question for the first time.
            if (!$this->layout->layoutChosen()) {
                $this->layout->setSeparateStores(false);
            }

            return;
        }
        $shops = ($this->shops)();
        if (!in_array($idShop, $shops, true)) {
            throw new \InvalidArgumentException('Not a shop of this store.');
        }
        $this->overrides->promoteToShared($idShop);
        $this->layout->setSeparateStores(false);
        foreach ($shops as $shop) {
            foreach (array_keys(ShopOverrides::KEYS) as $section) {
                $this->overrides->removeOwn($section, $shop);
            }
        }
    }
}
