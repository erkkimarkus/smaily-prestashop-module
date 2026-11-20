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

namespace PrestaShop\Module\SmailyForPrestaShop\Migration;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * The override of ps_emailsubscription that 2.x copied into the shop's override folder at install
 * (only when no other module had put a file there), and that its uninstall deleted whoever owned it.
 * 3.0.0 ships no override. The file is removed only when its content is a copy that a 2.x release
 * shipped; any other file belongs to someone else and stays.
 *
 * PrestaShop loads a module override by its path (Module::coreLoadModule() checks the file without a
 * cache), and no class index lists it, so removing the file is all it takes.
 */
final class LegacyOverride
{
    /**
     * SHA-256 of `src/overrides/ps_emailsubscription.php` at the tags 2.0.0, 2.1.0, 2.1.1, 2.1.2 and
     * 2.2.0: the file is the same in all five.
     */
    public const SHIPPED_HASHES = [
        '5fb063e253e12c6db9789a6ca8e30c2bad0fbb83c19630a5a50191087643a56d',
    ];

    public const ABSENT = 'absent';
    public const REMOVED = 'removed';
    public const FOREIGN = 'foreign';

    /** The installed file, relative to the shop's override folder. */
    public const RELATIVE_PATH = 'modules/ps_emailsubscription/ps_emailsubscription.php';

    private string $overrideDir;

    /**
     * @param string $overrideDir the shop's override folder; _PS_OVERRIDE_DIR_ in a shop
     */
    public function __construct(string $overrideDir)
    {
        $this->overrideDir = rtrim($overrideDir, '/');
    }

    /**
     * Removes the installed file when 2.x shipped it, and then its folder when that is empty.
     *
     * @return string ABSENT, REMOVED, or FOREIGN for a file left in place
     */
    public function removeIfShipped(): string
    {
        $file = $this->overrideDir . '/' . self::RELATIVE_PATH;
        if (!is_file($file)) {
            return self::ABSENT;
        }
        if (!in_array(hash_file('sha256', $file), self::SHIPPED_HASHES, true)) {
            return self::FOREIGN;
        }
        if (!unlink($file)) {
            throw new \RuntimeException('The 2.x override file could not be removed.');
        }
        $dir = dirname($file);
        if (scandir($dir) === ['.', '..']) {
            rmdir($dir);
        }

        return self::REMOVED;
    }
}
