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

namespace PrestaShop\Module\SmailyForPrestaShop\Contacts;

if (!defined('_PS_VERSION_')) {
    exit;
}

use PrestaShop\Module\SmailyForPrestaShop\Config\ContactsSettings;
use PrestaShop\Module\SmailyForPrestaShop\Http\Transport;
use PrestaShop\Module\SmailyForPrestaShop\Queue\Failure;
use PrestaShop\Module\SmailyForPrestaShop\Scheduler\Deadline;
use PrestaShop\Module\SmailyForPrestaShop\Scope\ScopeResolver;
use PrestaShop\Module\SmailyForPrestaShop\Smaily\Account;
use PrestaShop\Module\SmailyForPrestaShop\Smaily\Client;
use PrestaShop\Module\SmailyForPrestaShop\Smaily\Exception\SmailyClientException;

/**
 * Mirrors the consent changes that Smaily holds back into the store (ARCHITECTURE 6.2, "Reconcile
 * from Smaily"), as the sibling connectors' ContactReconcile does.
 *
 * Only the shops in subscribers-only mode whose contact sync is active take part. The reconcile
 * reads the action log of each Smaily account that those shops' contacts go through (history.php,
 * from the account's cursor on): `optout`, `delete` and `complaint` set `customer.newsletter = 0`
 * and `emailsubscription.active = 0` (as 2.x did with Smaily's unsubscriber list); `optin` sets
 * `customer.newsletter = 1`. A change touches only the rows of those shops whose shop and language
 * resolve to the account that reported it (ScopeResolver), so a shop is never changed by another
 * shop's account.
 *
 * The first run of an account (no cursor stored for it) applies only the unsubscribes of the log it
 * replays, and skips every `optin` in it (owner, 2026-11-20): 2.x never sent a newsletter-block
 * unsubscribe to Smaily, so an opt-in in that log may belong to a shopper who has unsubscribed in
 * the store since. The first run lasts until it has read the log to its end, over as many ticks as
 * that takes; every run after it applies both directions.
 *
 * The writes go straight to the tables, so they fire no hook and queue nothing back to Smaily. The
 * cursor of each account is stored under CURSORS after every chunk, so a run that the deadline
 * stops goes on from there: the last `seq_id` read, a number, or `{"seq_id": <n>, "first_run":
 * true}` while the first run has not read the log to its end. A log line names the shop, the
 * language and the failure only, never a contact.
 */
final class Reconciler
{
    /**
     * JSON: the last seq_id read by account, keyed "<id_shop>:<language>:<subdomain>"; a number, or
     * {"seq_id": <n>, "first_run": true} until the account's first run has read the log to its end.
     */
    public const CURSORS = 'SMAILY_CONNECT_RECONCILE_CURSORS';

    /** The actions of the log that change consent; every one but `optin` unsubscribes. */
    public const ACTIONS = ['optin', 'optout', 'delete', 'complaint'];

    public const PAGE_SIZE = 10000;

    /** How many log rows are applied between two deadline checks. */
    private const CHUNK = 500;

    private const OPTIN = 'optin';

    private ScopeResolver $scopes;

    private ContactsSettings $settings;

    private Transport $transport;

    private int $pageSize;

    /** @var array<string, string> account key by "<id_shop>:<id_lang>", for one run */
    private array $accountKeys = [];

    public function __construct(ScopeResolver $scopes, ContactsSettings $settings, Transport $transport, int $pageSize = self::PAGE_SIZE)
    {
        $this->scopes = $scopes;
        $this->settings = $settings;
        $this->transport = $transport;
        $this->pageSize = $pageSize;
    }

