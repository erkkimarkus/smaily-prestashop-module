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

use PrestaShop\Module\SmailyForPrestaShop\Admin\BaseController;
use PrestaShop\Module\SmailyForPrestaShop\Admin\Settings\ShopOverrides;
use PrestaShop\Module\SmailyForPrestaShop\Automation\MappingRepository;
use PrestaShop\Module\SmailyForPrestaShop\Automation\Trigger;
use PrestaShop\Module\SmailyForPrestaShop\Config\AutomationSettings;
use PrestaShop\Module\SmailyForPrestaShop\Config\ContactsSettings;
use PrestaShop\Module\SmailyForPrestaShop\Scope\StoreLayout;
use PrestaShop\Module\SmailyForPrestaShop\Smaily\AccountStore;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The card "Shops with their own values" under the Initial setup's Connect, Contacts and
 * Automations steps (Magento's WizardData::getWebsiteOverrides(), CON-179): in one store of several
 * shops, each shop that holds its own values of the step's section (Admin\Settings\ShopOverrides),
 * with those values in the admin's words. The setup saves the values all shops share; a listed shop
 * keeps its own unless the merchant picks the step's values for it (Api\Setup\SaveStep,
 * `use_shared`). A shop that keeps its own automations whatever is picked is marked `required`
 * (Magento CON-190).
 */
final class ShopsWithOwnValues
{
    private ShopOverrides $overrides;

    private AccountStore $accounts;

    private MappingRepository $mappings;

    private TranslatorInterface $translator;

    /** @var callable(): array<int, string> */
    private $shops;

    /**
     * @param callable(): array<int, string> $shops the store's shops, id => name, in shop order
     */
    public function __construct(ShopOverrides $overrides, AccountStore $accounts, MappingRepository $mappings, TranslatorInterface $translator, callable $shops)
    {
        $this->overrides = $overrides;
        $this->accounts = $accounts;
        $this->mappings = $mappings;
        $this->translator = $translator;
        $this->shops = $shops;
    }

    /**
     * The shops that hold own values of the section, in one store of several shops; else none.
     *
     * @return list<array{id_shop: int, name: string, values: list<array{0: string, 1: string}>, required: bool}>
     */
    public function of(SetupScope $scope, string $section): array
    {
        if (!$scope->multishop() || $scope->separateStores()) {
            return [];
        }
        $list = [];
        foreach (($this->shops)() as $idShop => $name) {
            if (!$this->overrides->hasOwn($section, $idShop)) {
                continue;
            }
            $list[] = [
                'id_shop' => $idShop,
                'name' => $name,
                'values' => $this->describe($section, $idShop),
                'required' => $section === ShopOverrides::AUTOMATIONS && $this->overrides->keepsOwnAutomations($idShop),
            ];
        }

        return $list;
    }

    /**
     * The shops with a Smaily account of their own that a finished setup's Connect summary lists
     * when no Smaily account is saved for all shops (Magento's getOwnAccountWebsites(), CON-185).
     *
     * @return list<array{id_shop: int, name: string, values: list<array{0: string, 1: string}>, required: bool}>
     */
    public function ownAccounts(SetupScope $scope): array
    {
        if ($this->accounts->find(0, '') !== null) {
            return [];
        }

        return array_values(array_filter(
            $this->of($scope, ShopOverrides::CONNECTION),
            fn (array $shop): bool => $this->accounts->find($shop['id_shop'], '') !== null
        ));
    }

