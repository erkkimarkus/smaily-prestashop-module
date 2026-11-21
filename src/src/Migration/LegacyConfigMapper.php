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

namespace PrestaShop\Module\SmailyForPrestaShop\Migration;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Turns the state that 2.x or 1.6.x left into the 3.0.0 migration plan. Pure: no PrestaShop class,
 * no database, no clock, no randomness; the same state always gives the same plan.
 *
 * A feature is switched on at a scope only where the previous code sent it there. The mapper first
 * reads each legacy key as the previous code did: it never passed a shop id, so PrestaShop resolved
 * the key in the context of the request's shop. With multistore on, a shop row wins even when it
 * holds NULL (it reads as empty), else the shop group's row, else the global row; with multistore
 * off, only the global row. Hooks (the welcome opt-in) ran in every shop's own context. The two crons
 * are assumed to have run in the default shop's context: the contact sync pushed every shop's
 * subscribers through that context's account, the cart cron reminded the carts of the default shop
 * and of the shops that share customers with it.
 *
 * With multistore off the store is one store, however many rows the `shop` table holds: PrestaShop
 * then reads and writes the global row whatever shop a call names, so a setting planned for a second
 * shop would overwrite the global one. The plan then covers the default shop alone, which the global
 * scope stands for, and takes no row of a shop or a shop group.
 */
final class LegacyConfigMapper
{
    private const CONTACT_FIELDS = ['first_name', 'last_name', 'birthday', 'website'];

    private const TOKEN_KEYS = ['SMAILY_CUSTOMER_CRON_TOKEN', 'SMAILY_CART_CRON_TOKEN'];

    private const RSS_SORT_BY = ['date_add', 'date_upd', 'name', 'price', 'id_product'];

    private const RSS_SORT_ORDER = ['asc', 'desc'];

    /** The shortest and the longest cart delay of 3.0.0 (Config\AutomationSettings). */
    private const MIN_CART_DELAY_MINUTES = 10;

    private const MAX_CART_DELAY_MINUTES = 1435;

    /** The cart delay that 2.x installed, and showed for an empty value. */
    private const LEGACY_CART_DELAY_MINUTES = 15;

    private LegacyState $state;

    /**
     * The legacy rows by scope: 'shop' => id_shop => key => value, 'group' => id_shop_group => key =>
     * value, 'global' => key => value. A stored NULL stays null.
     *
     * @var array{shop: array<int, array<string, string|null>>, group: array<int, array<string, string|null>>, global: array<string, string|null>}
     */
    private array $index;

    /** Whether a scope's legacy cart delay was longer than the longest delay of 3.0.0. */
    private bool $cartDelayCapped;

    public function map(LegacyState $state): MigrationPlan
    {
        $this->state = $state;
        $this->index = ['shop' => [], 'group' => [], 'global' => []];
        $this->cartDelayCapped = false;
        foreach ($this->rows() as $row) {
            if (!in_array($row['name'], LegacyState::KEYS, true)) {
                continue;
            }
            if ($row['id_shop'] !== null) {
                $this->index['shop'][$row['id_shop']][$row['name']] = $row['value'];
            } elseif ($row['id_shop_group'] !== null) {
                $this->index['group'][$row['id_shop_group']][$row['name']] = $row['value'];
            } else {
                $this->index['global'][$row['name']] = $row['value'];
            }
        }

        $shops = $this->shops();
        $globalAccount = $this->account(null);
        $shopAccounts = [];
        $subdomains = [];
        foreach ($shops as $idShop) {
            $shopAccounts[$idShop] = $this->account($idShop);
            if ($shopAccounts[$idShop] !== null) {
                $subdomains[$shopAccounts[$idShop]['subdomain']] = true;
            }
        }
        $separateStores = $globalAccount === null && count($subdomains) >= 2;
        $upgraded = $globalAccount !== null || array_filter($shopAccounts) !== [];

        $settings = [0 => $this->globalOnlySettings($separateStores, $upgraded)];
        $accounts = [];
        $mappings = [];
        if ($separateStores) {
            foreach ($shops as $idShop) {
                $settings[$idShop] = $this->scopeSettings($idShop);
                if ($shopAccounts[$idShop] !== null) {
                    $accounts[] = ['id_shop' => $idShop, 'language' => ''] + $shopAccounts[$idShop];
                }
                $mappings = array_merge($mappings, array_values($this->mappings($idShop)));
            }
        } else {
            $globalSettings = $this->scopeSettings(null);
            $settings[0] += $globalSettings;
            if ($globalAccount !== null) {
                $accounts[] = ['id_shop' => 0, 'language' => ''] + $globalAccount;
            }
            $globalMappings = $this->mappings(null);
            $mappings = array_values($globalMappings);
            foreach ($shops as $idShop) {
                $own = array_diff_assoc($this->scopeSettings($idShop), $globalSettings);
                if ($own !== []) {
                    ksort($own);
                    $settings[$idShop] = $own;
                }
                $ownAccount = $shopAccounts[$idShop] !== null && $shopAccounts[$idShop] !== $globalAccount;
                if ($ownAccount) {
                    $accounts[] = ['id_shop' => $idShop, 'language' => ''] + $shopAccounts[$idShop];
                }
                foreach ($this->mappings($idShop) as $trigger => $mapping) {
                    $inherited = $globalMappings[$trigger] ?? null;
                    if ($ownAccount || $inherited === null || $mapping['workflow_id'] !== $inherited['workflow_id']) {
                        $mappings[] = $mapping;
                    }
                }
            }
        }
        ksort($settings[0]);

        $tokens = $this->tokensToGenerate();

        return new MigrationPlan(
            $settings,
            $accounts,
            $mappings,
            $tokens,
            $state->sentCartCount() > 0,
            array_values(array_diff(LegacyState::KEYS, self::TOKEN_KEYS)),
            $this->notices($upgraded, $tokens)
        );
    }

