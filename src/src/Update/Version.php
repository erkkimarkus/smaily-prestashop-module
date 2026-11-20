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

namespace PrestaShop\Module\SmailyForPrestaShop\Update;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Version numbers of the module's releases.
 */
final class Version
{
    /** Numbers joined by dots, at least two; a release tag may carry a leading "v". */
    private const PLAIN_TAG = '/^v?(\d+(?:\.\d+)+)$/D';

    /**
     * The version a release tag names, or null when the tag is no plain version number (a release
     * candidate, a name).
     */
    public static function fromTag(string $tag): ?string
    {
        return preg_match(self::PLAIN_TAG, $tag, $match) === 1 ? $match[1] : null;
    }

    /**
     * Whether $candidate is a later version than $than, compared as numbers: 3.0.10 is later than
     * 3.0.9, and 3.0.0 is later than 3.0.0-rc1.
     */
    public static function isNewer(string $candidate, string $than): bool
    {
        return version_compare($candidate, $than, '>');
    }
}
