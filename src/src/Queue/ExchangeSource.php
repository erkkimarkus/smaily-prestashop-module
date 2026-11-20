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
 * A handler that tells what it sent for a row and what came back. The flusher stores it on the row
 * (EventQueue::recordExchange()) for the Log's Details.
 */
interface ExchangeSource
{
    /**
     * The exchanges of the last handle() call, by row id: the row's own part of the request body,
     * and the answer to that request (Smaily\Client::lastExchange()), null when none arrived. A row
     * for which nothing was sent has no entry.
     *
     * @return array<int, array{request: array<int|string, mixed>, response: array{http_status: int, body: mixed}|null}>
     */
    public function exchanges(): array;
}