    /**
     * The shops that the plan covers: every shop, or with multistore off the default shop alone.
     *
     * @return list<int>
     */
    private function shops(): array
    {
        return $this->state->multishopActive()
            ? array_keys($this->state->shopGroups())
            : [$this->state->defaultShopId()];
    }

    /**
     * The legacy rows that the previous code could read: every row, or with multistore off the
     * global rows alone.
     *
     * @return array<int, array{id_shop_group: int|null, id_shop: int|null, name: string, value: string|null}>
     */
    private function rows(): array
    {
        if ($this->state->multishopActive()) {
            return $this->state->rows();
        }

        return array_values(array_filter($this->state->rows(), static function (array $row): bool {
            return $row['id_shop_group'] === null && $row['id_shop'] === null;
        }));
    }

    /**
     * The value of a legacy key as the previous code read it in a shop's context (null: in the
     * "All shops" context); null when no row applies, '' for a row that holds NULL.
     */
    private function read(string $key, ?int $idShop): ?string
    {
        if ($idShop !== null && $this->state->multishopActive()) {
            if (array_key_exists($key, $this->index['shop'][$idShop] ?? [])) {
                return $this->index['shop'][$idShop][$key] ?? '';
            }
            $idShopGroup = $this->state->shopGroups()[$idShop] ?? 0;
            if (array_key_exists($key, $this->index['group'][$idShopGroup] ?? [])) {
                return $this->index['group'][$idShopGroup][$key] ?? '';
            }
        }
        if (array_key_exists($key, $this->index['global'])) {
            return $this->index['global'][$key] ?? '';
        }

        return null;
    }

    private function legacy1x(): bool
    {
        return strpos($this->state->previousVersion(), '1.') === 0;
    }

    /**
     * Whether a switch was on as the previous code tested it: 2.x by PHP truthiness, 1.6.x by
     * comparing with '1'.
     */
    private function switchOn(string $key, ?int $idShop): bool
    {
        $value = $this->read($key, $idShop);
        if ($this->legacy1x()) {
            return $value === '1';
        }

        return (bool) $value;
    }

    /**
     * The Smaily account a shop's context used, or null when the previous code had none there (it
     * called Smaily only with all three values non-empty).
     *
     * @return array{subdomain: string, username: string, password: string}|null
     */
    private function account(?int $idShop): ?array
    {
        $subdomain = (string) $this->read('SMAILY_SUBDOMAIN', $idShop);
        $username = (string) $this->read('SMAILY_USERNAME', $idShop);
        $password = (string) $this->read('SMAILY_PASSWORD', $idShop);
        if (empty($subdomain) || empty($username) || empty($password)) {
            return null;
        }
        $subdomain = self::normaliseSubdomain($subdomain);

        return $subdomain === '' ? null : ['subdomain' => $subdomain, 'username' => $username, 'password' => $password];
    }

