'use strict';

/*
 * Every script of views/js/front/ is one IIFE in strict mode, and the scripts leave no global
 * behind but window.smailyConnect. A classic script's top-level `const`, `let`, `class`,
 * `function` or `var` is global for every script that follows on the page, and breaks the shop's
 * own scripts.
 *
 * Run: node --test tests/js/
 */
const assert = require('node:assert/strict');
const fs = require('node:fs');
const {test} = require('node:test');
const vm = require('node:vm');
const {SCRIPT_DIR, STOREFRONT_SCRIPTS, definition, page, run, source} = require('./harness');

const scripts = fs.readdirSync(SCRIPT_DIR).filter((name) => name.endsWith('.js')).sort();

/** The source without its comments. No script holds a comment marker inside a string or a pattern. */
function code(name) {
    return source(name).replace(/\/\*[\s\S]*?\*\//g, '').replace(/^\s*\/\/.*$/gm, '').trim();
}

/**
 * The index just after the brace that closes the first block of the code: the braces are
 * counted outside string literals. (A pattern literal of the scripts holds only balanced braces;
 * one that did not would fail this check, not pass it.)
 */
function endOfFirstBlock(body) {
    let depth = 0;
    let quote = null;

    for (let i = 0; i < body.length; i++) {
        const char = body[i];
        if (quote) {
            if (char === '\\') {
                i++;
            } else if (char === quote) {
                quote = null;
            }
        } else if (char === '\'' || char === '"') {
            quote = char;
        } else if (char === '{') {
            depth++;
        } else if (char === '}' && --depth === 0) {
            return i + 1;
        }
    }

    return -1;
}

test('the folder holds the four storefront scripts and the checkout capture', () => {
    assert.deepEqual(scripts, ['attribution.js', 'checkout-capture.js', 'consent.js', 'recommendations.js', 'tracker.js']);
    assert.deepEqual(STOREFRONT_SCRIPTS.slice().sort(), scripts.filter((name) => name !== 'checkout-capture.js'));
});

for (const name of scripts) {
    test(name + ' is one IIFE in strict mode', () => {
        const body = code(name);

        assert.match(body, /^\(function \(\) \{\s*'use strict';/, 'the first statement opens the IIFE');
        assert.equal(body.slice(endOfFirstBlock(body)), '());', 'the function that the first statement opens ends the file');
        assert.doesNotThrow(() => new vm.Script(source(name), {filename: name}));
    });

    test(name + ' carries the licence notice', () => {
        assert.match(source(name), /^\/\*\n \* 2024 Smaily\n \*\n \* NOTICE OF LICENSE\n/);
    });
}

test('the storefront scripts add no global but window.smailyConnect', () => {
    const target = page({definition: definition({consentConfirmed: true, page: {type: 'product', productId: 1}}), containers: 1, search: '?smaily_rec=3fa85f64-5717-4562-b3fc-2c963f66afa6'});
    const before = Object.getOwnPropertyNames(target.window);

    run(target);
    target.load();
    target.runTimers();

    const added = Object.getOwnPropertyNames(target.window).filter((name) => !before.includes(name));
    assert.deepEqual(added, ['smailyConnect']);
    assert.deepEqual(Object.keys(target.window.smailyConnect).sort(), ['attribution', 'consentGiven']);
});

test('a store that defined window.smailyConnect before the scripts keeps its object', () => {
    const target = page();
    const store = {consentOverride: () => true, own: 'kept'};
    target.window.smailyConnect = store;

    run(target);

    assert.equal(target.window.smailyConnect, store);
    assert.equal(target.window.smailyConnect.own, 'kept');
    assert.equal(typeof target.window.smailyConnect.consentGiven, 'function');
});

test('the checkout capture adds no global at all', () => {
    const target = page();
    target.window.smailyConnectCapture = {url: 'https://shop.example.test/capture', token: 'token'};
    const before = Object.getOwnPropertyNames(target.window);

    run(target, ['checkout-capture.js']);

    assert.deepEqual(Object.getOwnPropertyNames(target.window).filter((name) => !before.includes(name)), []);
});

test('on a page without the module\'s definition the scripts do nothing', () => {
    const target = page({definition: null, containers: 1, search: '?smaily_rec=3fa85f64-5717-4562-b3fc-2c963f66afa6'});

    run(target);
    target.load();

    assert.deepEqual(target.written, []);
    assert.deepEqual(target.requests, []);
    assert.deepEqual(target.beacons, []);
});
