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

use PrestaShop\Module\SmailyForPrestaShop\Automation\MappingRepository;
use PrestaShop\Module\SmailyForPrestaShop\Config\AutomationSettings;
use PrestaShop\Module\SmailyForPrestaShop\Config\ContactsSettings;
use PrestaShop\Module\SmailyForPrestaShop\Config\Settings;
use PrestaShop\Module\SmailyForPrestaShop\Scope\StoreLayout;
use PrestaShop\Module\SmailyForPrestaShop\Smaily\AccountStore;

/**
 * The three sections a shop can hold its own values of (Magento's WebsiteOverrides): the Smaily
 * account, the Contacts settings and the automations. It tells which shops hold own values, and
 * copies, compares and removes them between a shop's scope and the scope all shops share (0).
 *
 * It never decides a value: the panels write what the merchant saves (Admin\Setup\*Panel), and
 * this class moves the stored rows as they are, the encrypted password included.
 *
 * - The Smaily account: the language mode, the fallback language, and the shop's account rows (its
 *   account of no language, and in mode `a` one per language).
 * - The Contacts settings: sync on or off, the mode, "include guests", the fields and the checkout
 *   checkbox. The confirmation (CONTACTS_CONFIRMED) is not an own value: it is never copied or
 *   removed, as in Magento.
 * - The automations: their switches, the cart delay and fields, and the shop's mapping rows.
 *
 * A change of the shop layout (LayoutChange) reloads the configuration once, after its last
 * removal: copySharedToShops() and promoteToShared() only write, and a write keeps the loaded
 * configuration current.
 */
final class ShopOverrides extends Settings
{
    public const CONNECTION = 'connection';

    public const CONTACTS = 'contacts';

    public const AUTOMATIONS = 'automations';

    /** The configuration keys of each section. */
    public const KEYS = [
        self::CONNECTION => [StoreLayout::LANGUAGE_MODE, StoreLayout::FALLBACK_LANGUAGE],
        self::CONTACTS => [
            ContactsSettings::ENABLED,
            ContactsSettings::MODE,
            ContactsSettings::GUESTS,
            ContactsSettings::FIELDS,
            ContactsSettings::CHECKOUT_CHECKBOX,
        ],
        self::AUTOMATIONS => [
            AutomationSettings::WELCOME_ENABLED,
            AutomationSettings::FIRST_ORDER_ENABLED,
            AutomationSettings::CART_ENABLED,
            AutomationSettings::CART_DELAY_MINUTES,
        ],
    ];

    private const ACCOUNT_TABLE = 'smaily_connect_account';

    private AccountStore $accounts;

    private MappingRepository $mappings;

    public function __construct(AccountStore $accounts, MappingRepository $mappings)
    {
        $this->accounts = $accounts;
        $this->mappings = $mappings;
    }

    /**
     * Whether the shop holds own values of the section. For the Smaily account, an account row of
     * a language counts only with the shop's own mode `a`; outside it no one reads that row.
     */
    public function hasOwn(string $section, int $idShop): bool
    {
        if ($this->storedValues(self::KEYS[$section], $idShop) !== []) {
            return true;
        }
        if ($section === self::CONNECTION) {
            return $this->accounts->find($idShop, '') !== null;
        }

        return $section === self::AUTOMATIONS && $this->mappings->rows($idShop) !== [];
    }

    /**
     * Removes the shop's own values of the section, so that it reads the values all shops share.
     */
    public function removeOwn(string $section, int $idShop): void
    {
        $this->deleteOwn($section, $idShop);
        $this->reload();
    }

    /**
     * Separate stores to one store: removes the own values of the three sections of every shop.
     *
     * @param list<int> $shops
     */
    public function removeAllOwn(array $shops): void
    {
        foreach ($shops as $idShop) {
            foreach (array_keys(self::KEYS) as $section) {
                $this->deleteOwn($section, $idShop);
            }
        }
        $this->reload();
    }

    /**
     * Whether the shop's own account of no language is another Smaily account than the one all
     * shops share (another subdomain or API user, Automation\Router's rule, Magento CON-190): its
     * automations then come from its own account only.
     */
    public function usesOtherAccount(int $idShop): bool
    {
        $own = $this->accounts->find($idShop, '');
        $shared = $this->accounts->find(0, '');
        if ($own === null || $shared === null) {
            return false;
        }

        return strtolower(trim($own->subdomain())) !== strtolower(trim($shared->subdomain()))
            || trim($own->username()) !== trim($shared->username());
    }