    /**
     * Trimmed, lower case, and the first label of a URL or a host name; hyphens stay.
     */
    private static function normaliseSubdomain(string $subdomain): string
    {
        $host = (string) preg_replace('#^[a-z][a-z0-9+.-]*://#', '', strtolower(trim($subdomain)));

        return explode('.', explode('/', $host)[0])[0];
    }

    /**
     * A workflow id as the previous code sent it: a positive integer; null when the value is empty
     * (the generic opt-in trigger); false when the value is no usable id.
     *
     * @return int|false|null
     */
    private function workflow(string $key, ?int $idShop)
    {
        $value = (string) $this->read($key, $idShop);
        if (empty($value)) {
            return null;
        }

        return ctype_digit($value) && (int) $value > 0 ? (int) $value : false;
    }

    /**
     * @return array{enabled: bool, reason: string|null, mapping: array{workflow_id: int|null}|null}
     */
    private function welcome(?int $idShop): array
    {
        $workflow = $this->workflow('SMAILY_OPTIN_AUTORESPONDER', $idShop);
        $chosen = is_int($workflow) ? ['workflow_id' => $workflow] : null;
        if (!$this->switchOn('SMAILY_OPTIN_ENABLED', $idShop)) {
            return ['enabled' => false, 'reason' => null, 'mapping' => $chosen];
        }
        if ($this->account($idShop) === null) {
            return ['enabled' => false, 'reason' => MigrationPlan::REASON_NO_ACCOUNT, 'mapping' => $chosen];
        }
        if ($workflow === false) {
            return ['enabled' => false, 'reason' => MigrationPlan::REASON_NO_WORKFLOW, 'mapping' => null];
        }

        // Decision 5: no workflow keeps the generic opt-in trigger.
        return ['enabled' => true, 'reason' => null, 'mapping' => ['workflow_id' => $workflow]];
    }

    /**
     * Contact sync at a scope, from the cron that ran in the default shop's context.
     *
     * @return array{enabled: bool, reason: string|null}
     */
    private function contactSync(?int $idShop): array
    {
        $default = $this->state->defaultShopId();
        $switch = $this->legacy1x() ? 'SMAILY_ENABLE_CRON' : 'SMAILY_ENABLE_CUSTOMER_SYNC';
        $account = $this->account($default);
        if (!$this->switchOn($switch, $default)) {
            if (!$this->legacy1x() && $this->switchOn('SMAILY_ENABLE_CRON', $default)) {
                return ['enabled' => false, 'reason' => MigrationPlan::REASON_SWITCH_NOT_READ];
            }
            if ($idShop !== null && $idShop !== $default && $this->switchOn($switch, $idShop)) {
                return ['enabled' => false, 'reason' => MigrationPlan::REASON_NOT_DEFAULT_SHOP];
            }

            return ['enabled' => false, 'reason' => null];
        }
        if ($account === null) {
            return ['enabled' => false, 'reason' => MigrationPlan::REASON_NO_ACCOUNT];
        }
        if ($this->account($idShop) !== $account) {
            return ['enabled' => false, 'reason' => MigrationPlan::REASON_OTHER_ACCOUNT];
        }

        return ['enabled' => true, 'reason' => null];
    }

    /**
     * Abandoned cart at a scope, from the cron that ran in the default shop's context.
     *
     * @return array{enabled: bool, reason: string|null}
     */
    private function abandonedCart(?int $idShop): array
    {
        $default = $this->state->defaultShopId();
        $account = $this->account($default);
        if (!$this->switchOn('SMAILY_ENABLE_ABANDONED_CART', $default)) {
            if ($idShop !== null && $idShop !== $default && $this->switchOn('SMAILY_ENABLE_ABANDONED_CART', $idShop)) {
                return ['enabled' => false, 'reason' => MigrationPlan::REASON_NOT_DEFAULT_SHOP];
            }

            return ['enabled' => false, 'reason' => null];
        }
        if ($account === null) {
            return ['enabled' => false, 'reason' => MigrationPlan::REASON_NO_ACCOUNT];
        }
        if (!is_int($this->workflow('SMAILY_CART_AUTORESPONDER', $default))) {
            return ['enabled' => false, 'reason' => MigrationPlan::REASON_NO_WORKFLOW];
        }
        // 2.x stops with a TypeError on a field list that is not JSON, before it sends anything.
        if (!$this->legacy1x()
            && !is_array(json_decode((string) $this->read('SMAILY_CART_SYNCRONIZE_ADDITIONAL', $default), true))) {
            return ['enabled' => false, 'reason' => MigrationPlan::REASON_FIELD_LIST_NOT_JSON];
        }
        if (!$this->cartsReached($idShop)) {
            return ['enabled' => false, 'reason' => MigrationPlan::REASON_CARTS_NOT_REACHED];
        }
        if ($this->account($idShop) !== $account) {
            return ['enabled' => false, 'reason' => MigrationPlan::REASON_OTHER_ACCOUNT];
        }

        return ['enabled' => true, 'reason' => null];
    }

