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
 * Sends a batch of queued events of one event type.
 */
interface HandlerInterface
{
    /**
     * @param list<array<string, mixed>> $events queue rows, all of one event type
     *
     * @return array<int, mixed> per queue row id, the outcome of that row: true when it was sent,
     *                           else the outcome type that the queue defines (a failure, a skip, or
     *                           a row given back unchanged)
     */
    public function handle(array $events): array;
}
