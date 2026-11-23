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

/**
 * One Campaign Intelligence connection as its row holds it: the shop it is stored for (0 = the
 * installation-wide connection), the engine store, the API key and what the setup exchange
 * answered. Engine\Connections reads it; a value, never saved by itself.
 */
final class Connection
{
    /**
     * The form of a connection prefix that the engine assigns (contract "Base context": `c<N>-`, N
     * from 1 to 9999, lower case). It decides only whether a value that arrives is taken as a
     * prefix, as in the Magento and WooCommerce connectors; nothing reads a prefix in parts.
     */
    private const PREFIX_FORM = '/^c[1-9][0-9]{0,3}-$/D';

    /** state(): the engine takes the connection, also while it does not answer. */
    public const STATE_CONNECTED = 'connected';

    /** state(): the engine refuses the connection because the account is not active. */
    public const STATE_NOT_ACTIVE = 'not_active';

    /** state(): the engine refuses the key, or the stored key does not read. */
    public const STATE_KEY_REFUSED = 'key_refused';

    /** state(): the engine asks for a newer version of the module. */
    public const STATE_UPDATE_REQUIRED = 'update_required';

    private int $id;

    private int $idShop;

    private string $smailySubdomain;

    private string $tenantId;

    private string $apiKey;

    /** @var array<string, mixed> */
    private array $settings;

    private string $storedKey;

    /**
     * @param string $apiKey the API key, or '' when the stored one does not decrypt
     * @param array<string, mixed> $settings the row's settings: `tenant_name`, `engine_base_url`,
     *                                       `engine_version`, `endpoints`, `config`, `issued_at`,
     *                                       `refused_at`, `health`
     * @param string $storedKey the encrypted key as the row holds it, which names this very
     *                          connection among the ones its row held (Connections)
     */
    public function __construct(int $id, int $idShop, string $smailySubdomain, string $tenantId, string $apiKey, array $settings, string $storedKey = '')
    {
        $this->id = $id;
        $this->idShop = $idShop;
        $this->smailySubdomain = $smailySubdomain;
        $this->tenantId = $tenantId;
        $this->apiKey = $apiKey;
        $this->settings = $settings;
        $this->storedKey = $storedKey;
    }

    public function id(): int
    {
        return $this->id;
    }

    /**
     * The shop the connection is stored for: 0 for the installation-wide connection, which serves
     * every shop of one store; a shop's id for the connection of a separate store.
     */
    public function idShop(): int
    {
        return $this->idShop;
    }

    /**
     * The Smaily account of the engine store, lower case; '' when neither the engine nor the shop
     * named one.
     */
    public function smailySubdomain(): string
    {
        return $this->smailySubdomain;
    }

    public function tenantId(): string
    {
        return $this->tenantId;
    }

    /**
     * The API key, or '' when the stored one does not decrypt (a shop moved to another cookie
     * key): the connection then needs a new setup link.
     */
    public function apiKey(): string
    {
        return $this->apiKey;
    }

    /**
     * @return array<string, mixed>
     */
    public function settings(): array
    {
        return $this->settings;
    }

    public function storedKey(): string
    {
        return $this->storedKey;
    }

    /**
     * The URL of an engine endpoint: the one the setup exchange's map names, else the fallback
     * path on the stored base URL, for a key the map of an older connection lacks.
     *
     * @param string $key a key of the contract's endpoints map, e.g. `ingest_ping`
     * @param string $fallbackPath the endpoint's path, e.g. `/api/v1/ingest/ping`
     */
    public function endpoint(string $key, string $fallbackPath): string
    {
        $url = is_array($this->settings['endpoints'] ?? null) ? ($this->settings['endpoints'][$key] ?? null) : null;
        if (is_string($url) && $url !== '') {
            return $url;
        }

        return rtrim($this->string('engine_base_url'), '/') . $fallbackPath;
    }

    /**
     * The `config` block of the setup exchange, with what a later ping changed in it.
     *
     * @return array<string, mixed>
     */
    public function config(): array
    {
        return is_array($this->settings['config'] ?? null) ? $this->settings['config'] : [];
    }

    /**
     * A number of months of the engine's `config.history` (`start_months`, `horizon_months`), or
     * the fallback where the engine named none.
     */
    public function historyMonths(string $key, int $fallback): int
    {
        $history = $this->config()['history'] ?? null;

        return is_array($history) && is_numeric($history[$key] ?? null) && (int) $history[$key] > 0 ? (int) $history[$key] : $fallback;
    }

    /**
     * The connection's code prefix, exactly as the engine gave it in `config.connection.prefix`:
     * every product and order key that the contract names is sent with it in front. It is the
     * engine's and opaque: nothing reads its parts, derives it or shows it. '' sends keys as they
     * are, also for a connection whose engine named no prefix.
     */
    public function prefix(): string
    {
        $connection = $this->config()['connection'] ?? null;
        $prefix = is_array($connection) ? ($connection['prefix'] ?? null) : null;

        return is_string($prefix) ? $prefix : '';
    }

    /**
     * Whether a value is a connection prefix as the engine gives one: '' (a connection without a
     * prefix) or `c<N>-`. An exchange that answers another value is refused, and a ping's is
     * ignored (Setup::read(), HealthCheck::withAnswer()), as in the sibling connectors.
     *
     * @param mixed $value
     */
    public static function isPrefix($value): bool
    {
        return is_string($value) && ($value === '' || preg_match(self::PREFIX_FORM, $value) === 1);
    }

    /**
     * The UTC time since which the engine refuses this connection, or '' while it does not.
     */
    public function refusedAt(): string
    {
        return $this->string('refused_at');
    }

    /**
     * Whether the engine takes the connection: its key reads, and no health check found it
     * refused. Rows, requests of the storefront and automations go only to such a connection.
     */
    public function isTaken(): bool
    {
        return $this->apiKey !== '' && $this->refusedAt() === '';
    }

    /**
     * What the admin pages show for the connection, from the health checks: connected (also while
     * the engine does not answer), or why the engine refuses it (the reason of the last refusal,
     * which a later check without an answer does not replace).
     *
     * @return string one of the STATE_ constants
     */
    public function state(): string
    {
        if ($this->apiKey === '') {
            return self::STATE_KEY_REFUSED;
        }
        if ($this->refusedAt() === '') {
            return self::STATE_CONNECTED;
        }
        switch ($this->settings['refused_error'] ?? $this->health()['error']) {
            case 'tenant_inactive':
                return self::STATE_NOT_ACTIVE;
            case 'plugin_update_required':
                return self::STATE_UPDATE_REQUIRED;
            default:
                return self::STATE_KEY_REFUSED;
        }
    }

    /**
     * How the last exchange or health check went (HealthCheck): `status`, the `error` code ('' for
     * none), the `http_status` (0 when no answer arrived) and the UTC time `checked_at`.
     *
     * @return array{status: string, error: string, http_status: int, checked_at: string}
     */
    public function health(): array
    {
        $health = is_array($this->settings['health'] ?? null) ? $this->settings['health'] : [];

        return [
            'status' => is_string($health['status'] ?? null) ? $health['status'] : '',
            'error' => is_string($health['error'] ?? null) ? $health['error'] : '',
            'http_status' => (int) ($health['http_status'] ?? 0),
            'checked_at' => is_string($health['checked_at'] ?? null) ? $health['checked_at'] : '',
        ];
    }

    private function string(string $key): string
    {
        return is_string($this->settings[$key] ?? null) ? $this->settings[$key] : '';
    }
}