    /**
     * Whether the cart cron in the default shop's context reached a shop's carts: the default shop's
     * own, and those of its shop group when the group shares customers. The global scope stands for
     * the default shop.
     */
    private function cartsReached(?int $idShop): bool
    {
        $default = $this->state->defaultShopId();
        if ($idShop === null || $idShop === $default) {
            return true;
        }
        $groups = $this->state->shopGroups();
        $group = $groups[$default] ?? 0;

        return ($groups[$idShop] ?? 0) === $group && $this->state->groupSharesCustomers($group);
    }

    /**
     * The settings that a scope holds in every layout.
     *
     * @return array<string, string>
     */
    private function scopeSettings(?int $idShop): array
    {
        $default = $this->state->defaultShopId();
        $contacts = $this->contactSync($idShop)['enabled'];
        $cart = $this->abandonedCart($idShop)['enabled'];
        // The settings of a cron feature are the ones its cron read where the feature stays on.
        $contactsFrom = $contacts ? $default : $idShop;
        $cartFrom = $cart ? $default : $idShop;
        // 2.x reminded a cart after a delay of a day or more, and 3.0.0 reminds no cart older than
        // 24 hours: such a delay becomes the longest one of 3.0.0, and a notice says so.
        $delay = (int) $this->read('SMAILY_ABANDONED_CART_TIME', $cartFrom);
        if ($delay > self::MAX_CART_DELAY_MINUTES) {
            $delay = self::MAX_CART_DELAY_MINUTES;
            $this->cartDelayCapped = true;
        }

        $settings = [
            'SMAILY_CONNECT_CART_DELAY_MINUTES' => (string) (
                $delay > 0 ? max(self::MIN_CART_DELAY_MINUTES, $delay) : self::LEGACY_CART_DELAY_MINUTES
            ),
            'SMAILY_CONNECT_CART_ENABLED' => $cart ? '1' : '0',
            'SMAILY_CONNECT_CHECKOUT_CHECKBOX' => '0',
            'SMAILY_CONNECT_CONTACTS_CONFIRMED' => $contacts ? '1' : '0',
            'SMAILY_CONNECT_CONTACTS_ENABLED' => $contacts ? '1' : '0',
            'SMAILY_CONNECT_CONTACTS_FIELDS' => implode(',', array_merge(
                ['store'],
                $this->fields('SMAILY_SYNCRONIZE_ADDITIONAL', $contactsFrom, self::CONTACT_FIELDS)
            )),
            'SMAILY_CONNECT_CONTACTS_GUESTS' => '1',
            'SMAILY_CONNECT_CONTACTS_MODE' => 'subscribers',
            'SMAILY_CONNECT_FIRST_ORDER_ENABLED' => '0',
            'SMAILY_CONNECT_LANGUAGE_MODE' => 'single',
            'SMAILY_CONNECT_WELCOME_ENABLED' => $this->welcome($idShop)['enabled'] ? '1' : '0',
        ];
        ksort($settings);

        return $settings;
    }

    /**
     * @return array<string, string>
     */
    private function globalOnlySettings(bool $separateStores, bool $upgraded): array
    {
        $settings = [
            'SMAILY_CONNECT_SEPARATE_STORES' => $separateStores ? '1' : '0',
            'SMAILY_CONNECT_SETUP_STATE' => $upgraded ? 'upgraded' : 'new',
        ];
        $category = (string) $this->read('SMAILY_RSS_CATEGORY_ID', null);
        if (ctype_digit($category) && (int) $category > 0) {
            $settings['SMAILY_CONNECT_RSS_CATEGORY'] = $category;
        }
        $limit = (string) $this->read('SMAILY_RSS_LIMIT', null);
        if (ctype_digit($limit) && (int) $limit >= 1 && (int) $limit <= 250) {
            $settings['SMAILY_CONNECT_RSS_LIMIT'] = $limit;
        }
        $sortBy = (string) $this->read('SMAILY_RSS_SORT_BY', null);
        if (in_array($sortBy, self::RSS_SORT_BY, true)) {
            $settings['SMAILY_CONNECT_RSS_SORT_BY'] = $sortBy;
        }
        $sortOrder = (string) $this->read('SMAILY_RSS_SORT_ORDER', null);
        if (in_array($sortOrder, self::RSS_SORT_ORDER, true)) {
            $settings['SMAILY_CONNECT_RSS_SORT_ORDER'] = $sortOrder;
        }

        return $settings;
    }