    /**
     * The shop's own values of the section as label and value; a value without a label names
     * itself (a mode).
     *
     * @return list<array{0: string, 1: string}>
     */
    private function describe(string $section, int $idShop): array
    {
        $own = $this->overrides->ownKeys($section, $idShop);
        $lines = [];
        if ($section === ShopOverrides::CONNECTION) {
            $modes = [
                StoreLayout::MODE_SINGLE => 'Single language',
                StoreLayout::MODE_ACCOUNT_PER_LANGUAGE => 'Per-language Smaily accounts',
                StoreLayout::MODE_WORKFLOW_PER_LANGUAGE => 'One account, per-language workflows',
                StoreLayout::MODE_BRANCH_ON_LANGUAGE => 'One workflow branching by language',
            ];
            if (isset($own[StoreLayout::LANGUAGE_MODE])) {
                $mode = $own[StoreLayout::LANGUAGE_MODE];
                $lines[] = ['', isset($modes[$mode]) ? $this->trans($modes[$mode]) : $mode];
            }
            if (isset($own[StoreLayout::FALLBACK_LANGUAGE])) {
                $lines[] = [$this->trans('Default fallback account'), strtoupper($own[StoreLayout::FALLBACK_LANGUAGE])];
            }
            $account = $this->accounts->find($idShop, '');
            if ($account !== null) {
                $lines[] = [$this->trans('Subdomain'), $account->subdomain()];
                $lines[] = [$this->trans('API username'), $account->username()];
                if ($account->password() !== '') {
                    $lines[] = [$this->trans('API password'), '••••••••'];
                }
            }

            return $lines;
        }
        if ($section === ShopOverrides::CONTACTS) {
            $modes = [
                'subscribers' => 'Subscribers only (consent)',
                'all_customers' => 'All customers (legitimate interest)',
                'checkout_optin' => 'Checkout opt-in only',
            ];
            foreach ($own as $key => $value) {
                switch ($key) {
                    case ContactsSettings::ENABLED:
                        $lines[] = [$this->trans('Sync contacts to Smaily'), $this->flag($value)];
                        break;
                    case ContactsSettings::MODE:
                        $lines[] = ['', isset($modes[$value]) ? $this->trans($modes[$value]) : $value];
                        break;
                    case ContactsSettings::GUESTS:
                        $lines[] = [$this->trans('Include guests'), $this->flag($value)];
                        break;
                    case ContactsSettings::FIELDS:
                        $names = [];
                        foreach (explode(',', $value) as $field) {
                            if (isset(ContactsPanel::FIELD_LABELS[$field])) {
                                $names[] = $this->trans(ContactsPanel::FIELD_LABELS[$field]);
                            }
                        }
                        $lines[] = [$this->trans('Extra fields to sync with each contact'), $names === [] ? '—' : implode(', ', $names)];
                        break;
                    case ContactsSettings::CHECKOUT_CHECKBOX:
                        $lines[] = [$this->trans('Show a newsletter checkbox at checkout'), $this->flag($value)];
                        break;
                }
            }

            return $lines;
        }

        $names = [
            Trigger::WELCOME => $this->trans('Welcome'),
            Trigger::FIRST_ORDER => $this->trans('First order'),
            Trigger::ABANDONED_CART => $this->trans('Abandoned cart'),
        ];
        $switches = [
            AutomationSettings::WELCOME_ENABLED => Trigger::WELCOME,
            AutomationSettings::FIRST_ORDER_ENABLED => Trigger::FIRST_ORDER,
            AutomationSettings::CART_ENABLED => Trigger::ABANDONED_CART,
        ];
        foreach ($own as $key => $value) {
            if (isset($switches[$key])) {
                $lines[] = [$names[$switches[$key]], $this->flag($value)];
            } elseif ($key === AutomationSettings::CART_DELAY_MINUTES) {
                $lines[] = [$names[Trigger::ABANDONED_CART] . ' · ' . $this->trans('Wait (minutes)'), $value];
            }
            foreach (AutomationSettings::DISCOUNT_KEYS as $trigger => $keys) {
                if ($key === $keys['enabled']) {
                    $lines[] = [$names[$trigger] . ' · ' . $this->trans('Send a discount code'), $this->flag($value)];
                } elseif ($key === $keys['rule']) {
                    $lines[] = [$names[$trigger] . ' · ' . $this->trans('Cart price rule'), (int) $value > 0 ? '#' . (int) $value : '—'];
                } elseif ($key === $keys['days']) {
                    $lines[] = [$names[$trigger] . ' · ' . $this->trans('Valid for (days)'), $value];
                }
            }
        }
        $perLanguage = 0;
        foreach ($this->mappings->rows($idShop) as $row) {
            if ($row['language'] !== '') {
                ++$perLanguage;
                continue;
            }
            $lines[] = [
                ($names[$row['trigger']] ?? $row['trigger']) . ' · ' . $this->trans('Smaily Workflow'),
                $row['workflow_id'] !== null ? '#' . $row['workflow_id'] : '—',
            ];
        }
        if ($perLanguage > 0) {
            $lines[] = [$this->trans('Per-language workflows'), (string) $perLanguage];
        }

        return $lines;
    }

    private function flag(string $value): string
    {
        return $this->trans($value === '1' ? 'On' : 'Off');
    }

    private function trans(string $message): string
    {
        return $this->translator->trans($message, [], BaseController::DOMAIN);
    }
}
