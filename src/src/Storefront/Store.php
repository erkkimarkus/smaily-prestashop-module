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

namespace PrestaShop\Module\SmailyForPrestaShop\Storefront;

if (!defined('_PS_VERSION_')) {
    exit;
}

use PrestaShop\Module\SmailyForPrestaShop\Scheduler\Deadline;

/**
 * The storefront's short-lived values on the server: the counters of the per-address limits and
 * the cached recommendation answers. One small file per key in a folder of PrestaShop's cache
 * (`var/cache/<environment>/smaily_connect/`), which PrestaShop's "Clear cache" empties.
 *
 * - A file is named by the SHA-256 of its key and holds no key: a key is built from a keyed hash
 *   of a network address or of a shopper's identifier, never from the value itself (RateLimiter,
 *   Recommendations).
 * - A file's modification time is the time its value expires. A value past it reads as absent;
 *   prune() deletes such files, and the daily job `storefront_cache` runs it.
 * - It is a cache: a folder that cannot be written reads as empty, and nothing fails.
 */
final class Store
{
    private string $directory;

    /** @var callable(): int */
    private $clock;

    /**
     * @param callable(): int $clock the current Unix time
     */
    public function __construct(string $directory, callable $clock)
    {
        $this->directory = rtrim($directory, '/');
        $this->clock = $clock;
    }

    public function get(string $key): ?string
    {
        $file = $this->file($key);
        $expires = @filemtime($file);
        if ($expires === false || $expires <= ($this->clock)()) {
            return null;
        }
        $value = @file_get_contents($file);

        return $value === false ? null : $value;
    }

    public function set(string $key, string $value, int $seconds): void
    {
        if (!is_dir($this->directory) && !@mkdir($this->directory, 0775, true) && !is_dir($this->directory)) {
            return;
        }
        // Written beside its place and renamed, so a reader never sees half a value.
        $temporary = @tempnam($this->directory, 'tmp');
        if ($temporary === false) {
            return;
        }
        if (@file_put_contents($temporary, $value) === false
            || !@touch($temporary, ($this->clock)() + $seconds)
            || !@rename($temporary, $this->file($key))) {
            @unlink($temporary);
        }
    }

    /**
     * Deletes the files whose value has expired, until the deadline passes.
     *
     * @return int the number of files deleted
     */
    public function prune(Deadline $deadline): int
    {
        $deleted = 0;
        $now = ($this->clock)();
        foreach (is_dir($this->directory) ? new \FilesystemIterator($this->directory, \FilesystemIterator::SKIP_DOTS) : [] as $file) {
            if ($deadline->passed()) {
                break;
            }
            if ($file->isFile() && $file->getMTime() <= $now && @unlink($file->getPathname())) {
                ++$deleted;
            }
        }

        return $deleted;
    }

    private function file(string $key): string
    {
        return $this->directory . '/' . hash('sha256', $key);
    }
}
