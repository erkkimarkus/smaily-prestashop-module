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

namespace PrestaShop\Module\SmailyForPrestaShop\Report;

if (!defined('_PS_VERSION_')) {
    exit;
}

use PrestaShop\Module\SmailyForPrestaShop\Automation\Trigger;
use PrestaShop\Module\SmailyForPrestaShop\Automation\WorkflowResolverInterface;
use PrestaShop\Module\SmailyForPrestaShop\Config\ContactsSettings;
use PrestaShop\Module\SmailyForPrestaShop\Engine\Connection;
use PrestaShop\Module\SmailyForPrestaShop\Scope\AccountResolver;
use PrestaShop\Module\SmailyForPrestaShop\Scope\StoreLayout;
use PrestaShop\Module\SmailyForPrestaShop\Smaily\Account;

/**
 * Builds the installation report body: contract installation-report-v1
 * (docs/contracts/installation-report-v1.md), product `connect_prestashop`, as the Magento
 * connector's ReportBuilder with the shop in place of the website.
 *
 * One `marketing` connection per shop that has a connected Smaily account, with the shop's id as
 * `website`, its address, its features, the account's health and the Campaign Intelligence engine
 * store that serves the shop. A shop with a Smaily account per language (mode `a`) gives one
 * connection per language, each with the account that serves the language and its `language`. A
 * connected account has a subdomain, an API user and a password, and a subdomain that passes the
 * contract's rule. Shops that share one account give one connection each.
 *
 * The MAIN account authenticates the report: the account set up for all shops when a connection
 * names it, else the first connection's (separate stores have no account for all shops).
 *
 * It carries the contract's list and nothing more: no customer, order, admin or free-text data, no
 * credential (the main account's API user and password go only into the Authorization header), no
 * engine key, and error CODES only.
 *
 * Not final: the unit tests stand in for it.
 */
class ReportBuilder
{
    public const PRODUCT = 'connect_prestashop';

    public const PLATFORM = 'prestashop';

    public const EVENT_SNAPSHOT = 'snapshot';
    public const EVENT_DISCONNECTED = 'disconnected';
    public const EVENT_DEACTIVATED = 'deactivated';

    public const TRIGGER_SETUP_SAVED = 'setup_saved';
    public const TRIGGER_DAILY = 'daily';

    public const ROLE_MARKETING = 'marketing';

    /** The contract's subdomain rule. */
    public const SUBDOMAIN_PATTERN = '/^[a-z0-9]([a-z0-9-]*[a-z0-9])?$/D';

    /** The most connections a report carries (contract "Top-level fields"). */
    public const MAX_CONNECTIONS = 100;

    /** Each store automation's feature id. */
    private const AUTOMATION_FEATURES = [
        Trigger::WELCOME => 'welcome_automation',
        Trigger::FIRST_ORDER => 'first_order_automation',
        Trigger::ABANDONED_CART => 'abandoned_cart',
    ];

    private StoreFacts $store;

    private StoreLayout $layout;

    private AccountResolver $accounts;

    private ContactsSettings $contacts;

    private WorkflowResolverInterface $router;

    /** @var callable(int): ?Connection */
    private $connectionOf;

    /** @var callable(int): bool */
    private $consentConfirmed;

    private ConnectionHealth $health;

    private string $moduleVersion;

    /** @var callable(): int */
    private $now;

    /**
     * @param callable(int): ?Connection $connectionOf the Campaign Intelligence connection that
     *                                                 serves a shop (Engine\Connections::forShop())
     * @param callable(int): bool $consentConfirmed whether the merchant confirmed the cookie banner
     *                                              for a connection's id_shop
     *                                              (Config\EngineSettings::consentConfirmed())
     * @param callable(): int $now the current Unix time
     */
    public function __construct(
        StoreFacts $store,
        StoreLayout $layout,
        AccountResolver $accounts,
        ContactsSettings $contacts,
        WorkflowResolverInterface $router,
        callable $connectionOf,
        callable $consentConfirmed,
        ConnectionHealth $health,
        string $moduleVersion,
        callable $now
    ) {
        $this->store = $store;
        $this->layout = $layout;
        $this->accounts = $accounts;
        $this->contacts = $contacts;
        $this->router = $router;
        $this->connectionOf = $connectionOf;
        $this->consentConfirmed = $consentConfirmed;
        $this->health = $health;
        $this->moduleVersion = $moduleVersion;
        $this->now = $now;
    }

