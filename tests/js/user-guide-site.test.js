'use strict';

/*
 * The merchant guide, docs/site/index.html: one self-contained bilingual page, published at
 * https://smaily.com/connect-presta/. Every unit is a data-lang="en" element followed by its
 * data-lang="et" twin. This check fails on a page that is not well formed, a broken in-page link,
 * a duplicate id, a section the module's admin links to (Admin\UserGuide::SECTION_*) that is
 * missing, anything loaded from elsewhere, a missing twin, or twins whose structure drifted.
 *
 * Node's built-in modules only: the page is parsed by the small parser below, which takes the
 * page's own strict form (every element closed explicitly).
 *
 * Run: node --test tests/js/*.test.js
 */
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const {test} = require('node:test');

const ROOT = path.join(__dirname, '..', '..');
const PAGE = path.join(ROOT, 'docs', 'site', 'index.html');
const USER_GUIDE_CLASS = path.join(ROOT, 'src', 'src', 'Admin', 'UserGuide.php');

/** The top-level sections, in the order of the Magento guide (Background tasks in the slot of its Store types). */
const SECTIONS = ['overview', 'getting-started', 'settings', 'email-fields', 'importing', 'background', 'errors', 'faq', 'privacy', 'appendix'];

/** Counted inside each English unit and its Estonian twin. */
const PARITY = ['table', 'tr', 'td', 'th', 'ul', 'ol', 'li', 'h3', 'pre', 'a', '.note'];

const VOID = new Set(['area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input', 'link', 'meta', 'source', 'track', 'wbr']);
const RAW = new Set(['script', 'style']);

/** Parses the page into a tree of {tag, attrs, children, parent, text, line}; throws on a tag that is not closed in order. */
function parse(html) {
    const root = {tag: '#root', attrs: {}, children: [], parent: null, text: '', line: 1};
    const token = /<!--[\s\S]*?-->|<![^>]*>|<\/([a-zA-Z][a-zA-Z0-9]*)\s*>|<([a-zA-Z][a-zA-Z0-9]*)((?:\s+[^\s"'>/=]+(?:\s*=\s*(?:"[^"]*"|'[^']*'|[^\s"'>]+))?)*)\s*\/?>|[^<]+|</g;
    const attribute = /([^\s"'>/=]+)(?:\s*=\s*(?:"([^"]*)"|'([^']*)'|([^\s"'>]+)))?/g;
    let current = root;
    let match;
    const newlines = [...html.matchAll(/\n/g)].map((found) => found.index);
    const lineAt = (index) => {
        let low = 0;
        let high = newlines.length;
        while (low < high) {
            const middle = (low + high) >> 1;
            if (newlines[middle] < index) {
                low = middle + 1;
            } else {
                high = middle;
            }
        }

        return low + 1;
    };

    while ((match = token.exec(html)) !== null) {
        const [whole, closing, opening, attributes] = match;
        if (closing) {
            const tag = closing.toLowerCase();
            if (current.tag !== tag) {
                throw new Error(`line ${lineAt(match.index)}: </${tag}> closes <${current.tag}> of line ${current.line}`);
            }
            current = current.parent;
        } else if (opening) {
            const tag = opening.toLowerCase();
            const node = {tag, attrs: {}, children: [], parent: current, text: '', line: lineAt(match.index)};
            let pair;
            attribute.lastIndex = 0;
            while ((pair = attribute.exec(attributes || '')) !== null) {
                node.attrs[pair[1].toLowerCase()] = pair[2] ?? pair[3] ?? pair[4] ?? '';
            }
            current.children.push(node);
            if (RAW.has(tag)) {
                const end = html.toLowerCase().indexOf(`</${tag}>`, token.lastIndex);
                if (end === -1) {
                    throw new Error(`line ${node.line}: <${tag}> is not closed`);
                }
                node.text = html.slice(token.lastIndex, end);
                token.lastIndex = end + tag.length + 3;
            } else if (!VOID.has(tag) && !whole.endsWith('/>')) {
                current = node;
            }
        } else if (!whole.startsWith('<!')) {
            current.children.push({tag: '#text', text: whole, parent: current});
        }
    }
    if (current !== root) {
        throw new Error(`<${current.tag}> of line ${current.line} is not closed`);
    }

    return root;
}

