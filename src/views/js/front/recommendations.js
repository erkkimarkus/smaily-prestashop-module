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
 * The recommendations block: fills the empty containers that the module's widget left in the
 * page with the shopper's recommended products. After the page has loaded, and only with the
 * visitor's consent (consent.js), it asks the store's own endpoint (config.recommendationsUrl)
 * once per page and puts the HTML it answers into every container.
 *
 * The store decides who the shopper is from its own session and cookies; the request says
 * nothing about the shopper. An empty answer, an error or a timeout leaves the containers empty:
 * no heading and no frame. A page without a container asks nothing.
 *
 * Consent that arrives later on the page (the DOM event "smaily:consent-changed") asks then.
 */
(function () {
    'use strict';

    var api = window.smailyConnect;
    var config = window.smailyConnectStorefront;

    if (!api || !config || !config.recommendationsUrl
        || typeof api.consentGiven !== 'function' || typeof window.fetch !== 'function'
    ) {
        return;
    }

    var CONTAINER = '[data-smaily-connect-recs]';
    var consentGiven = api.consentGiven;
    var asked = false;

    function fill(html) {
        if (!html || !html.trim()) {
            return;
        }
        Array.prototype.forEach.call(document.querySelectorAll(CONTAINER), function (container) {
            container.innerHTML = html;
        });
    }

    function ask() {
        if (asked || !document.querySelector(CONTAINER) || !consentGiven()) {
            return;
        }
        asked = true;
        window.fetch(config.recommendationsUrl, {
            credentials: 'same-origin',
            cache: 'no-store',
            headers: {Accept: 'text/html'}
        }).then(function (response) {
            return response.ok ? response.text() : '';
        }).then(fill).catch(function () {});
    }

    function start() {
        ask();
        document.addEventListener('smaily:consent-changed', ask);
    }

    if (document.readyState === 'complete') {
        start();
    } else {
        window.addEventListener('load', start);
    }
}());