    /**
     * The mapping rows of a scope by trigger: a chosen workflow, or the generic opt-in trigger where
     * the welcome stays on without one.
     *
     * @return array<string, array{id_shop: int, trigger: string, language: string, account_key: string, workflow_id: int|null, optin_trigger: int, is_default_fallback: int}>
     */
    private function mappings(?int $idShop): array
    {
        $welcome = $this->welcome($idShop)['mapping'];
        $cartFrom = $this->abandonedCart($idShop)['enabled'] ? $this->state->defaultShopId() : $idShop;
        $cart = $this->workflow('SMAILY_CART_AUTORESPONDER', $cartFrom);

        $mappings = [];
        if ($welcome !== null) {
            $mappings['welcome'] = self::mappingRow((int) $idShop, 'welcome', $welcome['workflow_id']);
        }
        if (is_int($cart)) {
            $mappings['abandoned_cart'] = self::mappingRow((int) $idShop, 'abandoned_cart', $cart);
        }

        return $mappings;
    }

    /**
     * @return array{id_shop: int, trigger: string, language: string, account_key: string, workflow_id: int|null, optin_trigger: int, is_default_fallback: int}
     */
    private static function mappingRow(int $idShop, string $trigger, ?int $workflowId): array
    {
        return [
            'id_shop' => $idShop,
            'trigger' => $trigger,
            'language' => '',
            'account_key' => 'default',
            'workflow_id' => $workflowId,
            'optin_trigger' => $workflowId === null ? 1 : 0,
            'is_default_fallback' => 1,
        ];
    }

    /**
     * The known field names that a legacy field list ticks, in the order of $known: a 2.x JSON map
     * name => bool or a 1.6.x PHP-serialized list of names.
     *
     * @param list<string> $known
     *
     * @return list<string>
     */
    private function fields(string $key, ?int $idShop, array $known): array
    {
        $names = self::decodeFields((string) $this->read($key, $idShop));

        return array_values(array_intersect($known, $names ?? []));
    }

    /**
     * @return list<string>|null null when the value is neither format
     */
    private static function decodeFields(string $value): ?array
    {
        if ($value === '') {
            return [];
        }
        $map = json_decode($value, true);
        if (is_array($map)) {
            return array_map('strval', array_keys(array_filter($map)));
        }
        $list = @unserialize($value, ['allowed_classes' => false]);
        if (is_array($list)) {
            return array_values(array_filter($list, 'is_string'));
        }

        return null;
    }

    /**
     * The legacy token rows that are empty, and the global row when there is none: the previous code
     * accepted a request without a token there.
     *
     * @return list<array{key: string, id_shop_group: int|null, id_shop: int|null}>
     */
    private function tokensToGenerate(): array
    {
        $tokens = [];
        foreach (self::TOKEN_KEYS as $key) {
            $global = false;
            $scopes = [];
            foreach ($this->rows() as $row) {
                if ($row['name'] !== $key) {
                    continue;
                }
                $global = $global || ($row['id_shop_group'] === null && $row['id_shop'] === null);
                if ((string) $row['value'] === '') {
                    $scopes[$row['id_shop_group'] . ':' . $row['id_shop']] = [$row['id_shop_group'], $row['id_shop']];
                }
            }
            if (!$global) {
                $scopes[':'] = [null, null];
            }
            ksort($scopes);
            foreach ($scopes as [$idShopGroup, $idShop]) {
                $tokens[] = ['key' => $key, 'id_shop_group' => $idShopGroup, 'id_shop' => $idShop];
            }
        }

        return $tokens;
    }

