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
 * The landing capture: a click on a Smaily link lands with the link's parameters (by default
 * smaily_rec, smaily_ctx and smaily_vt), and the order that follows is credited to that click.
 *
 * The script reads and checks the parameters and posts them to the store's landing endpoint
 * (config.landUrl), whose answer sets the three cookies from the server: Safari keeps a cookie
 * that page JavaScript writes for 7 days at most. The script writes the cookies itself only when
 * that request fails.
 *
 * - A recommendation id counts only as a UUID: the engine refuses a whole order over a malformed
 *   one. A landing with a recommendation id sets the context cookie to the link's context, or
 *   clears it when the link has none: the two cookies describe the same landing.
 * - A visitor token is taken only in the engine's form (vt_...). An engine link never carries a
 *   store-created token (vs_...), so one in a link is a value its sender chose.
 * - These cookies are not gated on the tracking consent, as in every Smaily connector: they are
 *   first-party cookies that record the click on the merchant's own email. Nothing else is
 *   written here: the browse session cookie is written by ensureSession(), which the tracker
 *   calls only with consent.
 *
 * The helpers that the tracker shares are kept on window.smailyConnect.attribution.
 */
(function () {
    'use strict';

    var api = window.smailyConnect;
    var config = window.smailyConnectStorefront;

    if (!api || !config || !config.cookies || !config.days || !config.parameters) {
        return;
    }

    var REC_ID = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i;
    var VISITOR_TOKEN = /^vt_[A-Za-z0-9]{1,61}$/;
    var CONTEXT = /^[A-Za-z0-9._-]{1,64}$/;

    function shaped(value, pattern) {
        return value && pattern.test(value) ? value : null;
    }

    function getCookie(name) {
        var match = document.cookie.match(new RegExp('(?:^|; )' + name + '=([^;]*)'));

        return match ? decodeURIComponent(match[1]) : null;
    }

    function setCookie(name, value, days) {
        document.cookie = name + '=' + encodeURIComponent(value)
            + '; expires=' + new Date(Date.now() + days * 86400000).toUTCString()
            + '; path=' + (config.cookiePath || '/') + '; SameSite=Lax'
            + (window.location.protocol === 'https:' ? '; Secure' : '');
    }

    function uuidv4() {
        if (window.crypto && typeof window.crypto.randomUUID === 'function') {
            return window.crypto.randomUUID();
        }
        var bytes = new Uint8Array(16);

        window.crypto.getRandomValues(bytes);
        bytes[6] = bytes[6] & 0x0f | 0x40;
        bytes[8] = bytes[8] & 0x3f | 0x80;
        var hex = Array.prototype.map.call(bytes, function (byte) {
            return ('0' + byte.toString(16)).slice(-2);
        }).join('');

        return hex.slice(0, 8) + '-' + hex.slice(8, 12) + '-' + hex.slice(12, 16)
            + '-' + hex.slice(16, 20) + '-' + hex.slice(20);
    }

    // The anonymous session id of the browse events. The tracker calls this once the visitor has
    // consented; nothing else does.
    function ensureSession() {
        if (!getCookie(config.cookies.session)) {
            setCookie(config.cookies.session, uuidv4(), config.days.session);
        }
    }

    api.attribution = {
        getCookie: getCookie,
        uuidv4: uuidv4,
        ensureSession: ensureSession
    };

    if (typeof window.URLSearchParams !== 'function') {
        return;
    }

    var query = new window.URLSearchParams(window.location.search);
    var recId = shaped(query.get(config.parameters.recId), REC_ID);
    var visitorToken = shaped(query.get(config.parameters.visitor), VISITOR_TOKEN);
    var context = recId ? shaped(query.get(config.parameters.context), CONTEXT) : null;

    if (!recId && !visitorToken) {
        return;
    }

    function writeCookies() {
        if (recId) {
            setCookie(config.cookies.recId, recId, config.days.recId);
            setCookie(config.cookies.context, context || '', context ? config.days.context : -1);
        }
        if (visitorToken) {
            setCookie(config.cookies.visitor, visitorToken, config.days.visitor);
        }
    }

    if (!config.landUrl || typeof window.fetch !== 'function') {
        writeCookies();

        return;
    }

    var body = new window.URLSearchParams();

    if (recId) {
        body.append('rec_id', recId);
    }
    if (context) {
        body.append('context', context);
    }
    if (visitorToken) {
        body.append('visitor_token', visitorToken);
    }
    window.fetch(config.landUrl, {
        method: 'POST',
        credentials: 'same-origin',
        cache: 'no-store',
        keepalive: true,
        body: body
    }).then(function (response) {
        return response.ok;
    }, function () {
        return false;
    }).then(function (stored) {
        if (!stored) {
            writeCookies();
        }
    });
}());