    /**
     * The main account's credentials, or null when no Smaily account is connected: then nothing is
     * reported.
     */
    public function mainCredentials(): ?Credentials
    {
        return $this->main($this->connectedAccounts());
    }

    /**
     * The subdomain of each connected account: a save that makes one of them go removed that
     * account (a `disconnected` event).
     *
     * @return list<string>
     */
    public function accountKeys(): array
    {
        $keys = [];
        foreach ($this->connectedAccounts() as $account) {
            $keys[$account['credentials']->subdomain()] = true;
        }

        return array_map('strval', array_keys($keys));
    }

    /**
     * The report, or null when there is none to send: no Smaily account is connected, or the
     * installation has no valid address or version.
     *
     * A `disconnected` event that leaves no account carries no connection and is authenticated
     * with $previousMain, the main account the installation held before; without it there is
     * nothing to send.
     *
     * @param string $event one of the EVENT_* constants
     * @param string $trigger a TRIGGER_* constant for a snapshot, '' otherwise
     * @param list<int> $removedConnections the ids of the Campaign Intelligence connections that a
     *                                      `disconnected` event removes
     */
    public function build(string $event, string $trigger = '', array $removedConnections = [], ?Credentials $previousMain = null): ?Report
    {
        $installationUrl = self::address($this->store->url($this->store->defaultShopId()));
        if ($installationUrl === null || $this->moduleVersion === '') {
            return null;
        }

        $accounts = $this->connectedAccounts();
        $main = $this->main($accounts);
        if ($main === null) {
            if ($event !== self::EVENT_DISCONNECTED || $previousMain === null) {
                return null;
            }
            $main = $previousMain;
        }

        $connections = [];
        foreach ($accounts as $account) {
            $connections[] = $this->connection($account, $removedConnections);
        }

        $body = [
            'product' => self::PRODUCT,
            'product_version' => self::clip($this->moduleVersion),
            'platforms' => [['name' => self::PLATFORM, 'version' => self::clip($this->store->platformVersion())]],
            'php_version' => self::clip(PHP_VERSION),
            'installation_url' => $installationUrl,
            'smaily_subdomain' => $main->subdomain(),
            'event' => $event,
        ];
        if ($event === self::EVENT_SNAPSHOT) {
            $body['trigger'] = $trigger;
        }
        $body['sent_at'] = self::timestamp(($this->now)());
        $body['connections'] = array_slice($connections, 0, self::MAX_CONNECTIONS);

        return new Report($body, $main);
    }

    /**
     * The connected accounts: per shop (in id order) its account, or in mode `a` the account that
     * serves each of its languages.
     *
     * @return list<array{id_shop: int, language: string, store_url: string, credentials: Credentials}>
     */
    private function connectedAccounts(): array
    {
        $accounts = [];
        foreach ($this->store->shopIds() as $idShop) {
            $storeUrl = self::address($this->store->url($idShop));
            if ($storeUrl === null) {
                continue;
            }
            $languages = [''];
            if ($this->layout->languageMode($idShop) === StoreLayout::MODE_ACCOUNT_PER_LANGUAGE) {
                $languages = array_values(array_filter(
                    $this->store->languages($idShop),
                    static fn (string $language): bool => preg_match('/^[a-z]{2}$/D', $language) === 1
                ));
            }
            foreach ($languages as $language) {
                $credentials = self::connected($this->accounts->for($idShop, $language));
                if ($credentials !== null) {
                    $accounts[] = ['id_shop' => $idShop, 'language' => $language, 'store_url' => $storeUrl, 'credentials' => $credentials];
                }
            }
        }

        return $accounts;
    }

    /**
     * The main account: the account set up for all shops when a connection names it, else the
     * first connection's account.
     *
     * @param list<array{id_shop: int, language: string, store_url: string, credentials: Credentials}> $accounts
     */
    private function main(array $accounts): ?Credentials
    {
        if ($accounts === []) {
            return null;
        }
        $shared = $this->layout->separateStores() ? null : self::connected($this->accounts->for(0, ''));
        if ($shared !== null) {
            foreach ($accounts as $account) {
                if ($account['credentials']->subdomain() === $shared->subdomain()) {
                    return $shared;
                }
            }
        }

        return $accounts[0]['credentials'];
    }

