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
 * What the daily update check last learnt, at the global scope (the module's version is the same
 * for every shop): JSON {"version", "download_url", "release_url", "checked_at", "dismissed"}.
 * Update\UpdateNotice reads and writes it.
 *
 * Not final: the unit tests stand in for the stored settings.
 */
class UpdateSettings extends Settings
{
    public const KEY = self::PREFIX . 'UPDATE_CHECK';

    /**
     * The state as this request loaded it with the rest of the configuration: reading it costs no
     * query.
     *
     * @return array<mixed>
     */
    public function state(): array
    {
        return $this->getJson(self::KEY);
    }

    /**
     * The state as it is stored now, also when another request wrote it after this one started.
     *
     * @return array<mixed>
     */
    public function storedState(): array
    {
        $value = json_decode((string) $this->storedValue(self::KEY), true);

        return is_array($value) ? $value : [];
    }

    /**
     * @param array<string, int|string|null> $state
     */
    public function save(array $state): void
    {
        if (!$this->setJson(self::KEY, $state)) {
            throw new \RuntimeException('The update state could not be stored.');
        }
    }
}
