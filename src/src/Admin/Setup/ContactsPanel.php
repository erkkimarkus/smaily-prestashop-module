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

use PrestaShop\Module\SmailyForPrestaShop\Admin\BaseController;
use PrestaShop\Module\SmailyForPrestaShop\Config\ContactsSettings;
use PrestaShop\Module\SmailyForPrestaShop\Contacts\ContactSyncMode;
use PrestaShop\Module\SmailyForPrestaShop\Contacts\SubscriberPayloadBuilder;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The Contacts panel (Initial setup step 2, Settings > Contacts): who is synced, the extra fields,
 * "include guests" and the checkout checkbox of the page's scope, read and saved by this one
 * class, as Magento's WizardStepSaver::saveSubscribers() saves them.
 *
 * Saving is what starts contact sync: it confirms the settings and switches sync on, unless the
 * post switches it off (`enabled` false, the switch that a store with sync off sees). A fresh
 * install sends nothing to Smaily before this save.
 *
 * Defaults where nothing is stored: subscribers only, every field, guests off (the narrowest
 * audience), and the checkout checkbox on (ARCHITECTURE 6.1).
 */
final class ContactsPanel
{
    private ContactsSettings $settings;

    private TranslatorInterface $translator;

    /** @var callable(): bool */
    private $newsletterModuleActive;

    /**
     * @param callable(): bool $newsletterModuleActive whether ps_emailsubscription is active, which
     *                                                 then shows the checkout checkbox itself
     */
    public function __construct(ContactsSettings $settings, TranslatorInterface $translator, callable $newsletterModuleActive)
    {
        $this->settings = $settings;
        $this->translator = $translator;
        $this->newsletterModuleActive = $newsletterModuleActive;
    }

    /**
     * @return array{stored: bool, active: bool, mode: string, guests: bool, fields: list<string>, checkout_checkbox: bool, newsletter_module: bool}|null null when the scope saves nothing
     */
    public function values(SetupScope $scope): ?array
    {
        if ($scope->targetShop() === null) {
            return null;
        }
        $shop = $scope->readShop();
        $stored = $this->settings->isStored($shop);
        $mode = $this->settings->mode($shop);

        return [
            'stored' => $stored,
            'active' => $this->settings->syncActive($shop),
            'mode' => $mode->mode(),
            'guests' => $stored && $mode->includesNewsletterGuests(),
            'fields' => $stored ? $this->settings->fields($shop) : SubscriberPayloadBuilder::FIELDS,
            'checkout_checkbox' => $this->settings->checkoutCheckbox($shop) ?? true,
            'newsletter_module' => (bool) ($this->newsletterModuleActive)(),
        ];
    }

    /**
     * Checks and saves the posted `mode`, `fields`, `guests`, `checkout_checkbox` and, where the
     * page shows the switch, `enabled`.
     *
     * @param array<string, mixed> $data
     *
     * @return list<array{field: string, message: string}>
     */
    public function save(SetupScope $scope, array $data): array
    {
        $target = $scope->targetShop();
        if ($target === null) {
            return [['field' => '', 'message' => $this->trans('These shops are set up as separate stores. Reload the page and set each shop up on its own.')]];
        }
        $mode = (string) ($data['mode'] ?? '');
        if (!in_array($mode, [ContactSyncMode::SUBSCRIBERS, ContactSyncMode::ALL_CUSTOMERS, ContactSyncMode::CHECKOUT_OPTIN], true)) {
            return [['field' => 'mode', 'message' => $this->trans('Pick who should be synced to Smaily.')]];
        }
        $fields = is_array($data['fields'] ?? null) ? array_map('strval', $data['fields']) : [];

        $this->settings->setMode(new ContactSyncMode($mode, !empty($data['guests'])), $target);
        $this->settings->setFields($fields, $target);
        $this->settings->setCheckoutCheckbox(!empty($data['checkout_checkbox']), $target);
        $this->settings->setEnabled(!array_key_exists('enabled', $data) || !empty($data['enabled']), $target);
        // The merchant has now chosen how contacts sync: contact sync starts here (Magento PRO-4036).
        $this->settings->confirm($target);

        return [];
    }

    private function trans(string $message): string
    {
        return $this->translator->trans($message, [], BaseController::DOMAIN);
    }
}
