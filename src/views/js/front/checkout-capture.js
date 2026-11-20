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
 * The checkout keeps a guest's address on the page until the shopper finishes the personal
 * information step, so a guest who leaves before that has no address for the abandoned-cart
 * reminder. When the shopper leaves the email field of the checkout's customer form (or changes its
 * newsletter box) and the field holds a valid address, this posts the address and the box to the
 * module's capture endpoint, which records them on the visitor's own cart.
 *
 * One request per change of the address or the box, never one per key. The address goes in the
 * request body only; it is not stored in the browser or logged. Fire-and-forget: the answer is not
 * read, and a failure changes nothing in the checkout. On a theme whose form has other names
 * nothing matches and nothing is sent.
 */
(function () {
    'use strict';

    var config = window.smailyConnectCapture;
    if (!config || typeof config.url !== 'string' || typeof config.token !== 'string'
        || typeof window.fetch !== 'function' || typeof window.URLSearchParams !== 'function'
    ) {
        return;
    }

    var EMAIL = '#customer-form input[name="email"]';
    var BOX = '#customer-form input[name="newsletter"]';
    var VALID = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
    var DELAY = 400;
    var lastSent = null;
    var timer = null;

    function send() {
        timer = null;
        var field = document.querySelector(EMAIL);
        var email = field ? String(field.value || '').trim() : '';
        if (email.length > 255 || !VALID.test(email)) {
            return;
        }
        var box = document.querySelector(BOX);
        var ticked = box && box.checked ? '1' : '0';
        if (lastSent === email + ' ' + ticked) {
            return;
        }
        lastSent = email + ' ' + ticked;

        var body = new URLSearchParams();
        body.append('token', config.token);
        body.append('email', email);
        body.append('newsletter', ticked);
        try {
            window.fetch(config.url, {
                method: 'POST',
                body: body,
                credentials: 'same-origin',
                keepalive: true
            }).catch(function () {});
        } catch (error) {
            // A browser that refuses the request changes nothing in the checkout.
        }
    }

    function schedule(event) {
        var target = event.target;
        if (!target || typeof target.matches !== 'function' || !(target.matches(EMAIL) || target.matches(BOX))) {
            return;
        }
        if (timer !== null) {
            window.clearTimeout(timer);
        }
        timer = window.setTimeout(send, DELAY);
    }

    document.addEventListener('change', schedule, true);
    document.addEventListener('focusout', schedule, true);
}());
