{**
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
 *}
{*
 * The link "Personalization" of the customer's account (Privacy\AccountLink), in the markup of the
 * theme's own account links: a link of the account menu where the theme has one, else a tile of
 * the classic theme's account page.
 *}
{if $smaily_personalization_link.account_menu}
  <a
    class="account-menu__link account-menu__link--smaily-personalization{if $urls.current_url === $smaily_personalization_link.url} account-menu__link--active{/if}"
    href="{$smaily_personalization_link.url}"
    {if $urls.current_url === $smaily_personalization_link.url}aria-current="page"{/if}
  >
    <i class="account-menu__icon material-icons" aria-hidden="true">&#xE429;</i>
    {l s='Personalization' d='Modules.Smailyforprestashop.Shop'}
  </a>
{else}
  <a class="col-lg-4 col-md-6 col-sm-6 col-xs-12" id="smaily-personalization-link" href="{$smaily_personalization_link.url}">
    <span class="link-item">
      <i class="material-icons">&#xE429;</i>
      {l s='Personalization' d='Modules.Smailyforprestashop.Shop'}
    </span>
  </a>
{/if}
