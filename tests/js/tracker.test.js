'use strict';

/*
 * What the tracker, the landing capture and the recommendations script send and write once the
 * visitor has consented, and what the landing capture does without consent.
 */
const assert = require('node:assert/strict');
const {test} = require('node:test');
const {definition, page, run, settle} = require('./harness');

const UUID_V4 = /^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/;
const REC_ID = '3fa85f64-5717-4562-b3fc-2c963f66afa6';
const IDENTITY_FIELDS = ['customer_email', 'email', 'external_id', 'customer_external_id', 'customer_id', 'smaily_visitor_token', 'visitor_token'];

function consenting(pageContext, options) {
    return page(Object.assign({definition: definition({consentConfirmed: true, page: pageContext || {type: null}})}, options || {}));
}

async function eventsOf(target) {
    target.load();
    target.fireWindow('pagehide');
    await settle();

    return target.sentEvents();
}

test('a product page sends one product view with the store\'s own ids', async () => {
    const target = run(consenting({type: 'product', productId: 12, productAttributeId: 34}));
    const events = await eventsOf(target);

    assert.equal(events.length, 1);
    assert.deepEqual(Object.keys(events[0]), ['event_id', 'session_id', 'event_type', 'product_id', 'product_attribute_id']);
    assert.match(events[0].event_id, UUID_V4);
    assert.match(events[0].session_id, UUID_V4);
    assert.equal(events[0].session_id, target.jar.smaily_anon_sid);
    assert.equal(events[0].event_type, 'product_view');
    assert.equal(events[0].product_id, '12');
    assert.equal(events[0].product_attribute_id, '34');
    assert.equal(target.beacons[0].url, 'https://shop.example.test/module/smailyforprestashop/relay');
    assert.equal(target.beacons[0].blob.type, 'application/json');
});

test('the session cookie lives 30 days on the shop\'s path with SameSite=Lax', async () => {
    const target = run(consenting({type: 'product', productId: 12}));
    await eventsOf(target);

    const cookie = target.written[0];
    assert.equal(cookie.name, 'smaily_anon_sid');
    assert.match(cookie.value, UUID_V4);
    assert.equal(cookie.attributes.path, '/');
    assert.equal(cookie.attributes.samesite, 'Lax');
    assert.ok('secure' in cookie.attributes, 'Secure on an https page');
    const days = (new Date(cookie.attributes.expires).getTime() - Date.now()) / 86400000;
    assert.ok(days > 29.9 && days < 30.1, 'about 30 days: ' + days);
});

test('a session cookie that the browser holds is kept', async () => {
    const target = run(consenting({type: 'category', categoryPath: 'clothes/men'}, {cookies: {smaily_anon_sid: '0f8fad5b-d9cb-469f-a165-70867728950e'}}));
    const events = await eventsOf(target);

    assert.deepEqual(target.written, []);
    assert.equal(events[0].session_id, '0f8fad5b-d9cb-469f-a165-70867728950e');
    assert.equal(events[0].event_type, 'category_view');
    assert.equal(events[0].category_path, 'clothes/men');
});

test('each page type sends its own event, and any other page none', async () => {
    const cases = [
        [{type: 'search', searchQuery: 'mug'}, 'search'],
        [{type: 'checkout'}, 'checkout_start'],
        [{type: 'success'}, 'checkout_complete']
    ];
    for (const [context, type] of cases) {
        const events = await eventsOf(run(consenting(context)));
        assert.deepEqual(events.map((event) => event.event_type), [type]);
    }
    assert.equal((await eventsOf(run(consenting({type: 'search', searchQuery: 'mug'}))))[0].search_query, 'mug');
    assert.deepEqual(await eventsOf(run(consenting({type: null}))), []);
    assert.deepEqual(await eventsOf(run(consenting({type: 'search'}))), [], 'a search page without words');
    assert.deepEqual(await eventsOf(run(consenting({type: 'cms'}))), []);
});

test('the shop\'s cart event sends a cart add or a cart remove with the product', async () => {
    const target = run(consenting());
    target.load();
    target.fireShop('updateCart', {reason: {linkAction: 'add-to-cart', idProduct: 12, idProductAttribute: 34}});
    target.fireShop('updateCart', {reason: {linkAction: 'delete-from-cart', idProduct: '7', idProductAttribute: '0'}});
    target.fireShop('updateCart', {reason: {linkAction: 'refresh'}});
    target.fireShop('updateCart', {});
    target.fireWindow('pagehide');
    await settle();
    const events = await target.sentEvents();

    assert.deepEqual(events.map((event) => event.event_type), ['cart_add', 'cart_remove']);
    assert.equal(events[0].product_id, '12');
    assert.equal(events[0].product_attribute_id, '34');
    assert.equal(events[1].product_id, '7');
    assert.ok(!('product_attribute_id' in events[1]) || events[1].product_attribute_id === '0');
});

test('no event names the shopper, although the browser holds every attribution cookie', async () => {
    const target = run(consenting({type: 'product', productId: 12}, {cookies: {smaily_rec_uid: 'vt_8f3k2a9xq4', smaily_rec_id: REC_ID, smaily_rec_ctx: 'welcome'}}));
    target.load();
    target.fireShop('updateCart', {reason: {linkAction: 'add-to-cart', idProduct: 12}});
    target.fireWindow('pagehide');
    await settle();

    for (const event of await target.sentEvents()) {
        for (const field of IDENTITY_FIELDS.concat(['smaily_rec_id', 'smaily_ctx', 'sku'])) {
            assert.ok(!(field in event), field);
        }
    }
});

