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
{* The shopper's recommended products, drawn by the theme's own product list and miniature. The
   frame around them is the one the theme gives its own product blocks: the section title of a
   theme that is built from components (PrestaShop 9's default theme), else the classic theme's
   markup. A theme can replace this file as it replaces any module template. *}
{if $smaily_connect_theme_title}
  <section class="smaily-connect-recommendations-products">
    <div class="module-products">
      {include file='components/section-title.tpl' title={l s='Recommended for you' d='Modules.Smailyforprestashop.Shop'}}
      <div class="module-products__list">
        {include file='catalog/_partials/productlist.tpl' products=$smaily_connect_products}
      </div>
    </div>
  </section>
{else}
  <section class="featured-products clearfix smaily-connect-recommendations-products">
    <h2 class="h2 products-section-title text-uppercase">
      {l s='Recommended for you' d='Modules.Smailyforprestashop.Shop'}
    </h2>
    {include file='catalog/_partials/productlist.tpl' products=$smaily_connect_products cssClass='row' productClass='col-xs-12 col-sm-6 col-lg-4 col-xl-3'}
  </section>
{/if}
