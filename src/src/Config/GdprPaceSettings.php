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
 * The privacy requests made to each Campaign Intelligence engine store lately (Privacy\GdprPace),
 * at the global scope: JSON {"<tenant id>": {"calls": [<Unix time>, …], "until": <Unix time>}}.
 *
 * Read as it is stored now, past the configuration that the request loaded: a tick and an admin's
 * export write it from two requests.
 *
 * Not final: the unit tests stand in for the stored value.
 */
class GdprPaceSettings extends Settings
{
    public const CALLS = self::PREFIX . 'GDPR_CALLS';

    /**
     * @return array<mixed>
     */
    public function calls(): array
    {
        $value = json_decode((string) $this->storedValue(self::CALLS), true);

        return is_array($value) ? $value : [];
    }

    /**
     * @param array<string, mixed> $calls
     */
    public function saveCalls(array $calls): void
    {
        if (!$this->setJson(self::CALLS, $calls)) {
            throw new \RuntimeException('The privacy requests to Campaign Intelligence could not be stored.');
        }
    }
}
