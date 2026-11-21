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

namespace PrestaShop\Module\SmailyForPrestaShop\Admin\Setup;

if (!defined('_PS_VERSION_')) {
    exit;
}

use PrestaShop\Module\SmailyForPrestaShop\Config\AdminSettings;
use PrestaShop\Module\SmailyForPrestaShop\Config\ContactsSettings;
use PrestaShop\Module\SmailyForPrestaShop\Smaily\AccountStore;

/**
 * How far the Initial setup is, read from what its steps saved, so a reload keeps the steps
 * reached open in the rail (Magento's WizardData::getReachedStep()). No key of its own: a saved
 * Smaily account reaches Contacts, saved Contacts settings reach Automations, and a finished or
 * upgraded store reaches every step. Like Magento, the page always opens on Connect.
 */
final class SetupProgress
{
    public const STEPS = 5;

    private AdminSettings $admin;

    private AccountStore $accounts;

    private ContactsSettings $contacts;

    public function __construct(AdminSettings $admin, AccountStore $accounts, ContactsSettings $contacts)
    {
        $this->admin = $admin;
        $this->accounts = $accounts;
        $this->contacts = $contacts;
    }

    /** The setup was finished, or the store was upgraded with its settings: nothing is forced. */
    public function isComplete(): bool
    {
        return in_array($this->admin->setupState(), [AdminSettings::SETUP_DONE, AdminSettings::SETUP_UPGRADED], true);
    }

    public function reachedStep(SetupScope $scope): int
    {
        if ($this->isComplete()) {
            return self::STEPS;
        }
        $target = $scope->targetShop();
        if ($target === null || $this->accounts->find($target, '') === null) {
            return 1;
        }

        return $this->contacts->isStored($target) && $this->contacts->syncActive($target) ? 3 : 2;
    }

    /** "Finish": the setup is done, and the module's Configure button opens the Dashboard. */
    public function finish(): bool
    {
        return $this->admin->setSetupState(AdminSettings::SETUP_DONE);
    }
}
