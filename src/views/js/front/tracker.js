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
 * The browse tracker: what the page shows (a product, a category, a search, the checkout) and
 * what the visitor does with the cart, sent in batches of up to 5 seconds to the store's own
 * relay (config.relayUrl). The store forwards them to Campaign Intelligence; the connection's
 * key never reaches the browser. Losing an event is accepted.
 *
 * Consent is the rule of consent.js. Without it the tracker sends nothing and writes no session
 * cookie. Consent that arrives later on the page (the DOM event "smaily:consent-changed") starts
 * it. Every send asks again and drops what is queued when the consent is gone.
 *
 * An event names no shopper: an event id, the anonymous session id of the session cookie, the
 * event type, and the page's product (the store's own ids), category path or search words.
 */
(function () {
    'use strict';

    var api = window.smailyConnect;
    var config = window.smailyConnectStorefront;

    if (!api || !config || !config.relayUrl || !config.cookies
        || typeof api.consentGiven !== 'function' || !api.attribution
    ) {
        return;
    }

    var BATCH_WINDOW_MS = 5000;
    var MAX_BATCH = 100;
    var CART_EVENTS = {'add-to-cart': 'cart_add', 'delete-from-cart': 'cart_remove'};
    var PAGE_EVENTS = {
        product: 'product_view',
        category: 'category_view',
        search: 'search',
        checkout: 'checkout_start',
        success: 'checkout_complete'
    };

    var consentGiven = api.consentGiven;
    var helper = api.attribution;
    var queue = [];
    var flushTimer = null;
    var started = false;

    function baseEvent(type) {
        return {
            event_id: helper.uuidv4(),
            session_id: helper.getCookie(config.cookies.session),
            event_type: type
        };
    }

    function withProduct(event, idProduct, idProductAttribute) {
        if (idProduct) {
            event.product_id = String(idProduct);
            if (idProductAttribute) {
                event.product_attribute_id = String(idProductAttribute);
            }
        }

        return event;
    }

    function flush() {
        if (flushTimer) {
            window.clearTimeout(flushTimer);
            flushTimer = null;
        }
        if (!queue.length) {
            return;
        }
        if (!consentGiven()) {
            queue.length = 0;

            return;
        }
        var payload = JSON.stringify({events: queue.splice(0, MAX_BATCH)});

        try {
            if (window.navigator && typeof window.navigator.sendBeacon === 'function') {
                window.navigator.sendBeacon(config.relayUrl, new window.Blob([payload], {type: 'application/json'}));
            } else if (typeof window.fetch === 'function') {
                window.fetch(config.relayUrl, {
                    method: 'POST',
                    credentials: 'same-origin',
                    keepalive: true,
                    headers: {'Content-Type': 'application/json'},
                    body: payload
                }).catch(function () {});
            }
        } catch (error) {
            // A browser that refuses the request changes nothing on the page.
        }
    }

    function push(event) {
        if (!started || !event.session_id) {
            return;
        }
        queue.push(event);
        if (queue.length >= MAX_BATCH) {
            flush();
        } else if (!flushTimer) {
            flushTimer = window.setTimeout(flush, BATCH_WINDOW_MS);
        }
    }

    function trackPage() {
        var page = config.page || {};
        var type = PAGE_EVENTS[page.type];

        if (!type || (type === 'search' && !page.searchQuery)) {
            return;
        }
        var event = baseEvent(type);

        if (type === 'product_view') {
            withProduct(event, page.productId, page.productAttributeId);
        }
        if (type === 'category_view' && page.categoryPath) {
            event.category_path = page.categoryPath;
        }
        if (type === 'search') {
            event.search_query = page.searchQuery;
        }
        push(event);
    }

    function start() {
        if (started || !consentGiven()) {
            return;
        }
        started = true;
        helper.ensureSession();
        trackPage();
    }

    // PrestaShop's own cart event: its reason names the action and the product.
    if (window.prestashop && typeof window.prestashop.on === 'function') {
        window.prestashop.on('updateCart', function (event) {
            var reason = event && event.reason;
            var type = reason && CART_EVENTS[reason.linkAction];

            if (type) {
                push(withProduct(baseEvent(type), reason.idProduct, reason.idProductAttribute));
            }
        });
    }

    window.addEventListener('pagehide', flush);
    document.addEventListener('smaily:consent-changed', start);

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', start);
    } else {
        start();
    }
}());