    /**
     * @param array{id_shop: int, language: string, store_url: string, credentials: Credentials} $account
     * @param list<int> $removedConnections
     *
     * @return array<string, mixed>
     */
    private function connection(array $account, array $removedConnections): array
    {
        $idShop = $account['id_shop'];
        $subdomain = $account['credentials']->subdomain();
        $connection = [
            'role' => self::ROLE_MARKETING,
            'store_url' => $account['store_url'],
            'website' => (string) $idShop,
            'smaily_subdomain' => $subdomain,
        ];
        if ($account['language'] !== '') {
            $connection['language'] = $account['language'];
        }

        $features = [];
        if ($this->contacts->syncActive($idShop)) {
            $features[] = 'subscriber_sync';
        }
        foreach (self::AUTOMATION_FEATURES as $trigger => $feature) {
            if ($this->automationOn($trigger, $idShop, $account['language'], $subdomain)) {
                $features[] = $feature;
            }
        }

        $engine = ($this->connectionOf)($idShop);
        if ($engine !== null && ($engine->tenantId() === '' || in_array($engine->id(), $removedConnections, true))) {
            $engine = null;
        }
        if ($engine !== null) {
            $features[] = 'campaign_intelligence';
            if (($this->consentConfirmed)($engine->idShop())) {
                $features[] = 'browse_tracking';
            }
        }

        $connection['features'] = $features;
        $connection['smaily'] = self::healthBlock($this->health->smaily($subdomain));
        if ($engine !== null) {
            $connection['intelligence'] = [
                'tenant_id' => $engine->tenantId(),
                // The engine takes the connection: its key can be read, and no health check found
                // it refused (Engine\Ingest\IngestFlusher::maySend()).
                'connected' => $engine->apiKey() !== '' && $engine->refusedAt() === '',
            ] + self::healthBlock($this->health->engine($engine->tenantId()));
        }

        return $connection;
    }

    /**
     * Whether an automation is switched on for the shop AND mapped to a workflow that this account
     * sends: the router answers nothing for an automation that is off or has no workflow, and a
     * workflow of another account is not this connection's. A shop with a workflow per language
     * (mode `b`) is asked for each of its languages too: it may hold no workflow of no language.
     */
    private function automationOn(string $trigger, int $idShop, string $language, string $subdomain): bool
    {
        $languages = [$language];
        if ($language === '' && $this->layout->languageMode($idShop) === StoreLayout::MODE_WORKFLOW_PER_LANGUAGE) {
            $languages = array_merge($languages, $this->store->languages($idShop));
        }
        try {
            foreach ($languages as $candidate) {
                $match = $this->router->resolve($trigger, $idShop, $candidate);
                if ($match !== null && strtolower(trim($match->account()->subdomain())) === $subdomain) {
                    return true;
                }
            }
        } catch (\Throwable $error) {
            // The mapping table is gone, or cannot be read: the report says no automation.
        }

        return false;
    }

    /**
     * The credentials when the account is connected: all three set, and a subdomain that passes
     * the contract's rule.
     */
    private static function connected(?Account $account): ?Credentials
    {
        if ($account === null || !$account->hasCredentials()) {
            return null;
        }
        $subdomain = strtolower(trim($account->subdomain()));
        if (strlen($subdomain) > 63 || preg_match(self::SUBDOMAIN_PATTERN, $subdomain) !== 1) {
            return null;
        }

        return new Credentials($subdomain, $account->username(), $account->password());
    }

    /**
     * A storefront address as the contract's address: an absolute http(s) URL without credentials,
     * query or fragment, without a trailing slash, at most 255 characters; null when it is not one.
     */
    private static function address(string $url): ?string
    {
        $url = rtrim(trim($url), '/');
        $parts = parse_url($url);
        if (!is_array($parts) || !in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true)
            || ($parts['host'] ?? '') === '' || isset($parts['user']) || isset($parts['pass'])
            || isset($parts['query']) || isset($parts['fragment']) || strlen($url) > 255
        ) {
            return null;
        }

        return $url;
    }

    /**
     * @param array{status: string, last_sync_at: ?int, last_error_code: ?string} $health
     *
     * @return array{status: string, last_sync_at: ?string, last_error_code: ?string}
     */
    private static function healthBlock(array $health): array
    {
        return [
            'status' => $health['status'],
            'last_sync_at' => $health['last_sync_at'] === null ? null : self::timestamp($health['last_sync_at']),
            'last_error_code' => $health['last_error_code'],
        ];
    }

    private static function timestamp(int $timestamp): string
    {
        return gmdate('Y-m-d\TH:i:s\Z', $timestamp);
    }

    /**
     * The contract's 32-character limit on a version string.
     */
    private static function clip(string $value): string
    {
        return substr($value, 0, 32);
    }
}
