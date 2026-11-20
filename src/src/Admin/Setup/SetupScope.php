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

use PrestaShop\Module\SmailyForPrestaShop\Scope\StoreLayout;

/**
 * What a setup or Settings page works on, the way Magento's WizardData answers it: whether the
 * store has several shops and how they are run, which scope the page reads and saves, and the
 * languages it offers.
 *
 * - One shop, or several shops as one store in several versions: the page saves the global values
 *   (shop 0), whatever shop PrestaShop's shop selector names. Its Smaily accounts are id_shop 0, the
 *   per-language accounts of a single shop included.
 * - Separate stores: the page saves the shop of PrestaShop's shop selector; with "All shops" (or a
 *   shop group) selected it saves nothing, and says so.
 */
final class SetupScope
{
    private bool $multishop;

    private bool $separateStores;

    private bool $layoutChosen;

    private int $selectedShop;

    /** @var array<string, string> */
    private array $languages;

    /** @var list<string> */
    private array $shopNames;

    /**
     * @param array<string, string> $languages ISO code => name, the active languages of the page's scope
     * @param list<string> $shopNames the names of the store's shops
     */
    public function __construct(bool $multishop, bool $separateStores, bool $layoutChosen, int $selectedShop, array $languages, array $shopNames = [])
    {
        $this->multishop = $multishop;
        $this->separateStores = $multishop && $separateStores;
        $this->layoutChosen = $layoutChosen;
        $this->selectedShop = $selectedShop;
        $this->languages = $languages;
        $this->shopNames = $shopNames;
    }

    /**
     * The scope of the current admin request: $selectedShop is the shop of PrestaShop's shop
     * selector, 0 for "All shops" (Api\AbstractJsonAction::shopId()).
     */
    public static function current(StoreLayout $layout, int $selectedShop): self
    {
        $multishop = (bool) \Shop::isFeatureActive();
        $separate = $multishop && $layout->separateStores();
        $languages = [];
        $rows = $separate && $selectedShop > 0 ? \Language::getLanguages(true, $selectedShop) : \Language::getLanguages(true);
        foreach ($rows as $row) {
            $iso = strtolower((string) $row['iso_code']);
            if (!isset($languages[$iso])) {
                $languages[$iso] = (string) $row['name'];
            }
        }
        $shopNames = [];
        if ($multishop) {
            foreach (\Shop::getShops(false) as $shop) {
                $shopNames[] = (string) $shop['name'];
            }
        }

        return new self($multishop, $separate, $layout->layoutChosen(), $selectedShop, $languages, $shopNames);
    }

    /** The store has two or more shops, and PrestaShop's multishop is on. */
    public function multishop(): bool
    {
        return $this->multishop;
    }

    public function separateStores(): bool
    {
        return $this->separateStores;
    }

    /** The setup asks how the shops are run before anything else (Magento CON-279). */
    public function asksLayoutFirst(): bool
    {
        return $this->multishop && !$this->layoutChosen;
    }

    /**
     * The shop whose values the page reads and saves: 0 for the global values, the selected shop
     * in separate stores, and null in separate stores while no shop is selected.
     */
    public function targetShop(): ?int
    {
        if (!$this->separateStores) {
            return 0;
        }

        return $this->selectedShop > 0 ? $this->selectedShop : null;
    }

    /**
     * The shop that the page's settings are read for: the target shop, and for the global values
     * the selected shop, else the default shop (both read the global values where they hold none).
     */
    public function readShop(): int
    {
        $target = $this->targetShop();
        if ($target !== null && $target > 0) {
            return $target;
        }

        return $this->selectedShop;
    }

    /**
     * @return array<string, string> ISO code => name
     */
    public function languages(): array
    {
        return $this->languages;
    }

    /** More than one language: the language mode applies. */
    public function multilingual(): bool
    {
        return count($this->languages) > 1;
    }

    /**
     * Whether a Smaily account per language (mode `a`) is offered: it is a shop's own setting, so a
     * save for all shops of a multishop store does not take it (ARCHITECTURE 5.1).
     */
    public function offersAccountPerLanguage(): bool
    {
        return $this->multilingual() && !($this->multishop && !$this->separateStores);
    }

    /**
     * The language modes the page offers.
     *
     * @return list<string>
     */
    public function languageModes(): array
    {
        if (!$this->multilingual()) {
            return [StoreLayout::MODE_SINGLE];
        }
        $modes = [StoreLayout::MODE_SINGLE];
        if ($this->offersAccountPerLanguage()) {
            $modes[] = StoreLayout::MODE_ACCOUNT_PER_LANGUAGE;
        }
        $modes[] = StoreLayout::MODE_WORKFLOW_PER_LANGUAGE;
        $modes[] = StoreLayout::MODE_BRANCH_ON_LANGUAGE;

        return $modes;
    }

    /**
     * @return list<string>
     */
    public function shopNames(): array
    {
        return $this->shopNames;
    }
}