function elements(node, out = []) {
    for (const child of node.children || []) {
        if (child.tag !== '#text') {
            out.push(child);
            elements(child, out);
        }
    }

    return out;
}

function textOf(node) {
    return node.tag === '#text' ? node.text : (node.children || []).map(textOf).join('');
}

function nextElement(node) {
    const siblings = node.parent.children.filter((child) => child.tag !== '#text');

    return siblings[siblings.indexOf(node) + 1] || null;
}

function previousElement(node) {
    const siblings = node.parent.children.filter((child) => child.tag !== '#text');

    return siblings[siblings.indexOf(node) - 1] || null;
}

function matches(node, selector) {
    return selector.startsWith('.')
        ? (node.attrs.class || '').split(/\s+/).includes(selector.slice(1))
        : node.tag === selector;
}

/** Where a node is, for a message: the id of its nearest ancestor that has one. */
function where(node) {
    for (let at = node; at; at = at.parent) {
        if (at.attrs && at.attrs.id) {
            return '#' + at.attrs.id;
        }
    }

    return `line ${node.line}`;
}

/** The section ids the module's admin links to: the SECTION_* constants of Admin\UserGuide. */
function moduleAnchors() {
    const source = fs.readFileSync(USER_GUIDE_CLASS, 'utf8');

    return [...source.matchAll(/const SECTION_[A-Z_]+ = '([^']+)'/g)].map((found) => found[1]);
}