    /**
     * Whether the shop always holds its own automations (Magento CON-190, its switch shows ticked
     * and fixed): its workflows come from its own account per language (mode `a`, a shop's own
     * setting), or from another Smaily account than the shared one.
     */
    public function keepsOwnAutomations(int $idShop): bool
    {
        return $this->get(StoreLayout::LANGUAGE_MODE, $idShop) === StoreLayout::MODE_ACCOUNT_PER_LANGUAGE
            || $this->usesOtherAccount($idShop);
    }

    /**
     * Whether Settings tells the merchant to pick the shop's workflows (Magento CON-190): it uses
     * another Smaily account than the shared one, which starts only its own workflows, and it
     * holds none.
     */
    public function needsOwnWorkflows(int $idShop): bool
    {
        return $this->usesOtherAccount($idShop) && $this->mappings->rows($idShop) === [];
    }

    /**
     * Gives the shop a copy of the shared account of no language, as stored, when it holds none:
     * a shop that takes its own Smaily account starts from the shared one, so an unchanged account
     * keeps its password. Returns whether it copied one.
     */
    public function copySharedAccount(int $idShop): bool
    {
        if ($this->accounts->find($idShop, '') !== null || $this->accounts->find(0, '') === null) {
            return false;
        }

        return $this->copyAccountRow(0, $idShop, '');
    }

    public function removeAccount(int $idShop, string $language): void
    {
        $this->accounts->delete($idShop, $language);
    }

    /**
     * One store to separate stores: every shared value of the three sections that a shop does not
     * hold becomes its own, as stored. A shop on another Smaily account gets no shared mapping
     * row: it never read them (Magento CON-190).
     *
     * @param array<int, bool> $shops each shop's id => whether it takes the shared mapping rows
     */
    public function copySharedToShops(array $shops): void
    {
        $keys = array_merge(...array_values(self::KEYS));
        $shared = $this->storedValues($keys, null);
        $languages = $this->accountLanguages(0);
        foreach ($shops as $idShop => $withMappings) {
            foreach (array_diff_key($shared, $this->storedValues($keys, $idShop)) as $key => $value) {
                $this->set($key, $value, $idShop);
            }
            foreach ($languages as $language) {
                if ($this->accounts->find($idShop, $language) === null) {
                    $this->copyAccountRow(0, $idShop, $language);
                }
            }
            if ($withMappings) {
                $this->copyMappings(0, $idShop);
            }
        }
    }

    /**
     * Removes the values all shops share of the three sections, so that a shop added later starts
     * with nothing set (one store to separate stores, once every shop holds them as its own).
     */
    public function removeShared(): void
    {
        foreach (self::KEYS as $keys) {
            foreach ($keys as $key) {
                \Configuration::deleteFromGivenContext($key, null, null);
            }
        }
        \Db::getInstance()->delete(self::ACCOUNT_TABLE, '`id_shop` = 0');
        $this->removeMappings(0);
        $this->reload();
    }

    /**
     * Separate stores to one store: the shop's values become the values all shops share, as
     * stored, and the shared account is the shop's account of no language (its fallback account),
     * as Magento's promoteToStoreWide() does. The shop's accounts of a language are not shared:
     * they go with its own values (removeAllOwn()), as Magento's go. Its mapping rows join the
     * shared rows that an upgrade left, its own winning; a shared row that it holds no row for
     * stays.
     */
    public function promoteToShared(int $idShop): void
    {
        $keys = array_merge(...array_values(self::KEYS));
        // The shop's own value, else the shared one it reads now (an upgrade can leave one).
        foreach ($this->storedValues($keys, $idShop) + $this->storedValues($keys, null) as $key => $value) {
            $this->set($key, $value);
        }
        \Db::getInstance()->delete(self::ACCOUNT_TABLE, '`id_shop` = 0');
        $this->copyAccountRow($idShop, 0, '');
        $this->copyMappings($idShop, 0, true);
    }

    /**
     * The values stored at exactly the shop's scope, or with null at the scope all shops share,
     * read past this request's cache as storedValue() reads one: key => value, without the keys
     * that hold no row or NULL there.
     *
     * @param list<string> $keys
     *
     * @return array<string, string>
     */
    private function storedValues(array $keys, ?int $idShop): array
    {
        $rows = \Db::getInstance()->executeS(sprintf(
            'SELECT `name`, `value` FROM `%sconfiguration` WHERE `name` IN (\'%s\') AND `value` IS NOT NULL AND %s',
            _DB_PREFIX_,
            implode('\', \'', array_map('pSQL', $keys)),
            $idShop !== null && $idShop > 0 ? sprintf('`id_shop` = %d', $idShop) : '`id_shop_group` IS NULL AND `id_shop` IS NULL'
        ), true, false);

        return array_column(is_array($rows) ? $rows : [], 'value', 'name');
    }