    /**
     * @return int how many customer and newsletter-block rows changed
     */
    public function run(Deadline $deadline): int
    {
        $this->accountKeys = [];
        $shops = array_values(array_filter(
            self::shopIds(),
            fn (int $idShop): bool => $this->settings->syncActive($idShop) && $this->settings->mode($idShop)->reconciles()
        ));
        if ($shops === []) {
            return 0;
        }

        /** @var array<string, Account> $accounts */
        $accounts = [];
        foreach ($shops as $idShop) {
            foreach (self::languageIds() as $idLang) {
                $account = $this->scopes->resolve($idShop, $idLang)->account();
                if ($account !== null && $account->hasCredentials()) {
                    $accounts[self::accountKey($account)] = $account;
                }
            }
        }

        $changed = 0;
        foreach ($accounts as $key => $account) {
            if ($deadline->passed()) {
                break;
            }
            try {
                $changed += $this->reconcileAccount($key, $account, $shops, $deadline);
            } catch (SmailyClientException $error) {
                \PrestaShopLogger::addLog(sprintf(
                    'Smaily Connect: the contact reconcile of the account of shop %d, language "%s", failed: %s',
                    $account->idShop(),
                    $account->language(),
                    Failure::of($error)->reason()
                ), 3);
            }
        }

        return $changed;
    }

    /**
     * @param list<int> $shops the shops that take part
     */
    private function reconcileAccount(string $key, Account $account, array $shops, Deadline $deadline): int
    {
        $client = new Client($this->transport, $account->subdomain(), $account->username(), $account->password());
        $cursorKey = $key . ':' . strtolower($account->subdomain());
        [$seqId, $firstRun] = self::cursor($cursorKey);
        $changed = 0;
        do {
            $rows = $client->get('history', [
                'since_seq_id' => $seqId,
                'limit' => $this->pageSize,
                'actions' => implode(',', self::ACTIONS),
            ]);
            $rows = array_values(array_filter($rows, static fn ($row): bool => is_array($row) && isset($row['seq_id'])));
            usort($rows, static fn (array $a, array $b): int => (int) $a['seq_id'] <=> (int) $b['seq_id']);

            foreach (array_chunk($rows, self::CHUNK) as $chunk) {
                $subscribed = [];
                foreach ($chunk as $row) {
                    $email = strtolower(trim((string) ($row['email'] ?? '')));
                    if ($email !== '' && in_array($row['action'] ?? null, self::ACTIONS, true) && !($firstRun && $row['action'] === self::OPTIN)) {
                        $subscribed[$email] = $row['action'] === self::OPTIN;
                    }
                }
                $changed += $this->apply($subscribed, $key, $shops);
                $seqId = (int) end($chunk)['seq_id'];
                self::saveCursor($cursorKey, $seqId, $firstRun);
                if ($deadline->passed()) {
                    return $changed;
                }
            }
        } while (count($rows) >= $this->pageSize);
        if ($firstRun) {
            self::saveCursor($cursorKey, $seqId, false);
        }

        return $changed;
    }

    /**
     * Sets the store's consent of each address to what Smaily holds now.
     *
     * @param array<string, bool> $subscribed whether Smaily holds the address as subscribed, by address
     * @param list<int> $shops
     *
     * @return int how many rows changed
     */
    private function apply(array $subscribed, string $key, array $shops): int
    {
        if ($subscribed === []) {
            return 0;
        }
        $db = \Db::getInstance();
        $changed = 0;

        $ids = [0 => [], 1 => []];
        foreach ($this->rows('customer', 'id_customer', array_keys($subscribed), $key, $shops, 'newsletter') as $row) {
            $wanted = (int) ($subscribed[strtolower(trim((string) $row['email']))] ?? $row['newsletter']);
            if ((int) $row['newsletter'] !== $wanted) {
                $ids[$wanted][] = (int) $row['id'];
            }
        }
        foreach ($ids as $wanted => $customers) {
            if ($customers !== []) {
                $db->execute('UPDATE `' . _DB_PREFIX_ . 'customer` SET `newsletter` = ' . $wanted
                    . ' WHERE `id_customer` IN (' . implode(',', $customers) . ')');
                $changed += count($customers);
            }
        }

        $unsubscribed = array_keys(array_filter($subscribed, static fn (bool $value): bool => !$value));
        if ($unsubscribed !== [] && self::hasNewsletterTable()) {
            $ids = [];
            foreach ($this->rows('emailsubscription', 'id', $unsubscribed, $key, $shops, 'active') as $row) {
                if ((int) $row['active'] === 1) {
                    $ids[] = (int) $row['id'];
                }
            }
            if ($ids !== []) {
                $db->execute('UPDATE `' . _DB_PREFIX_ . 'emailsubscription` SET `active` = 0'
                    . ' WHERE `id` IN (' . implode(',', $ids) . ')');
                $changed += count($ids);
            }
        }

        return $changed;
    }

