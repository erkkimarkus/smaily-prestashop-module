/*
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

/*
 * The visitor's consent to Smaily's browse tracking and recommendations: one rule for the
 * storefront scripts (tracker.js, recommendations.js), in this order.
 *
 * 1. The store's own window.smailyConnect.consentOverride(), where the store defines it: its
 *    answer decides. true is consent; any other answer, and a function that throws, is none.
 * 2. Otherwise the merchant's setting "My store's cookie banner covers Smaily tracking"
 *    (smailyConnectStorefront.consentConfirmed), which is off until the merchant confirms it.
 * 3. Otherwise no consent.
 *
 * The rule is asked every time it matters, so a store's banner can change its answer on an open
 * page; a store that does fires the DOM event "smaily:consent-changed" on document.
 *
 * To probe it on a live page: window.smailyConnect.consentGiven() in the browser's console.
 *
 * window.smailyConnect is the one global these scripts use. A store may define it before or
 * after them; the scripts add to it and never replace it.
 */
(function () {
    'use strict';

    if (typeof window.smailyConnect !== 'object' || window.smailyConnect === null) {
        window.smailyConnect = {};
    }

    window.smailyConnect.consentGiven = function () {
        var store = window.smailyConnect;
        var config = window.smailyConnectStorefront;

        if (store && typeof store.consentOverride === 'function') {
            try {
                return store.consentOverride() === true;
            } catch (error) {
                return false;
            }
        }

        return !!config && config.consentConfirmed === true;
    };
}());
