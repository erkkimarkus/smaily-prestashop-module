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

namespace PrestaShop\Module\SmailyForPrestaShop\Contacts\Capture;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * The module's own newsletter box on the customer form (ARCHITECTURE 6.1), for a store without the
 * newsletter block: where `ps_emailsubscription` is active it adds the same box, and a second one
 * would confuse shoppers.
 *
 * The box is the form field `newsletter`, so PrestaShop writes it to `customer.newsletter` as it
 * writes the block's box. Like the block's box it is on every customer form, the account page
 * included, so that a shopper can take the consent back where they gave it.
 */
final class CheckoutBox
{
    private CheckoutBoxSetting $setting;

    /** @var callable(): bool */
    private $blockActive;

    /**
     * @param callable(): bool $blockActive whether `ps_emailsubscription` is active in the shop
     */
    public function __construct(CheckoutBoxSetting $setting, callable $blockActive)
    {
        $this->setting = $setting;
        $this->blockActive = $blockActive;
    }

    /**
     * additionalCustomerFormFields: the box, or none.
     *
     * @return list<\FormField>|null
     */
    public function fields(): ?array
    {
        if (($this->blockActive)() || !$this->setting->isOn((int) \Context::getContext()->shop->id)) {
            return null;
        }

        return [
            (new \FormField())
                ->setName('newsletter')
                ->setType('checkbox')
                ->setLabel(\Context::getContext()->getTranslator()->trans('Sign up for our newsletter', [], 'Modules.Smailyforprestashop.Shop')),
        ];
    }
}
