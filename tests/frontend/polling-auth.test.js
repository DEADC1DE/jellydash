'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

function fixture(script, status = 401) {
    const documentListeners = {};
    const windowListeners = {};
    const intervals = [];
    const timers = new Map();
    const nodes = new Map();
    let calls = 0;
    let timerId = 0;
    function node() {
        return {
            children: [], dataset: {}, style: {}, textContent: '', hidden: false,
            classList: { add() {}, remove() {}, toggle() {}, contains() { return false; } },
            appendChild(child) { this.children.push(child); this.firstChild = this.children[0]; },
            append(...children) { children.forEach((child) => this.appendChild(child)); },
            removeChild(child) { this.children = this.children.filter((item) => item !== child); this.firstChild = this.children[0]; },
            replaceChildren(...children) { this.children = children; this.firstChild = children[0]; },
            setAttribute(name, value) { this[name] = value; }, removeAttribute() {},
            addEventListener() {}, querySelector: lookup, querySelectorAll() { return []; },
            contains() { return false; }, scrollIntoView() {},
        };
    }
    function lookup(selector) {
        if (selector.includes('manager') || selector.includes('editor') || selector.includes('dialog')) return null;
        if (selector === '[data-now-playing-root]' && script !== 'now-playing') return null;
        if (!nodes.has(selector)) nodes.set(selector, node());
        return nodes.get(selector);
    }
    const document = {
        hidden: true, querySelector: lookup, querySelectorAll() { return []; },
        createElement: node, createElementNS: node, createDocumentFragment: node, createTextNode: (text) => ({ textContent: text }),
        addEventListener(type, callback) { documentListeners[type] = callback; },
    };
    const window = {
        location: { hash: '' }, JellydashFrontendTestHooks: {},
        setInterval(callback) { intervals.push(callback); },
        setTimeout(callback) { timers.set(++timerId, callback); return timerId; },
        clearTimeout(id) { timers.delete(id); },
        addEventListener(type, callback) { windowListeners[type] = callback; }, dispatchEvent() {},
    };
    const context = { window, document, navigator: {}, AbortController, Error, Date, Number, JSON, Set, FormData,
        fetch: async () => { calls++; return { ok: false, status }; },
    };
    vm.runInNewContext(fs.readFileSync(path.resolve(__dirname, `../../public/assets/js/${script}.js`), 'utf8'), context);
    return { document, window, nodes, timers, intervals, documentListeners, windowListeners, context, calls: () => calls };
}

const settle = () => new Promise((resolve) => setImmediate(resolve));

(async () => {
    for (const script of ['now-playing', 'system-status', 'nav-count', 'server-stats', 'downloads', 'download-indicator']) {
        for (const status of [401, 403]) {
            const page = fixture(script, status);
            page.document.hidden = false;
            page.documentListeners.visibilitychange();
            await settle();
            assert.equal(page.calls(), 1, `${script}: initial auth rejection`);
            const snapshot = Array.from(page.nodes.values(), (node) => node.children.slice());
            page.intervals.forEach((callback) => callback());
            Array.from(page.timers.values()).forEach((callback) => callback());
            page.documentListeners.visibilitychange();
            page.windowListeners.pagehide();
            page.windowListeners.pageshow();
            await settle();
            assert.equal(page.calls(), 1, `${script}: terminal ${status} must survive timer, visibility and bfcache`);
            assert.deepEqual(Array.from(page.nodes.values(), (node) => node.children.slice()), snapshot, `${script}: stable controls`);
        }
        const retry = fixture(script, 503);
        retry.document.hidden = false;
        retry.documentListeners.visibilitychange();
        await settle();
        retry.windowListeners.pageshow();
        await settle();
        assert.equal(retry.calls(), 2, `${script}: outage remains retryable`);

        for (const event of ['pagehide', 'visibilitychange']) {
            const aborted = fixture(script);
            let rejectFetch;
            aborted.context.fetch = () => new Promise((resolve, reject) => { rejectFetch = reject; });
            aborted.document.hidden = false;
            aborted.documentListeners.visibilitychange();
            const before = Array.from(aborted.nodes.values(), (node) => [node.textContent, node.children.slice()]);
            if (event === 'pagehide') aborted.windowListeners.pagehide();
            else { aborted.document.hidden = true; aborted.documentListeners.visibilitychange(); }
            rejectFetch(Object.assign(new Error('Aborted'), { name: 'AbortError' }));
            await settle();
            assert.deepEqual(Array.from(aborted.nodes.values(), (node) => [node.textContent, node.children.slice()]), before,
                `${script}: intentional ${event} abort must not paint a failure`);
        }
        const resumed = fixture(script);
        const pending = [];
        resumed.context.fetch = () => new Promise((resolve) => pending.push(resolve));
        resumed.document.hidden = false;
        resumed.documentListeners.visibilitychange();
        resumed.windowListeners.pagehide();
        resumed.windowListeners.pageshow();
        assert.equal(pending.length, 1, `${script}: resume cannot overlap the old request`);
        pending[0]({ ok: false, status: 401 });
        await settle();
        assert.equal(pending.length, 2, `${script}: fast resume queues a fresh request after invalidating the old response`);
        pending[1]({ ok: false, status: 403 });
        await settle();
    }

    const saved = fixture('downloads');
    const responses = [];
    saved.context.fetch = () => new Promise((resolve) => { responses.push(resolve); });
    saved.document.hidden = false;
    saved.documentListeners.visibilitychange();
    saved.window.JellydashDownloadsTestHooks.requestFreshOverview();
    saved.window.JellydashDownloadsTestHooks.requestFreshOverview();
    assert.equal(responses.length, 1, 'Post-save refresh must wait for the obsolete request to settle.');
    responses[0]({ ok: false, status: 401 });
    await settle();
    assert.equal(responses.length, 2, 'Multiple saves queue exactly one fresh overview.');
    assert.equal(saved.nodes.get('[data-downloads-root]').children.length, 0, 'An obsolete response cannot paint or expire the session.');
    responses[1]({ ok: false, status: 403 });
    await settle();
    assert.equal(saved.nodes.get('[data-downloads-root]').children[0].children[1].href, '/login');
    console.log('Polling authentication lifecycle tests passed.');
})().catch((error) => { console.error(error); process.exitCode = 1; });
