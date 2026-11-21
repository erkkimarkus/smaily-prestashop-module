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
 * The page "Personalization" of the customer's account (front controller `personalization`), in
 * the theme's own account layout, with the Magento connector's wording. The checkbox and the
 * button take the form markup of the theme: Bootstrap 5 where the theme has an account menu
 * (Hummingbird), else the classic theme's.
 *}
{extends file='customer/page.tpl'}

{block name='page_title'}
  {l s='Personalized Recommendations' d='Modules.Smailyforprestashop.Shop'}
{/block}

{block name='page_content'}
  <form action="{$smaily_personalization.action}" method="post" id="smaily-personalization-form">
    <input type="hidden" name="token" value="{$smaily_personalization.token}">
    <p>{l s='We use your shopping activity to personalize product recommendations in our newsletters. You can opt out at any time; newsletters will then show generic content.' d='Modules.Smailyforprestashop.Shop'}</p>
    {if $smaily_personalization.account_menu}
      <div class="form-check mb-3">
        <input class="form-check-input" type="checkbox" id="smaily-profiling-allowed" name="profiling_allowed" value="1"{if $smaily_personalization.allowed} checked{/if}>
        <label class="form-check-label" for="smaily-profiling-allowed">{l s='Allow personalized recommendations' d='Modules.Smailyforprestashop.Shop'}</label>
      </div>
      <button class="btn btn-primary" type="submit" name="submitPersonalization" value="1">{l s='Save Preference' d='Modules.Smailyforprestashop.Shop'}</button>
    {else}
      <div class="form-group">
        <span class="custom-checkbox">
          <label>
            <input type="checkbox" id="smaily-profiling-allowed" name="profiling_allowed" value="1"{if $smaily_personalization.allowed} checked{/if}>
            <span><i class="material-icons rtl-no-flip checkbox-checked">&#xE5CA;</i></span>
            {l s='Allow personalized recommendations' d='Modules.Smailyforprestashop.Shop'}
          </label>
        </span>
      </div>
      <footer class="form-footer clearfix">
        <button class="btn btn-primary form-control-submit" type="submit" name="submitPersonalization" value="1">{l s='Save Preference' d='Modules.Smailyforprestashop.Shop'}</button>
      </footer>
    {/if}
  </form>
{/block}
