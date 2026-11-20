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

namespace PrestaShop\Module\SmailyForPrestaShop\Admin\Dashboard;

if (!defined('_PS_VERSION_')) {
    exit;
}

use PrestaShop\Module\SmailyForPrestaShop\Config\ContactsSettings;
use PrestaShop\Module\SmailyForPrestaShop\Config\Settings;
use PrestaShop\Module\SmailyForPrestaShop\Config\TickSettings;

/**
 * The settings that the Dashboard reads and no settings class exposes on its own: read only,
 * through Config\Settings's scope rule (the shop's own value, else the global one).
 */
final class DashboardSettings extends Settings
{
    public function contactsEnabled(int $idShop): bool
    {
        return $this->get(ContactsSettings::ENABLED, $idShop) === '1';
    }

    public function contactsConfirmed(int $idShop): bool
    {
        return $this->get(ContactsSettings::CONFIRMED, $idShop) === '1';
    }

    /**
     * The tick URL's token as stored, or null while none is: unlike TickSettings::token(), this
     * read never creates one.
     */
    public function tickToken(): ?string
    {
        $token = $this->get(TickSettings::TICK_TOKEN);

        return $token === null || $token === '' ? null : $token;
    }
}
