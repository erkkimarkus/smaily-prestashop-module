'use strict';

/*
 * The consent rule of consent.js, and what the tracker and the recommendations do with it:
 * without consent no tracking cookie is written and nothing leaves the browser.
 */
const assert = require('node:assert/strict');
const {test} = require('node:test');
const {definition, page, run, settle} = require('./harness');

const PRODUCT_PAGE = {type: 'product', productId: 12, productAttributeId: 34};

function consentOf(setting, override) {
    const target = page({definition: definition({consentConfirmed: setting})});
    if (override !== undefined) {
        target.window.smailyConnect = {consentOverride: override};
    }
    run(target, ['consent.js']);

    return target.window.smailyConnect.consentGiven();
}

test('without an override the merchant\'s setting decides, and it is off unless it is exactly true', () => {
    assert.equal(consentOf(false), false);
    assert.equal(consentOf(true), true);
    assert.equal(consentOf(undefined), false);
    assert.equal(consentOf('1'), false);
    assert.equal(consentOf(1), false);
});

test('the store\'s override decides whatever the setting says', () => {
    assert.equal(consentOf(true, () => false), false, 'false overrides a confirmed setting');
    assert.equal(consentOf(false, () => true), true, 'true enables it without the setting');
});

test('only an override that answers exactly true is consent', () => {
    for (const answer of [undefined, null, 'yes', 1, {}, 'true']) {
        assert.equal(consentOf(true, () => answer), false, String(answer));
    }
    assert.equal(consentOf(true, () => {
        throw new Error('the banner is not ready');
    }), false, 'an override that throws is no consent');
});

test('an override that is no function is ignored', () => {
    assert.equal(consentOf(true, 'yes'), true);
    assert.equal(consentOf(false, true), false);
});

test('the rule is asked each time, so an answer can change on an open page', () => {
    let answer = false;
    const target = page({definition: definition({consentConfirmed: true})});
    target.window.smailyConnect = {consentOverride: () => answer};
    run(target, ['consent.js']);

    assert.equal(target.window.smailyConnect.consentGiven(), false);
    answer = true;
    assert.equal(target.window.smailyConnect.consentGiven(), true);
});

test('a page without the module\'s definition has no consent', () => {
    const target = page({definition: null});
    run(target, ['consent.js']);

    assert.equal(target.window.smailyConnect.consentGiven(), false);
});

test('without consent: no session cookie, no event, no recommendations call', async () => {
    const target = page({definition: definition({consentConfirmed: false, page: PRODUCT_PAGE}), containers: 1});
    run(target);
    target.load();
    target.fireShop('updateCart', {reason: {linkAction: 'add-to-cart', idProduct: 12, idProductAttribute: 34}});
    target.runTimers();
    target.fireWindow('pagehide');
    await settle();

    assert.deepEqual(target.written, [], 'no cookie was written');
    assert.deepEqual(target.jar, {});
    assert.deepEqual(target.beacons, []);
    assert.deepEqual(target.requests, []);
    assert.equal(target.containers[0].innerHTML, '');
});

test('an override that answers false keeps everything off although the setting is confirmed', async () => {
    const target = page({definition: definition({consentConfirmed: true, page: PRODUCT_PAGE}), containers: 1});
    target.window.smailyConnect = {consentOverride: () => false};
    run(target);
    target.load();
    target.fireShop('updateCart', {reason: {linkAction: 'add-to-cart', idProduct: 12}});
    target.runTimers();
    target.fireWindow('pagehide');
    await settle();

    assert.deepEqual(target.written, []);
    assert.deepEqual(target.beacons, []);
    assert.deepEqual(target.requests, []);
});

test('an override that answers true starts both without the setting', async () => {
    const target = page({definition: definition({consentConfirmed: false, page: PRODUCT_PAGE}), containers: 1, html: '<section>products</section>'});
    target.window.smailyConnect = {consentOverride: () => true};
    run(target);
    target.load();
    target.fireWindow('pagehide');
    await settle();

    assert.deepEqual(target.written.map((cookie) => cookie.name), ['smaily_anon_sid']);
    assert.equal((await target.sentEvents()).length, 1);
    assert.equal(target.containers[0].innerHTML, '<section>products</section>');
});

test('consent that arrives later on the page starts the tracker and the recommendations then', async () => {
    let answer = false;
    const target = page({definition: definition({page: PRODUCT_PAGE}), containers: 1, html: '<section>products</section>'});
    target.window.smailyConnect = {consentOverride: () => answer};
    run(target);
    target.load();
    await settle();
    assert.deepEqual(target.written, []);
    assert.deepEqual(target.requests, []);

    answer = true;
    target.fireDocument('smaily:consent-changed');
    target.fireWindow('pagehide');
    await settle();

    assert.deepEqual(target.written.map((cookie) => cookie.name), ['smaily_anon_sid']);
    assert.equal((await target.sentEvents())[0].event_type, 'product_view');
    assert.equal(target.containers[0].innerHTML, '<section>products</section>');
});

test('consent that is gone when a batch is due drops the batch', async () => {
    let answer = true;
    const target = page({definition: definition({page: PRODUCT_PAGE})});
    target.window.smailyConnect = {consentOverride: () => answer};
    run(target);
    target.load();

    answer = false;
    target.runTimers();
    target.fireWindow('pagehide');
    await settle();

    assert.deepEqual(target.beacons, []);
    assert.deepEqual(target.requests, []);
});