    /**
     * The rows of $table with one of the addresses, in a shop that takes part, whose shop and
     * language resolve to the account $key.
     *
     * @param list<string> $emails
     * @param list<int> $shops
     *
     * @return list<array<string, mixed>> with `id`, `email` and the $consent column
     */
    private function rows(string $table, string $idColumn, array $emails, string $key, array $shops, string $consent): array
    {
        $rows = \Db::getInstance()->executeS(
            'SELECT `' . $idColumn . '` AS `id`, `id_shop`, `id_lang`, `email`, `' . $consent . '`'
            . ' FROM `' . _DB_PREFIX_ . $table . '`'
            . ' WHERE `email` IN (' . implode(',', array_map(static fn (string $email): string => '\'' . pSQL($email) . '\'', $emails)) . ')'
            . ' AND `id_shop` IN (' . implode(',', $shops) . ')',
            true,
            false
        );

        return array_values(array_filter(
            is_array($rows) ? $rows : [],
            fn (array $row): bool => $this->accountKeyOf((int) $row['id_shop'], (int) $row['id_lang']) === $key
        ));
    }

    private function accountKeyOf(int $idShop, int $idLang): string
    {
        $scope = $idShop . ':' . $idLang;
        if (!isset($this->accountKeys[$scope])) {
            $account = $this->scopes->resolve($idShop, $idLang)->account();
            $this->accountKeys[$scope] = $account === null ? '' : self::accountKey($account);
        }

        return $this->accountKeys[$scope];
    }

    private static function accountKey(Account $account): string
    {
        return $account->idShop() . ':' . $account->language();
    }

    /**
     * The account's last seq_id read, and whether its first run goes on: no cursor is the start of
     * the first run.
     *
     * @return array{0: int, 1: bool}
     */
    private static function cursor(string $cursorKey): array
    {
        $cursor = self::cursors()[$cursorKey] ?? ['seq_id' => 0, 'first_run' => true];

        return is_array($cursor) ? [(int) ($cursor['seq_id'] ?? 0), !empty($cursor['first_run'])] : [(int) $cursor, false];
    }

    private static function saveCursor(string $cursorKey, int $seqId, bool $firstRun): void
    {
        [$stored, $storedFirstRun] = self::cursor($cursorKey);
        $seqId = max($seqId, $stored);
        $cursors = self::cursors();
        $cursors[$cursorKey] = $firstRun && $storedFirstRun ? ['seq_id' => $seqId, 'first_run' => true] : $seqId;
        \Configuration::updateGlobalValue(self::CURSORS, (string) json_encode($cursors));
    }

    /**
     * The stored cursors, read from the table: another request may have written them after this
     * one loaded the configuration.
     *
     * @return array<string, int|array{seq_id: int, first_run: bool}>
     */
    private static function cursors(): array
    {
        $json = \Db::getInstance()->getValue(
            'SELECT `value` FROM `' . _DB_PREFIX_ . 'configuration` WHERE `name` = \'' . self::CURSORS . '\''
            . ' AND `id_shop_group` IS NULL AND `id_shop` IS NULL',
            false
        );
        $cursors = is_string($json) ? json_decode($json, true) : null;

        return is_array($cursors) ? $cursors : [];
    }

    /**
     * @return list<int>
     */
    private static function shopIds(): array
    {
        $rows = \Db::getInstance()->executeS('SELECT `id_shop` FROM `' . _DB_PREFIX_ . 'shop` WHERE `deleted` = 0', true, false);

        return array_map('intval', array_column(is_array($rows) ? $rows : [], 'id_shop'));
    }

    /**
     * @return list<int>
     */
    private static function languageIds(): array
    {
        $rows = \Db::getInstance()->executeS('SELECT `id_lang` FROM `' . _DB_PREFIX_ . 'lang`', true, false);

        return array_map('intval', array_column(is_array($rows) ? $rows : [], 'id_lang'));
    }

    private static function hasNewsletterTable(): bool
    {
        return (bool) \Db::getInstance()->executeS('SHOW TABLES LIKE \'' . _DB_PREFIX_ . 'emailsubscription\'', true, false);
    }
}
