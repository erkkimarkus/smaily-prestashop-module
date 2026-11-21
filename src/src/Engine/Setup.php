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

namespace PrestaShop\Module\SmailyForPrestaShop\Engine;

if (!defined('_PS_VERSION_')) {
    exit;
}

use PrestaShop\Module\SmailyForPrestaShop\Config\EngineSettings;
use PrestaShop\Module\SmailyForPrestaShop\Engine\Exception\EngineException;
use PrestaShop\Module\SmailyForPrestaShop\Engine\Exception\EngineRequestException;
use PrestaShop\Module\SmailyForPrestaShop\Scope\StoreLayout;
use PrestaShop\Module\SmailyForPrestaShop\Smaily\AccountStore;

/**
 * Connects a shop to Campaign Intelligence: exchanges the setup link that the merchant pasted for
 * a connection (contract §1) and stores it. Nothing connects a shop but this call.
 *
 * The request carries facts of the store only: the module's name and version, PrestaShop's
 * version, the shop's address, how the shops share customer accounts (multishop only), the
 * `connection_prefix` capability and, on a reconnect, the prefix the shop's connection held
 * before. No customer, order, product or browsing data.
 *
 * Nothing is stored unless the whole answer can be used: an API key and an engine store, every
 * address in it on the engine's own host, a connection prefix that can be kept as given, and an
 * engine store of one of the shop's own Smaily accounts. The setup token is spent by then; the
 * merchant asks for a new link.
 */
final class Setup
{
    /** The pasted text is neither a setup link of the engine nor a setup token. */
    public const INVALID_LINK = 'invalid_link';

    /** The engine store belongs to a Smaily account that is none of the shop's own. */
    public const ACCOUNT_MISMATCH = 'account_mismatch';

    /** The answer lacks what a connection needs, or holds it in a form that cannot be stored. */
    public const INVALID_ANSWER = 'invalid_answer';

    /** The capability of a connector that puts the connection prefix in front of every key (§1). */
    public const CAPABILITY_CONNECTION_PREFIX = 'connection_prefix';

    private const SETUP_URL = '~^https://intelligence\.smaily\.com(?::443)?/setup/([A-Za-z0-9_-]+)/?$~D';

    private const TOKEN = '/^[A-Za-z0-9_-]+$/D';

    private Client $client;

    private Connections $connections;

    private EngineSettings $settings;

    private StoreLayout $layout;

    private AccountStore $accounts;

    private string $moduleVersion;

    public function __construct(Client $client, Connections $connections, EngineSettings $settings, StoreLayout $layout, AccountStore $accounts, string $moduleVersion)
    {
        $this->client = $client;
        $this->connections = $connections;
        $this->settings = $settings;
        $this->layout = $layout;
        $this->accounts = $accounts;
        $this->moduleVersion = $moduleVersion;
    }

    /**
     * Connects the shop with a setup link or a bare setup token, in place of the connection that
     * serves the shop now.
     *
     * @param int $idShop the shop that connects; in one store every shop shares the connection
     *
     * @throws EngineException with the reason as its error code; nothing is stored then
     */
    public function connect(int $idShop, string $setupLink): Connection
    {
        $key = $this->connections->keyOf($idShop);
        if ($key <= 0 && $this->layout->separateStores()) {
            throw new \InvalidArgumentException('In separate stores a shop connects, not the installation.');
        }
        [$baseUrl, $token] = self::parseLink($setupLink);

        $body = [
            'setup_token' => $token,
            'plugin_info' => $this->pluginInfo($key),
        ];
        if (\Shop::isFeatureActive()) {
            $body['store_config'] = ['customer_account_scope' => $this->layout->customerAccountScope()];
        }
        $body['capabilities'] = [self::CAPABILITY_CONNECTION_PREFIX];
        $existing = $this->connections->find($key);
        $previousPrefix = $existing !== null ? $existing->prefix() : $this->settings->previousPrefix($key);
        if ($previousPrefix !== null) {
            $body['connection'] = ['previous_prefix' => $previousPrefix];
        }

        $connection = self::read($this->client->setupExchange($baseUrl, $body), gmdate('Y-m-d\TH:i:s\Z'));
        $own = $this->ownAccounts($key);
        if (!self::isOwnAccount($connection['smaily_subdomain'], $own)) {
            throw new EngineRequestException(self::ACCOUNT_MISMATCH);
        }

        return $this->connections->save(
            $key,
            $connection['smaily_subdomain'] !== '' ? $connection['smaily_subdomain'] : $this->defaultAccount($key),
            $connection['tenant_id'],
            $connection['api_key'],
            $connection['settings']
        );
    }

    /**
     * The engine's address and the setup token of what the merchant pasted: a setup link
     * (https://intelligence.smaily.com/setup/<token>) or the token alone.
     *
     * @return array{string, string} the base URL and the token
     *
     * @throws EngineRequestException when it is neither, or a link to another host
     */
    public static function parseLink(string $setupLink): array
    {
        $setupLink = trim($setupLink);
        if (preg_match(self::SETUP_URL, $setupLink, $match) === 1) {
            return [Client::BASE_URL, $match[1]];
        }
        if (preg_match(self::TOKEN, $setupLink) === 1) {
            return [Client::BASE_URL, $setupLink];
        }
        if (strpos($setupLink, '://') !== false && !Client::isEngineUrl($setupLink)) {
            throw new EngineRequestException(Client::HOST_NOT_ALLOWED);
        }

        throw new EngineRequestException(self::INVALID_LINK);
    }

