'use strict';

/*
 * A small stand-in for a browser page, for the tests of the storefront scripts
 * (src/views/js/front/). Node's built-in modules only: no package is installed.
 *
 * page() builds a page: a global object that is its own `window`, a document with a cookie jar,
 * events and containers, and a network that records every request. run() executes the module's
 * scripts in it, in the order PrestaShop loads them.
 */
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const SCRIPT_DIR = path.join(__dirname, '..', '..', 'src', 'views', 'js', 'front');

/** The storefront scripts, in the order Storefront\PageScripts registers them. */
const STOREFRONT_SCRIPTS = ['consent.js', 'attribution.js', 'tracker.js', 'recommendations.js'];

/** What Storefront\PageScripts prints for a connected shop whose engine takes the connection. */
function definition(overrides) {
    return Object.assign({
        consentConfirmed: false,
        landUrl: 'https://shop.example.test/module/smailyforprestashop/land',
        relayUrl: 'https://shop.example.test/module/smailyforprestashop/relay',
        recommendationsUrl: 'https://shop.example.test/module/smailyforprestashop/recommendations',
        cookiePath: '/',
        cookies: {visitor: 'smaily_rec_uid', session: 'smaily_anon_sid', recId: 'smaily_rec_id', context: 'smaily_rec_ctx'},
        days: {visitor: 365, session: 30, recId: 30, context: 30},
        parameters: {visitor: 'smaily_vt', recId: 'smaily_rec', context: 'smaily_ctx'},
        page: {type: null}
    }, overrides || {});
}

function listeners() {
    const registered = {};

    return {
        add(type, listener) {
            (registered[type] = registered[type] || []).push(listener);
        },
        fire(type, event) {
            (registered[type] || []).slice().forEach((listener) => listener(event || {type}));
        }
    };
}

/**
 * @param {object} options
 *   definition   the page's smailyConnectStorefront (default: definition()); null for a page without one
 *   search       the page address's query, e.g. "?smaily_rec=..."
 *   cookies      the cookies the browser holds, by name
 *   containers   how many recommendation containers the page has
 *   readyState   document.readyState when the scripts run (default "loading")
 *   landStatus   the HTTP status of the landing endpoint, or "fail" for a request that fails
 *   html         what the recommendations endpoint answers
 *   beacon       false for a browser without navigator.sendBeacon
 *   prestashop   false for a page without PrestaShop's event emitter
 */
function page(options) {
    const settings = Object.assign({search: '', cookies: {}, containers: 0, readyState: 'loading', landStatus: 204, html: '', beacon: true, prestashop: true}, options || {});
    const jar = Object.assign({}, settings.cookies);
    const written = [];
    const requests = [];
    const beacons = [];
    const timers = [];
    const windowEvents = listeners();
    const documentEvents = listeners();
    const shopEvents = listeners();
    const containers = [];
    for (let i = 0; i < settings.containers; i++) {
        containers.push({innerHTML: ''});
    }

    const document = {
        readyState: settings.readyState,
        get cookie() {
            return Object.keys(jar).map((name) => name + '=' + jar[name]).join('; ');
        },
        set cookie(value) {
            const parts = String(value).split(';').map((part) => part.trim());
            const [name, ...rest] = parts[0].split('=');
            const attributes = {};
            parts.slice(1).forEach((part) => {
                const [key, ...val] = part.split('=');
                attributes[key.toLowerCase()] = val.join('=');
            });
            written.push({name, value: rest.join('='), attributes});
            if (attributes.expires && new Date(attributes.expires).getTime() <= Date.now()) {
                delete jar[name];
            } else {
                jar[name] = rest.join('=');
            }
        },
        addEventListener: documentEvents.add,
        querySelector: (selector) => (selector === '[data-smaily-connect-recs]' && containers.length ? containers[0] : null),
        querySelectorAll: (selector) => (selector === '[data-smaily-connect-recs]' ? containers : [])
    };

    const window = {
        document,
        location: {search: settings.search, protocol: 'https:', pathname: '/'},
        URLSearchParams,
        Blob,
        crypto: globalThis.crypto,
        addEventListener: windowEvents.add,
        setTimeout(callback, delay) {
            timers.push({callback, delay});

            return timers.length;
        },
        clearTimeout(id) {
            if (timers[id - 1]) {
                timers[id - 1].callback = null;
            }
        },
        fetch(url, init) {
            const request = {url, init: init || {}, body: init && init.body ? String(init.body) : ''};
            requests.push(request);
            if (url === settings.definitionLandUrl && settings.landStatus === 'fail') {
                return Promise.reject(new Error('network'));
            }
            const status = url === settings.definitionLandUrl ? settings.landStatus : 200;

            return Promise.resolve({ok: status >= 200 && status < 300, status, text: () => Promise.resolve(settings.html)});
        }
    };
    window.navigator = settings.beacon ? {
        sendBeacon(url, blob) {
            beacons.push({url, blob});

            return true;
        }
    } : {};
    if (settings.prestashop) {
        window.prestashop = {on: shopEvents.add};
    }
    if (settings.definition !== null) {
        window.smailyConnectStorefront = settings.definition || definition();
        settings.definitionLandUrl = window.smailyConnectStorefront.landUrl;
    }
    window.window = window;
    vm.createContext(window);

    return {
        window,
        document,
        jar,
        written,
        requests,
        beacons,
        containers,
        /** Runs the timers that are waiting, e.g. the tracker's batch window. */
        runTimers() {
            timers.splice(0).forEach((timer) => timer.callback && timer.callback());
        },
        fireWindow: windowEvents.fire,
        fireDocument: documentEvents.fire,
        fireShop: shopEvents.fire,
        /** The page finished loading: DOMContentLoaded, then load. */
        load() {
            document.readyState = 'complete';
            documentEvents.fire('DOMContentLoaded');
            windowEvents.fire('load');
        },
        /** The events of every batch the tracker sent, in order. */
        async sentEvents() {
            const batches = [];
            for (const beacon of beacons) {
                batches.push(JSON.parse(await beacon.blob.text()).events);
            }
            requests.filter((request) => request.url === window.smailyConnectStorefront.relayUrl)
                .forEach((request) => batches.push(JSON.parse(request.body).events));

            return batches.flat();
        }
    };
}

function source(name) {
    return fs.readFileSync(path.join(SCRIPT_DIR, name), 'utf8');
}

/** Executes scripts of the module in a page, as classic scripts, in the given order. */
function run(target, names) {
    (names || STOREFRONT_SCRIPTS).forEach((name) => vm.runInContext(source(name), target.window, {filename: name}));

    return target;
}

/** Lets the promises that are settled run their callbacks. */
function settle() {
    return new Promise((resolve) => setImmediate(resolve));
}

module.exports = {SCRIPT_DIR, STOREFRONT_SCRIPTS, definition, page, run, settle, source};
