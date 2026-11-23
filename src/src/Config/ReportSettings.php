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

namespace PrestaShop\Module\SmailyForPrestaShop\Config;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * What the installation report keeps, at the global scope (one report covers the installation):
 *
 * - HEALTH, JSON {"smaily": {"<subdomain>": {"error_code", "last_sync_at"}}, "engine":
 *   {"<tenant id>": {…}}}: how the last request of each Smaily account and of each Campaign
 *   Intelligence engine store went (Report\ConnectionHealth);
 * - STATE, JSON {"setup_saved_at", "daily_at", "retry": {"trigger", "attempt", "at"}}: when the
 *   next report is due (Report\InstallationReport).
 *
 * Both are read as they are stored now, past the configuration that the request loaded: a tick and
 * an admin's save write them from two requests.
 *
 * Not final: the unit tests stand in for the stored settings.
 */
class ReportSettings extends Settings
{
    public const HEALTH = self::PREFIX . 'HEALTH';

    public const STATE = self::PREFIX . 'REPORT_STATE';

    /**
     * @return array<mixed>
     */
    public function health(): array
    {
        return $this->stored(self::HEALTH);
    }

    /**
     * @param array<string, mixed> $health
     */
    public function saveHealth(array $health): void
    {
        $this->save(self::HEALTH, $health);
    }

    /**
     * @return array<mixed>
     */
    public function state(): array
    {
        return $this->stored(self::STATE);
    }

    /**
     * @param array<string, mixed> $state
     */
    public function saveState(array $state): void
    {
        $this->save(self::STATE, $state);
    }

    /**
     * @return array<mixed>
     */
    private function stored(string $key): array
    {
        $value = json_decode((string) $this->storedValue($key), true);

        return is_array($value) ? $value : [];
    }

    /**
     * @param array<string, mixed> $value
     */
    private function save(string $key, array $value): void
    {
        // PrestaShop strips tags from a configuration value: the JSON spells the characters of a
        // tag as escapes, so a key that holds one is stored as it came.
        if (!$this->set($key, (string) json_encode($value, JSON_HEX_TAG | JSON_HEX_AMP))) {
            throw new \RuntimeException('The installation report\'s settings could not be stored.');
        }
    }
}