    /**
     * What a setup exchange's answer stores, when all of it can be used.
     *
     * The connection prefix (`config.connection.prefix`) is kept exactly as it came: any string,
     * unread. An answer without the block (an engine before the prefix existed) stores none.
     *
     * @param array<string, mixed> $answer
     * @param string $now the UTC time of the exchange
     *
     * @return array{smaily_subdomain: string, tenant_id: string, api_key: string, settings: array<string, mixed>}
     *
     * @throws EngineRequestException when the answer cannot be used
     */
    public static function read(array $answer, string $now): array
    {
        $apiKey = $answer['api_key'] ?? null;
        $tenantId = $answer['tenant_id'] ?? null;
        if (!is_string($apiKey) || $apiKey === '' || !is_string($tenantId) || $tenantId === '' || strlen($tenantId) > 36) {
            throw new EngineRequestException(self::INVALID_ANSWER);
        }

        $baseUrl = $answer['engine_base_url'] ?? Client::BASE_URL;
        $endpoints = $answer['endpoints'] ?? [];
        if (!is_array($endpoints)) {
            throw new EngineRequestException(self::INVALID_ANSWER);
        }
        foreach (array_merge([$baseUrl], array_values($endpoints)) as $url) {
            if (!is_string($url) || !Client::isEngineUrl($url)) {
                throw new EngineRequestException(Client::HOST_NOT_ALLOWED);
            }
        }

        $config = is_array($answer['config'] ?? null) ? $answer['config'] : [];
        if (array_key_exists('connection', $config)
            && !(is_array($config['connection']) && is_string($config['connection']['prefix'] ?? null))) {
            throw new EngineRequestException(self::INVALID_ANSWER);
        }

        $subdomain = is_string($answer['smaily_subdomain'] ?? null) ? strtolower(trim($answer['smaily_subdomain'])) : '';
        if (strlen($subdomain) > 63) {
            throw new EngineRequestException(self::INVALID_ANSWER);
        }

        return [
            'smaily_subdomain' => $subdomain,
            'tenant_id' => $tenantId,
            'api_key' => $apiKey,
            'settings' => [
                'tenant_name' => is_string($answer['tenant_name'] ?? null) ? $answer['tenant_name'] : '',
                'engine_base_url' => $baseUrl,
                'engine_version' => is_string($answer['engine_version'] ?? null) ? $answer['engine_version'] : '',
                'endpoints' => $endpoints,
                'config' => $config,
                'issued_at' => is_string($answer['issued_at'] ?? null) ? $answer['issued_at'] : '',
                'refused_at' => '',
                'health' => HealthCheck::health(HealthCheck::CONNECTED, '', 200, $now),
            ],
        ];
    }

    /**
     * Whether a link to an engine store of this Smaily account may connect the shop: yes when the
     * engine names no account, when the shop holds no Smaily account yet, or when the account is
     * one of the shop's own (as the WooCommerce connector decides it).
     *
     * @param string $engineAccount the answer's `smaily_subdomain`, trimmed and lower case
     * @param list<string> $own the shop's Smaily accounts, lower case
     */
    public static function isOwnAccount(string $engineAccount, array $own): bool
    {
        return $engineAccount === '' || $own === [] || in_array($engineAccount, $own, true);
    }

    /**
     * The Smaily accounts of the shops that the connection of $key serves: every stored account
     * for the installation-wide connection, the shop's own for a separate store.
     *
     * @return list<string>
     */
    private function ownAccounts(int $key): array
    {
        $own = [];
        foreach ($this->accounts->scopes() as $scope) {
            if ($key > 0 && (int) $scope['id_shop'] !== $key) {
                continue;
            }
            $account = $this->accounts->find((int) $scope['id_shop'], (string) $scope['language']);
            if ($account !== null && trim($account->subdomain()) !== '') {
                $own[] = strtolower(trim($account->subdomain()));
            }
        }

        return array_values(array_unique($own));
    }

    private function defaultAccount(int $key): string
    {
        $account = $this->accounts->find($key, '');

        return $account === null ? '' : strtolower(trim($account->subdomain()));
    }

    /**
     * The contract's `plugin_info`: facts of the module and the store, nothing of a shopper.
     *
     * @return array<string, string>
     */
    private function pluginInfo(int $key): array
    {
        return [
            'name' => 'smaily-connect-prestashop',
            'version' => $this->moduleVersion,
            'platform' => 'prestashop',
            'platform_version' => _PS_VERSION_,
            'ecommerce_platform' => 'prestashop',
            'ecommerce_platform_version' => _PS_VERSION_,
            'site_url' => self::siteUrl($key > 0 ? $key : (int) \Configuration::get('PS_SHOP_DEFAULT')),
        ];
    }

    /**
     * The storefront address of a shop, or '' when it has none.
     */
    private static function siteUrl(int $idShop): string
    {
        $shop = new \Shop($idShop);
        $secure = (bool) \Configuration::get('PS_SSL_ENABLED');
        $domain = $secure ? $shop->domain_ssl : $shop->domain;
        if (!is_string($domain) || $domain === '') {
            return '';
        }

        return ($secure ? 'https://' : 'http://') . $domain . $shop->getBaseURI();
    }
}
