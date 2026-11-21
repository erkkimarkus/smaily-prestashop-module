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
     * The UTC time since which the engine refuses this connection, or '' while it does not.
     */
    public function refusedAt(): string
    {
        return $this->string('refused_at');
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
