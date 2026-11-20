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
 * The admin screens' own state, global: how far the Initial setup is, and when an admin dismissed
 * the invitation to connect a Smaily account.
 */
final class AdminSettings extends Settings
{
    public const SETUP_STATE = self::PREFIX . 'SETUP_STATE';

    public const INVITATION_DISMISSED_AT = self::PREFIX . 'INVITATION_DISMISSED_AT';

    /** A fresh install: every module page opens the Initial setup until it is finished. */
    public const SETUP_NEW = 'new';

    /** An upgraded store: the Initial setup is prefilled and does not block the other pages. */
    public const SETUP_UPGRADED = 'upgraded';

    public const SETUP_DONE = 'done';

    /** The invitation stays dismissed this long, for every admin (the siblings' 90 days). */
    public const INVITATION_DISMISS_SECONDS = 90 * 86400;

    /**
     * The stored state of the Initial setup, null while none is stored: a fresh install stores
     * none, and the upgrade stores `upgraded` (or `new`) when it migrates the settings.
     */
    public function setupState(): ?string
    {
        return $this->get(self::SETUP_STATE);
    }

    public function setSetupState(string $state): bool
    {
        return $this->set(self::SETUP_STATE, $state);
    }

    public function isInvitationDismissed(int $now): bool
    {
        $at = (int) $this->get(self::INVITATION_DISMISSED_AT);

        return $at > 0 && $now - $at < self::INVITATION_DISMISS_SECONDS;
    }

    public function dismissInvitation(int $now): bool
    {
        return $this->set(self::INVITATION_DISMISSED_AT, (string) $now);
    }
}