test('events wait for the batch window and go in one request', async () => {
    const target = run(consenting({type: 'product', productId: 12}));
    target.load();
    target.fireShop('updateCart', {reason: {linkAction: 'add-to-cart', idProduct: 12}});
    assert.deepEqual(target.beacons, [], 'nothing is sent before the window ends');

    target.runTimers();
    await settle();

    assert.equal(target.beacons.length, 1);
    assert.equal((await target.sentEvents()).length, 2);
});

test('a browser without sendBeacon posts the batch with fetch', async () => {
    const target = run(consenting({type: 'product', productId: 12}, {beacon: false}));
    const events = await eventsOf(target);

    assert.equal(events.length, 1);
    assert.equal(target.requests[0].init.method, 'POST');
    assert.equal(target.requests[0].init.headers['Content-Type'], 'application/json');
});

test('a page without PrestaShop\'s events still tracks its page', async () => {
    assert.equal((await eventsOf(run(consenting({type: 'product', productId: 12}, {prestashop: false})))).length, 1);
});

test('a landing posts the link\'s values to the store, which sets the cookies', async () => {
    const target = run(page({search: '?utm_source=smaily&smaily_rec=' + REC_ID + '&smaily_ctx=cart_abandoned&smaily_vt=vt_8f3k2a9xq4'}));
    await settle();

    assert.equal(target.requests.length, 1);
    assert.equal(target.requests[0].url, 'https://shop.example.test/module/smailyforprestashop/land');
    assert.equal(target.requests[0].init.method, 'POST');
    assert.equal(target.requests[0].body, 'rec_id=' + REC_ID + '&context=cart_abandoned&visitor_token=vt_8f3k2a9xq4');
    assert.deepEqual(target.written, [], 'the server set the cookies; the script wrote none');
});

test('the landing capture is not gated on consent, and writes no session cookie', async () => {
    const target = page({definition: definition({consentConfirmed: false}), search: '?smaily_rec=' + REC_ID, landStatus: 'fail'});
    target.window.smailyConnect = {consentOverride: () => false};
    run(target);
    target.load();
    await settle();

    assert.deepEqual(target.written.map((cookie) => cookie.name), ['smaily_rec_id', 'smaily_rec_ctx']);
    assert.ok(!('smaily_anon_sid' in target.jar));
    assert.deepEqual(target.beacons, []);
});

test('when the store does not answer the script writes the three cookies itself', async () => {
    for (const landStatus of ['fail', 500, 429]) {
        const target = run(page({search: '?smaily_rec=' + REC_ID + '&smaily_ctx=welcome&smaily_vt=vt_8f3k2a9xq4', landStatus}));
        await settle();

        assert.deepEqual(target.jar, {smaily_rec_id: REC_ID, smaily_rec_ctx: 'welcome', smaily_rec_uid: 'vt_8f3k2a9xq4'}, String(landStatus));
        const days = target.written.map((cookie) => Math.round((new Date(cookie.attributes.expires).getTime() - Date.now()) / 86400000));
        assert.deepEqual(days, [30, 30, 365]);
    }
});

test('a landing without a context clears the context cookie of an earlier landing', async () => {
    const target = run(page({search: '?smaily_rec=' + REC_ID, landStatus: 'fail', cookies: {smaily_rec_ctx: 'storefront'}}));
    await settle();

    assert.deepEqual(target.jar, {smaily_rec_id: REC_ID});
    assert.equal(target.requests[0].body, 'rec_id=' + REC_ID);
});

test('a malformed id, a store-created token and a context alone are not taken', async () => {
    for (const search of ['?smaily_rec=rec_abc123', '?smaily_vt=vs_0123456789ABCDEFGHIJKL', '?smaily_ctx=welcome', '?utm_source=smaily&utm_content=' + REC_ID, '']) {
        const target = run(page({search, landStatus: 'fail'}));
        await settle();

        assert.deepEqual(target.requests, [], search);
        assert.deepEqual(target.written, [], search);
    }
});

test('a visitor token alone is posted and leaves the recommendation cookies as they are', async () => {
    const target = run(page({search: '?smaily_vt=vt_8f3k2a9xq4', landStatus: 'fail', cookies: {smaily_rec_id: REC_ID, smaily_rec_ctx: 'welcome'}}));
    await settle();

    assert.equal(target.requests[0].body, 'visitor_token=vt_8f3k2a9xq4');
    assert.deepEqual(target.jar, {smaily_rec_id: REC_ID, smaily_rec_ctx: 'welcome', smaily_rec_uid: 'vt_8f3k2a9xq4'});
});

test('the recommendations are asked once after load and fill every container', async () => {
    const target = run(consenting(null, {containers: 2, html: '<section>products</section>'}));
    await settle();
    assert.deepEqual(target.requests, [], 'nothing before the page has loaded');

    target.load();
    target.fireDocument('smaily:consent-changed');
    await settle();

    assert.equal(target.requests.length, 1);
    assert.equal(target.requests[0].url, 'https://shop.example.test/module/smailyforprestashop/recommendations');
    assert.equal(target.requests[0].init.credentials, 'same-origin');
    assert.equal(target.requests[0].body, '', 'the request says nothing about the shopper');
    assert.deepEqual(target.containers.map((container) => container.innerHTML), ['<section>products</section>', '<section>products</section>']);
});

test('an empty answer leaves the containers empty, and a page without a container asks nothing', async () => {
    const empty = run(consenting(null, {containers: 1, html: '  \n'}));
    empty.load();
    await settle();
    assert.equal(empty.containers[0].innerHTML, '');

    const without = run(consenting(null, {containers: 0, html: '<section>products</section>'}));
    without.load();
    await settle();
    assert.deepEqual(without.requests, []);
});
