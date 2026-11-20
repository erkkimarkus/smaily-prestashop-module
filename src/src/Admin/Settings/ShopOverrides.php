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
use PrestaShop\Module\SmailyForPrestaShop\Smaily\Account;
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
            AutomationSettings::CART_FIELDS,
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
        foreach (self::KEYS[$section] as $key) {
            if ($this->ownValue($key, $idShop) !== null) {
                return true;
            }
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
        foreach (self::KEYS[$section] as $key) {
            if ($this->ownValue($key, $idShop) !== null) {
                $this->removeShopValue($key, $idShop);
            }
        }
        if ($section === self::CONNECTION) {
            \Db::getInstance()->delete(self::ACCOUNT_TABLE, '`id_shop` = ' . $idShop);
        } elseif ($section === self::AUTOMATIONS) {
            foreach ($this->mappings->rows($idShop) as $row) {
                $this->mappings->delete($idShop, $row['trigger'], $row['language'], $row['account_key']);
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
        \Db::getInstance()->delete(self::ACCOUNT_TABLE, '`id_shop` = ' . $idShop . ' AND `language` = \'' . pSQL($language) . '\'');
    }

    /**
     * One store to separate stores, for one shop: every shared value of the three sections that
     * the shop does not hold becomes its own, as stored. A shop on another Smaily account gets no
     * shared mapping row: it never read them (Magento CON-190).
     */
    public function copySharedToShop(int $idShop, bool $withMappings): void
    {
        foreach (self::KEYS as $keys) {
            foreach ($keys as $key) {
                $shared = $this->storedValue($key);
                if ($shared !== null && $this->ownValue($key, $idShop) === null) {
                    $this->set($key, $shared, $idShop);
                }
            }
        }
        foreach ($this->accountLanguages(0) as $language) {
            if ($this->accounts->find($idShop, $language) === null) {
                $this->copyAccountRow(0, $idShop, $language);
            }
        }
        if ($withMappings) {
            $this->copyMappings(0, $idShop);
        }
        $this->reload();
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
        foreach ($this->mappings->rows(0) as $row) {
            $this->mappings->delete(0, $row['trigger'], $row['language'], $row['account_key']);
        }
        $this->reload();
    }

    /**
     * Separate stores to one store: the shop's values become the values all shops share. A Smaily
     * account per language is a shop's own setting, so the shared language mode `a` becomes
     * Single language, and the shared account is the shop's account of no language (its fallback
     * account), as Magento's promoteToStoreWide() does. The shop's accounts of a language are
     * copied too, unread while the shared mode is not `a`, so that no account is lost.
     */
    public function promoteToShared(int $idShop): void
    {
        foreach (self::KEYS as $keys) {
            foreach ($keys as $key) {
                // The shop's own value, else the shared one it reads now (an upgrade can leave one).
                $value = $this->ownValue($key, $idShop) ?? $this->storedValue($key);
                if ($key === StoreLayout::LANGUAGE_MODE && $value === StoreLayout::MODE_ACCOUNT_PER_LANGUAGE) {
                    $value = StoreLayout::MODE_SINGLE;
                }
                if ($value !== null) {
                    $this->set($key, $value);
                }
            }
        }
        \Db::getInstance()->delete(self::ACCOUNT_TABLE, '`id_shop` = 0');
        foreach ($this->accountLanguages($idShop) as $language) {
            $this->copyAccountRow($idShop, 0, $language);
        }
        foreach ($this->mappings->rows(0) as $row) {
            $this->mappings->delete(0, $row['trigger'], $row['language'], $row['account_key']);
        }
        $this->copyMappings($idShop, 0);
        $this->reload();
    }

    /**
     * Separate stores to one store, for each shop after the promotion: the shop's own values that
     * equal the shared ones go, so the shop reads the shared values; an own value that differs
     * stays, so the shop goes on exactly as before. Its own Smaily account stays as a whole while
     * its language mode differs from the shared one (mode `a`); its own automations stay as a
     * whole while it holds another Smaily account than the shared one (Magento CON-190).
     */
    public function removeOwnEqualToShared(int $idShop): void
    {
        $sharedMode = $this->storedValue(StoreLayout::LANGUAGE_MODE);
        $ownMode = $this->ownValue(StoreLayout::LANGUAGE_MODE, $idShop);
        if ($ownMode === null || $ownMode === $sharedMode) {
            foreach (self::KEYS[self::CONNECTION] as $key) {
                if ($this->ownValue($key, $idShop) !== null) {
                    $this->removeShopValue($key, $idShop);
                }
            }
            foreach ($this->accountLanguages($idShop) as $language) {
                if (self::sameAccount($this->accounts->find($idShop, $language), $this->accounts->find(0, $language))) {
                    $this->removeAccount($idShop, $language);
                }
            }
        }
        $this->reload();

        foreach ([self::CONTACTS, self::AUTOMATIONS] as $section) {
            foreach (self::KEYS[$section] as $key) {
                $own = $this->ownValue($key, $idShop);
                if ($own !== null && $own === $this->storedValue($key)) {
                    $this->removeShopValue($key, $idShop);
                }
            }
        }
        if (!$this->usesOtherAccount($idShop)) {
            $shared = array_map([self::class, 'mappingKey'], $this->mappings->rows(0));
            foreach ($this->mappings->rows($idShop) as $row) {
                if (in_array(self::mappingKey($row), $shared, true)) {
                    $this->mappings->delete($idShop, $row['trigger'], $row['language'], $row['account_key']);
                }
            }
        }
        $this->reload();
    }

    /**
     * The value stored at exactly the shop's scope (null: none), past this request's cache.
     */
    private function ownValue(string $key, int $idShop): ?string
    {
        return $this->storedValue($key, $idShop);
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
     * Copies the mapping rows of one shop scope to another. A row the target holds for the same
     * trigger, language and account stays; a copied row is a trigger's default fallback only where
     * the target holds no fallback for that trigger, so the Router still finds one.
     */
    private function copyMappings(int $from, int $to): void
    {
        $held = [];
        $fallbacks = [];
        foreach ($this->mappings->rows($to) as $row) {
            $held[$row['trigger'] . '|' . $row['language'] . '|' . $row['account_key']] = true;
            if ($row['is_default_fallback']) {
                $fallbacks[$row['trigger']] = true;
            }
        }
        foreach ($this->mappings->rows($from) as $row) {
            if (isset($held[$row['trigger'] . '|' . $row['language'] . '|' . $row['account_key']])) {
                continue;
            }
            $fallback = $row['is_default_fallback'] && !isset($fallbacks[$row['trigger']]);
            $this->mappings->upsert($to, $row['trigger'], $row['language'], $row['account_key'], $row['workflow_id'], $row['optin_trigger'], $fallback);
            if ($fallback) {
                $fallbacks[$row['trigger']] = true;
            }
        }
    }

    /**
     * @param array{trigger: string, language: string, account_key: string, workflow_id: int|null, optin_trigger: bool, is_default_fallback: bool} $row
     */
    private static function mappingKey(array $row): string
    {
        return implode('|', [
            $row['trigger'],
            $row['language'],
            $row['account_key'],
            (string) $row['workflow_id'],
            (int) $row['optin_trigger'],
            (int) $row['is_default_fallback'],
        ]);
    }

    private static function sameAccount(?Account $own, ?Account $shared): bool
    {
        return $own !== null && $shared !== null
            && strcasecmp($own->subdomain(), $shared->subdomain()) === 0
            && $own->username() === $shared->username()
            && $own->password() === $shared->password();
    }
}
