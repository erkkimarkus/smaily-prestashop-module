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
 * What the 3.0.0 upgrade writes, as LegacyConfigMapper derived it from a LegacyState. Plain data.
 *
 * - settings: id_shop => configuration key => value; id_shop 0 is the global scope.
 * - accounts: rows of `smaily_connect_account` without the password. passwordToEncrypt() gives the
 *   legacy plaintext password of each row, to encrypt before it is written.
 * - mappings: rows of `smaily_connect_automation_mapping`.
 * - tokens to generate: the legacy cron token rows (`id_shop_group`, `id_shop` as stored; null is
 *   no id) that are empty or missing and get a new random token. Every other token row stays.
 * - copy sent carts: whether `smaily_cart` holds rows to carry into `smaily_connect_cart`.
 * - legacy keys to delete: the configuration keys to delete at every scope once the plan is applied.
 * - notices: admin notices as a code and its parameters; the admin owns the wording.
 *
 * toArray() holds everything but the passwords, so it can be compared and printed safely.
 */
final class MigrationPlan
{
    public const NOTICE_SETTINGS_MIGRATED = 'settings_migrated';
    public const NOTICE_CONTACT_SYNC_LEFT_OFF = 'contact_sync_left_off';
    public const NOTICE_WELCOME_LEFT_OFF = 'welcome_left_off';
    public const NOTICE_ABANDONED_CART_LEFT_OFF = 'abandoned_cart_left_off';
    public const NOTICE_FIELD_LIST_UNREADABLE = 'field_list_unreadable';
    public const NOTICE_CRON_TOKEN_REPLACED = 'cron_token_replaced';
    public const NOTICE_CRON_SHOP_ASSUMED = 'cron_shop_assumed';
    public const NOTICE_NEWSLETTER_BLOCK_REMOVED = 'newsletter_block_removed';
    public const NOTICE_EMAILSUBSCRIPTION_EMAILS_CHANGED = 'emailsubscription_emails_changed';

    /** The previous code read another switch: 2.x reads SMAILY_ENABLE_CUSTOMER_SYNC, 1.6.x wrote SMAILY_ENABLE_CRON. */
    public const REASON_SWITCH_NOT_READ = 'switch_not_read';
    public const REASON_NO_ACCOUNT = 'no_account';
    public const REASON_NO_WORKFLOW = 'no_workflow';
    /** The 2.x cart cron stopped with an error on a field list that is not JSON, so it sent nothing. */
    public const REASON_FIELD_LIST_NOT_JSON = 'field_list_not_json';
    /** The cron ran in the default shop's context only, and this shop's switch was not the one it read. */
    public const REASON_NOT_DEFAULT_SHOP = 'not_default_shop';
    /** The cart cron of the default shop did not reach this shop's carts. */
    public const REASON_CARTS_NOT_REACHED = 'carts_not_reached';
    /** The cron sent through the default shop's account, and this shop uses another one. */
    public const REASON_OTHER_ACCOUNT = 'other_account';

    /** @var array<int, array<string, string>> */
    private array $settings;

    /** @var list<array{id_shop: int, language: string, subdomain: string, username: string}> */
    private array $accounts;

    /** @var array<int, string> id_shop => plaintext password */
    private array $passwords;

    /** @var list<array{id_shop: int, trigger: string, language: string, account_key: string, workflow_id: int|null, optin_trigger: int, is_default_fallback: int}> */
    private array $mappings;

    /** @var list<array{key: string, id_shop_group: int|null, id_shop: int|null}> */
    private array $tokensToGenerate;

    private bool $copySentCarts;

    /** @var list<string> */
    private array $legacyKeysToDelete;

    /** @var list<array{code: string, params: array<string, int|string>}> */
    private array $notices;

    /**
     * @param array<int, array<string, string>> $settings
     * @param list<array{id_shop: int, language: string, subdomain: string, username: string, password: string}> $accounts
     * @param list<array{id_shop: int, trigger: string, language: string, account_key: string, workflow_id: int|null, optin_trigger: int, is_default_fallback: int}> $mappings
     * @param list<array{key: string, id_shop_group: int|null, id_shop: int|null}> $tokensToGenerate
     * @param list<string> $legacyKeysToDelete
     * @param list<array{code: string, params: array<string, int|string>}> $notices
     */
    public function __construct(
        array $settings,
        array $accounts,
        array $mappings,
        array $tokensToGenerate,
        bool $copySentCarts,
        array $legacyKeysToDelete,
        array $notices
    ) {
        $this->settings = $settings;
        $this->accounts = [];
        $this->passwords = [];
        foreach ($accounts as $account) {
            $this->passwords[$account['id_shop']] = $account['password'];
            unset($account['password']);
            $this->accounts[] = $account;
        }
        $this->mappings = $mappings;
        $this->tokensToGenerate = $tokensToGenerate;
        $this->copySentCarts = $copySentCarts;
        $this->legacyKeysToDelete = $legacyKeysToDelete;
        $this->notices = $notices;
    }

    /**
     * @return array<int, array<string, string>>
     */
    public function settings(): array
    {
        return $this->settings;
    }

    /**
     * @return list<array{id_shop: int, language: string, subdomain: string, username: string}>
     */
    public function accounts(): array
    {
        return $this->accounts;
    }

    /**
     * The legacy plaintext password of the account row of a shop (0 = the global row). It is to be
     * encrypted before it is stored, and never shown or logged.
     */
    public function passwordToEncrypt(int $idShop): string
    {
        return $this->passwords[$idShop] ?? '';
    }

    /**
     * @return list<array{id_shop: int, trigger: string, language: string, account_key: string, workflow_id: int|null, optin_trigger: int, is_default_fallback: int}>
     */
    public function mappings(): array
    {
        return $this->mappings;
    }

    /**
     * @return list<array{key: string, id_shop_group: int|null, id_shop: int|null}>
     */
    public function tokensToGenerate(): array
    {
        return $this->tokensToGenerate;
    }

    public function copySentCarts(): bool
    {
        return $this->copySentCarts;
    }

    /**
     * @return list<string>
     */
    public function legacyKeysToDelete(): array
    {
        return $this->legacyKeysToDelete;
    }

    /**
     * @return list<array{code: string, params: array<string, int|string>}>
     */
    public function notices(): array
    {
        return $this->notices;
    }

    /**
     * The whole plan except the passwords.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'settings' => $this->settings,
            'accounts' => $this->accounts,
            'mappings' => $this->mappings,
            'tokens_to_generate' => $this->tokensToGenerate,
            'copy_sent_carts' => $this->copySentCarts,
            'legacy_keys_to_delete' => $this->legacyKeysToDelete,
            'notices' => $this->notices,
        ];
    }
}