/** Every problem of the page, by check; an empty list passes. */
function check(html, anchors) {
    const problems = {structure: [], ids: [], links: [], anchors: [], external: [], twins: [], drift: [], empty: [], sections: []};
    let root;
    try {
        root = parse(html);
    } catch (error) {
        problems.structure.push(error.message);

        return problems;
    }
    const all = elements(root);
    const body = all.filter((node) => node.tag === 'body');
    const inBody = body.length === 1 ? elements(body[0]) : [];

    const seen = new Set();
    for (const node of all) {
        const id = node.attrs.id;
        if (id !== undefined) {
            if (seen.has(id)) {
                problems.ids.push(id);
            }
            seen.add(id);
        }
    }

    const links = all.filter((node) => node.tag === 'a' && (node.attrs.href || '').startsWith('#'));
    for (const link of links) {
        if (!seen.has(link.attrs.href.slice(1))) {
            problems.links.push(`${link.attrs.href} (line ${link.line})`);
        }
    }
    if (links.length < 100) {
        problems.links.push(`only ${links.length} in-page links`);
    }

    for (const anchor of anchors) {
        if (!seen.has(anchor)) {
            problems.anchors.push(anchor);
        }
    }

    for (const node of all) {
        const loads = (node.tag === 'script' && node.attrs.src !== undefined)
            || (node.tag === 'link' && node.attrs.href !== undefined)
            || ['img', 'iframe', 'object', 'embed', 'video', 'audio', 'source'].includes(node.tag)
            || (node.tag === 'style' && /url\(|@import/i.test(node.text));
        if (loads) {
            problems.external.push(`<${node.tag}> on line ${node.line}`);
        }
    }

    const english = inBody.filter((node) => node.attrs['data-lang'] === 'en');
    const estonian = inBody.filter((node) => node.attrs['data-lang'] === 'et');
    if (english.length < 150 || english.length !== estonian.length) {
        problems.twins.push(`${english.length} English and ${estonian.length} Estonian units`);
    }
    for (const en of english) {
        const et = nextElement(en);
        if (!et || et.attrs['data-lang'] !== 'et' || et.tag !== en.tag) {
            problems.twins.push(`${where(en)} line ${en.line}: no Estonian twin right after the English <${en.tag}>`);
            continue;
        }
        if (textOf(en).trim() === '' || textOf(et).trim() === '') {
            problems.empty.push(`${where(en)} line ${en.line}`);
        }
        for (const selector of PARITY) {
            const a = elements(en).filter((node) => matches(node, selector)).length;
            const b = elements(et).filter((node) => matches(node, selector)).length;
            if (a !== b) {
                problems.drift.push(`${where(en)} line ${en.line}: ${selector} ${a}/${b}`);
            }
        }
    }
    for (const et of estonian) {
        const en = previousElement(et);
        if (!en || en.attrs['data-lang'] !== 'en') {
            problems.twins.push(`${where(et)} line ${et.line}: an Estonian <${et.tag}> without its English twin`);
        }
    }

    const sections = inBody.filter((node) => node.tag === 'section').map((node) => node.attrs.id);
    if (sections.join(' ') !== SECTIONS.join(' ')) {
        problems.sections.push(`sections ${sections.join(', ')}`);
    }
    const groups = inBody
        .filter((node) => node.tag === 'a' && (node.attrs.class || '') === 'gl' && node.attrs['data-lang'] === 'en')
        .map((node) => node.attrs.href.slice(1));
    if (groups.join(' ') !== SECTIONS.join(' ')) {
        problems.sections.push(`menu groups ${groups.join(', ')}`);
    }

    return problems;
}

const page = fs.readFileSync(PAGE, 'utf8');
const anchors = moduleAnchors();
const found = check(page, anchors);

/** The page with one change; fails the test when the change does not apply. */
function changed(search, replacement) {
    assert.ok(page.includes(search), `the page holds ${search}`);

    return page.replace(search, replacement);
}

test('the page is well formed', () => {
    assert.deepEqual(found.structure, []);
});

test('every id is unique', () => {
    assert.deepEqual(found.ids, []);
});

test('every in-page link has its target', () => {
    assert.deepEqual(found.links, []);
});

test('the sections the module links to are on the page', () => {
    assert.ok(anchors.length > 0, 'Admin\\UserGuide names its sections');
    assert.deepEqual(found.anchors, []);
});

test('the page loads nothing from elsewhere', () => {
    assert.deepEqual(found.external, []);
});

test('every English unit has its Estonian twin right after it, with the same structure', () => {
    assert.deepEqual(found.twins, []);
    assert.deepEqual(found.drift, []);
    assert.deepEqual(found.empty, []);
});

test('the sections and the menu keep the sibling guides\' order', () => {
    assert.deepEqual(found.sections, []);
});

test('the check fails on a removed twin', () => {
    const broken = changed('<span data-lang="et">Ülevaade</span>', '');
    assert.notDeepEqual(check(broken, anchors).twins, []);
});

test('the check fails on a broken in-page link', () => {
    const broken = changed('<a href="#set-rss" data-lang="et">', '<a href="#set-rss-feed" data-lang="et">');
    assert.deepEqual(check(broken, anchors).links, ['#set-rss-feed (line ' + (broken.slice(0, broken.indexOf('#set-rss-feed')).split('\n').length) + ')']);
});

test('the check fails on twins whose structure drifted', () => {
    const broken = changed('<li>Lisa kood Smaily malli — vt <a href="#ef-discount">Sooduskoodid</a>.</li>', '');
    assert.notDeepEqual(check(broken, anchors).drift, []);
});

test('the check fails on a missing section the module links to', () => {
    assert.deepEqual(check(page, ['no-such-section']).anchors, ['no-such-section']);
});

test('the check fails on a page that loads a script from elsewhere', () => {
    const broken = changed('</head>', '<script src="https://cdn.example.test/x.js"></script></head>');
    assert.notDeepEqual(check(broken, anchors).external, []);
});