    /**
     * @param list<array{key: string, id_shop_group: int|null, id_shop: int|null}> $tokens
     *
     * @return list<array{code: string, params: array<string, int|string>}>
     */
    private function notices(bool $upgraded, array $tokens): array
    {
        $notices = [];
        if ($upgraded) {
            $notices[] = ['code' => MigrationPlan::NOTICE_SETTINGS_MIGRATED, 'params' => []];
        }

        $shops = $this->shops();
        $features = [
            MigrationPlan::NOTICE_CONTACT_SYNC_LEFT_OFF => 'contactSync',
            MigrationPlan::NOTICE_WELCOME_LEFT_OFF => 'welcome',
            MigrationPlan::NOTICE_ABANDONED_CART_LEFT_OFF => 'abandonedCart',
        ];
        foreach ($features as $code => $method) {
            $seen = [];
            foreach ($shops as $idShop) {
                $reason = $this->{$method}($idShop)['reason'];
                if ($reason === null) {
                    continue;
                }
                // A reason that lies at the default shop is told once, for the default shop.
                $shopLevel = $method === 'welcome' || in_array($reason, [
                    MigrationPlan::REASON_NOT_DEFAULT_SHOP,
                    MigrationPlan::REASON_CARTS_NOT_REACHED,
                    MigrationPlan::REASON_OTHER_ACCOUNT,
                ], true);
                $params = ['id_shop' => $shopLevel ? $idShop : $this->state->defaultShopId(), 'reason' => $reason];
                if (!in_array($params, $seen, true)) {
                    $seen[] = $params;
                    $notices[] = ['code' => $code, 'params' => $params];
                }
            }
        }

        if ($this->cartDelayCapped) {
            $notices[] = ['code' => MigrationPlan::NOTICE_CART_DELAY_CAPPED, 'params' => []];
        }

        // The cart's field list raises no notice: its selection is not carried over.
        foreach ($this->rows() as $row) {
            if ($row['name'] === 'SMAILY_SYNCRONIZE_ADDITIONAL' && self::decodeFields((string) $row['value']) === null) {
                $notices[] = ['code' => MigrationPlan::NOTICE_FIELD_LIST_UNREADABLE, 'params' => ['key' => $row['name']]];
                break;
            }
        }

        foreach ($tokens as $token) {
            $notices[] = ['code' => MigrationPlan::NOTICE_CRON_TOKEN_REPLACED, 'params' => [
                'key' => $token['key'],
                'id_shop_group' => (int) $token['id_shop_group'],
                'id_shop' => (int) $token['id_shop'],
            ]];
        }

        $default = $this->state->defaultShopId();
        $cronOn = $this->switchOn($this->legacy1x() ? 'SMAILY_ENABLE_CRON' : 'SMAILY_ENABLE_CUSTOMER_SYNC', $default)
            || $this->switchOn('SMAILY_ENABLE_ABANDONED_CART', $default);
        if ($this->state->multishopActive() && count($shops) > 1 && $cronOn) {
            $notices[] = ['code' => MigrationPlan::NOTICE_CRON_SHOP_ASSUMED, 'params' => ['id_shop' => $default]];
        }

        if ($this->legacy1x()) {
            $notices[] = ['code' => MigrationPlan::NOTICE_NEWSLETTER_BLOCK_REMOVED, 'params' => []];
        } elseif ($this->optInSavedBy2x()) {
            $notices[] = ['code' => MigrationPlan::NOTICE_EMAILSUBSCRIPTION_EMAILS_CHANGED, 'params' => []];
        }

        return $notices;
    }

    /**
     * Whether a 2.x save of the customer sync form had the opt-in on: that save also switched off the
     * verification and confirmation emails of ps_emailsubscription and cleared its voucher. The save
     * writes the opt-in switch and a JSON field list with "store" at one scope.
     */
    private function optInSavedBy2x(): bool
    {
        $optIn = [];
        $fields = [];
        foreach ($this->rows() as $row) {
            $scope = $row['id_shop_group'] . ':' . $row['id_shop'];
            if ($row['name'] === 'SMAILY_OPTIN_ENABLED') {
                $optIn[$scope] = $row['value'] === '1';
            } elseif ($row['name'] === 'SMAILY_SYNCRONIZE_ADDITIONAL') {
                $map = json_decode((string) $row['value'], true);
                $fields[$scope] = is_array($map) && array_key_exists('store', $map);
            }
        }
        foreach ($optIn as $scope => $on) {
            if ($on && ($fields[$scope] ?? false)) {
                return true;
            }
        }

        return false;
    }
}
