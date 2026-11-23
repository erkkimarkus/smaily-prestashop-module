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

use PrestaShop\Module\SmailyForPrestaShop\Config\ReportSettings;

/**
 * The last answer each connection got: what the installation report says about a connection's
 * health (contract installation-report-v1 "Connections"), as the Magento connector's
 * ConnectionHealth.
 *
 * For each Smaily account (by subdomain) and each Campaign Intelligence engine store (by tenant
 * id) it keeps whether the most recent request failed, with its error CODE, and when the last
 * request that writes data succeeded. The two HTTP chokepoints report every answer here:
 * Smaily\Client::request() (a POST answered with code 101 is a sync: every POST writes contacts or
 * triggers a workflow) and Engine\Client::request() (a 2xx POST to an `/ingest/` path is a sync).
 *
 * It stores codes only, never a message, a body or a customer identifier. The storefront's browse
 * relay passes the engine chokepoint, so a success that changes nothing but the time is written at
 * most once per WRITE_EVERY_SECONDS; a change of status or error code is written at once.
 * Recording never throws: a failed write costs the health, never the request.
 *
 * Not final: the unit tests stand in for it.
 */
class ConnectionHealth
{
    public const STATUS_OK = 'ok';
    public const STATUS_FAILING = 'failing';

    /** Smaily answered code 227: the account's package has no API. */
    public const PLAN_BLOCKED = 'plan_blocked';

    /** Any other 4xx except 429 (a wrong subdomain answers 404). */
    public const CREDENTIALS_REJECTED = 'credentials_rejected';

    /** A 429, a 5xx, a timeout or a network error. */
    public const UNREACHABLE = 'unreachable';

    /** A success that changes nothing but the time is written at most this often. */
    public const WRITE_EVERY_SECONDS = 300;

    /** The contract's code rule; any other code is stored as UNKNOWN_CODE. */
    public const CODE_PATTERN = '/^[a-z0-9_]{1,64}$/D';

    /** The most entries kept per group; the oldest go first. */
    public const MAX_ENTRIES = 50;

    private const UNKNOWN_CODE = 'unknown_error';

    private const GROUP_SMAILY = 'smaily';

    private const GROUP_ENGINE = 'engine';

    private ReportSettings $settings;

    /** @var callable(): int */
    private $now;

    /**
     * @param callable(): int $now the current Unix time
     */
    public function __construct(ReportSettings $settings, callable $now)
    {
        $this->settings = $settings;
        $this->now = $now;
    }

    /**
     * A Smaily API answer: '' for a success, else its error code. $wrote is true when the request
     * wrote data (contacts, a workflow trigger).
     */
    public function recordSmaily(string $subdomain, string $errorCode, bool $wrote): void
    {
        $this->record(self::GROUP_SMAILY, strtolower(trim($subdomain)), $errorCode, $wrote);
    }

    /**
     * A Campaign Intelligence answer for one engine store: '' for a success, else the engine's
     * error code. $wrote is true for an accepted ingest request.
     */
    public function recordEngine(string $tenantId, string $errorCode, bool $wrote): void
    {
        $this->record(self::GROUP_ENGINE, trim($tenantId), $errorCode, $wrote);
    }

    /**
     * The health of one Smaily account, in the report's shape.
     *
     * @return array{status: string, last_sync_at: ?int, last_error_code: ?string}
     */
    public function smaily(string $subdomain): array
    {
        return $this->shape(self::GROUP_SMAILY, strtolower(trim($subdomain)));
    }

    /**
     * The health of one engine store, in the report's shape.
     *
     * @return array{status: string, last_sync_at: ?int, last_error_code: ?string}
     */
    public function engine(string $tenantId): array
    {
        return $this->shape(self::GROUP_ENGINE, trim($tenantId));
    }

    private function record(string $group, string $key, string $errorCode, bool $wrote): void
    {
        if ($key === '') {
            return;
        }

        try {
            $all = $this->settings->health();
            $entries = is_array($all[$group] ?? null) ? $all[$group] : [];
            $entry = is_array($entries[$key] ?? null) ? $entries[$key] : [];

            $now = ($this->now)();
            $code = $errorCode === '' || preg_match(self::CODE_PATTERN, $errorCode) === 1 ? $errorCode : self::UNKNOWN_CODE;
            $old = isset($entry['error_code']) ? (string) $entry['error_code'] : null;
            $last = (int) ($entry['last_sync_at'] ?? 0);

            $synced = $code === '' && $wrote;
            if ($old === $code && !($synced && $last <= $now - self::WRITE_EVERY_SECONDS)) {
                return;
            }

            $entry['error_code'] = $code;
            if ($synced) {
                $entry['last_sync_at'] = $now;
            }

            // The newest entry goes last, so the cap drops the oldest.
            unset($entries[$key]);
            $entries[$key] = $entry;
            $all[$group] = array_slice($entries, -self::MAX_ENTRIES, null, true);

            $this->settings->saveHealth($all);
        } catch (\Throwable $error) {
            // The health is best effort; the request it describes goes on.
            return;
        }
    }

    /**
     * @return array{status: string, last_sync_at: ?int, last_error_code: ?string}
     */
    private function shape(string $group, string $key): array
    {
        try {
            $all = $this->settings->health();
        } catch (\Throwable $error) {
            $all = [];
        }
        $entries = is_array($all[$group] ?? null) ? $all[$group] : [];
        $entry = is_array($entries[$key] ?? null) ? $entries[$key] : [];
        $code = (string) ($entry['error_code'] ?? '');
        $last = (int) ($entry['last_sync_at'] ?? 0);

        return [
            'status' => $code === '' ? self::STATUS_OK : self::STATUS_FAILING,
            'last_sync_at' => $last > 0 ? $last : null,
            'last_error_code' => $code === '' ? null : $code,
        ];
    }
}