    /**
     * Removes the shop's own values of the section; the caller reloads the configuration.
     */
    private function deleteOwn(string $section, int $idShop): void
    {
        foreach (array_keys($this->storedValues(self::KEYS[$section], $idShop)) as $key) {
            $this->removeShopValue($key, $idShop);
        }
        if ($section === self::CONNECTION) {
            \Db::getInstance()->delete(self::ACCOUNT_TABLE, '`id_shop` = ' . $idShop);
        } elseif ($section === self::AUTOMATIONS) {
            $this->removeMappings($idShop);
        }
    }

    private function removeMappings(int $idShop): void
    {
        foreach ($this->mappings->rows($idShop) as $row) {
            $this->mappings->delete($idShop, $row['trigger'], $row['language'], $row['account_key']);
        }
    }

    /**
     * The languages of the account rows stored at exactly one shop scope ('' = no language).
     *
     * @return list<string>
     */
    private function accountLanguages(int $idShop): array
    {
        $rows = \Db::getInstance()->executeS(
            'SELECT `language` FROM `' . _DB_PREFIX_ . self::ACCOUNT_TABLE . '` WHERE `id_shop` = ' . $idShop,
            true,
            false
        );

        return array_map(static fn (array $row): string => (string) $row['language'], is_array($rows) ? $rows : []);
    }

    /**
     * Copies an account row from one shop scope to another as stored: the password stays
     * encrypted, and a row the target holds is replaced.
     */
    private function copyAccountRow(int $from, int $to, string $language): bool
    {
        $table = '`' . _DB_PREFIX_ . self::ACCOUNT_TABLE . '`';

        return \Db::getInstance()->execute(
            'INSERT INTO ' . $table . ' (`id_shop`, `language`, `subdomain`, `username`, `password_enc`, `date_add`, `date_upd`)'
            . ' SELECT ' . $to . ', `language`, `subdomain`, `username`, `password_enc`, NOW(), NOW() FROM ' . $table
            . ' WHERE `id_shop` = ' . $from . ' AND `language` = \'' . pSQL($language) . '\''
            . ' ON DUPLICATE KEY UPDATE `subdomain` = VALUES(`subdomain`), `username` = VALUES(`username`),'
            . ' `password_enc` = VALUES(`password_enc`), `date_upd` = VALUES(`date_upd`)'
        );
    }

    /**
     * Copies the mapping rows of one shop scope to another (Magento's copyMappingRows()). The
     * shared scope never takes a row of a language's account: an account per language is a shop's
     * own. A row the target holds for the same trigger, language and account stays, unless
     * $replace. A copied row is a trigger's default fallback only where the target holds no
     * fallback of that kind for the trigger (the rows of the account `default`, or the rows of the
     * languages' accounts), so the Router still finds one. With $replace the copied rows win: a
     * fallback among them takes over its kind.
     */
    private function copyMappings(int $from, int $to, bool $replace = false): void
    {
        $key = static fn (array $row): string => $row['trigger'] . '|' . $row['language'] . '|' . $row['account_key'];
        $kind = static fn (array $row): string => $row['trigger'] . '|' . ($row['account_key'] === MappingRepository::ACCOUNT_DEFAULT ? 'one' : 'per-language');
        $source = array_filter(
            $this->mappings->rows($from),
            static fn (array $row): bool => $to !== 0 || $row['account_key'] === MappingRepository::ACCOUNT_DEFAULT
        );
        $held = [];
        $fallbacks = [];
        foreach ($this->mappings->rows($to) as $row) {
            $held[$key($row)] = true;
            if ($row['is_default_fallback']) {
                $fallbacks[$kind($row)] = $row;
            }
        }
        if ($replace) {
            foreach ($source as $row) {
                $loser = $row['is_default_fallback'] ? ($fallbacks[$kind($row)] ?? null) : null;
                if ($loser !== null) {
                    $this->mappings->upsert($to, $loser['trigger'], $loser['language'], $loser['account_key'], $loser['workflow_id'], $loser['optin_trigger'], false);
                    unset($fallbacks[$kind($row)]);
                }
            }
        }
        foreach ($source as $row) {
            if (isset($held[$key($row)]) && !$replace) {
                continue;
            }
            $fallback = $row['is_default_fallback'] && !isset($fallbacks[$kind($row)]);
            $this->mappings->upsert($to, $row['trigger'], $row['language'], $row['account_key'], $row['workflow_id'], $row['optin_trigger'], $fallback);
            if ($fallback) {
                $fallbacks[$kind($row)] = $row;
            }
        }
    }
}
