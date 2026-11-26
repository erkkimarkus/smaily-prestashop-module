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

namespace PrestaShop\Module\SmailyForPrestaShop\Queue;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * A handler's outcome for a row that may not go before a given time through no fault of the row
 * (a remote service's rate limit): the row goes back to pending with that time as its next
 * attempt, and no attempt is spent. A flush does not claim it before then.
 */
final class Deferred
{
    private int $until;

    private ?string $reason;

    /**
     * @param int $until the Unix time from which the row may go
     * @param string|null $reason what the Log shows for the row while it waits; null keeps the
     *                            row's reason as it is
     */
    public function __construct(int $until, ?string $reason = null)
    {
        $this->until = $until;
        $this->reason = $reason;
    }

    public function until(): int
    {
        return $this->until;
    }

    public function reason(): ?string
    {
        return $this->reason;
    }
}
