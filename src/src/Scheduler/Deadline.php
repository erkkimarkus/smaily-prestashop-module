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

namespace PrestaShop\Module\SmailyForPrestaShop\Scheduler;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * The time by which a job stops starting new work. A job checks it between batches; a batch that
 * has started runs to its end.
 */
final class Deadline
{
    private float $at;

    /** @var callable(): float */
    private $clock;

    /**
     * @param float $at the Unix time, with fractions, at which the deadline passes
     * @param callable(): float $clock the current Unix time with fractions
     */
    public function __construct(float $at, callable $clock)
    {
        $this->at = $at;
        $this->clock = $clock;
    }

    public function passed(): bool
    {
        return ($this->clock)() >= $this->at;
    }
}
