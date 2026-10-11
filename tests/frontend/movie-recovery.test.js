'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const script = fs.readFileSync(path.resolve(__dirname, '../../public/assets/js/movie-recovery.js'), 'utf8');

function deferred() {
    let resolve;
    let reject;
    const promise = new Promise((done, fail) => { resolve = done; reject = fail; });
    return { promise, resolve, reject };
}

function payload(overrides = {}) {
    return {
        state: 'ready', message: 'Movie metadata checked.', page: 1, pages: 1,
        suggestions: [], suggestionCount: 0, repairs: [], repairCount: 0, dismissed: [], dismissedCount: 0,
        ...overrides,
    };
}

const suggestion = {
    sourceId: 'old-Hidden123', sourceTitle: 'Original movie', plays: 2,
    candidates: [{ id: 'new-Hidden456', title: 'Current movie <script>', year: 2026 }],
};

function response(body, status = 200) {
    return { ok: status >= 200 && status < 300, status, json: async () => body };
}

function harness(onFetch) {
    const document = { hidden: false, activeElement: null };
    class Element {
        constructor(tag = 'div') {
            this.tagName = tag.toUpperCase();
            this.parentNode = null;
            this.children = [];
            this.dataset = {};
            this.attributes = {};
            this.listeners = {};
            this.disabled = false;
            this.hidden = false;
            this.ownText = '';
        }
        set textContent(value) { this.ownText = String(value); this.replaceChildren(); }
        get textContent() { return this.ownText + this.children.map((child) => child.textContent).join(''); }
        get isConnected() { return this === panel || Boolean(this.parentNode && this.parentNode.isConnected); }
        appendChild(child) { child.parentNode = this; this.children.push(child); return child; }
        replaceChildren(...children) {
            this.children.forEach((child) => { child.parentNode = null; });
            this.children = [];
            children.forEach((child) => this.appendChild(child));
        }
        contains(child) { return child === this || this.children.some((item) => item.contains(child)); }
        closest(selector) { return selector.toUpperCase() === this.tagName ? this : this.parentNode && this.parentNode.closest(selector); }
        setAttribute(name, value) { this.attributes[name] = String(value); }
        getAttribute(name) { return this.attributes[name] ?? null; }
        removeAttribute(name) { delete this.attributes[name]; }
        addEventListener(name, callback) { this.listeners[name] = callback; }
        focus() { document.activeElement = this; }
        querySelectorAll(selector) {
            const all = this.children.flatMap((child) => [child, ...child.querySelectorAll('*')]);
            return selector === '*' ? all : all.filter((child) => selector === 'button' && child.tagName === 'BUTTON');
        }
    }
    const panel = new Element('section');
    const notice = new Element('a');
    const title = panel.appendChild(new Element('h2'));
    const nodes = new Map();
    const buttons = new Set(['prev', 'next', 'refresh']);
    for (const name of ['status', 'suggestions', 'repairs', 'repairs-wrap', 'repairs-label', 'dismissed', 'dismissed-wrap', 'dismissed-label', 'pager', 'page', 'prev', 'next', 'refresh', 'error']) {
        nodes.set(name, panel.appendChild(new Element(buttons.has(name) ? 'button' : 'div')));
    }
    panel.querySelector = (selector) => nodes.get(selector.replace('[data-movie-recovery-', '').replace(']', '')) || null;
    document.querySelector = (selector) => {
        if (selector === '[data-movie-recovery]') return panel;
        if (selector === '[data-movie-recovery-notice]') return notice;
        if (selector === 'meta[name="csrf-token"]') return { content: 'csrf-fixture' };
        return null;
    };
    document.getElementById = (id) => id === 'movie-recovery-title' ? title : null;
    document.createElement = (tag) => new Element(tag);
    const timers = new Map();
    const requests = [];
    const windowListeners = {};
    let timerId = 0;
    const window = {
        setTimeout(callback, delay) { const id = ++timerId; timers.set(id, { callback, delay }); return id; },
        clearTimeout(id) { timers.delete(id); },
        addEventListener(name, callback) { windowListeners[name] = callback; },
    };
    vm.runInNewContext(script, {
        document, window, Promise, AbortController, Error,
        fetch(url, options) {
            const request = { url, options, body: options.body ? JSON.parse(options.body) : null };
            requests.push(request);
            return onFetch(request, requests.length);
        },
    }, { filename: 'public/assets/js/movie-recovery.js' });
    return {
        panel, notice, title, document, requests, nodes,
        action(name) { return panel.querySelectorAll('button').find((button) => button.dataset.recoveryAction === name); },
        click(button) { assert.ok(button, 'The action button must exist.'); if (!button.disabled) panel.listeners.click({ target: button }); },
        tick(delay) {
            const entry = [...timers].find(([, timer]) => delay === undefined ? timer.delay !== 25000 : timer.delay === delay);
            if (!entry) return false;
            timers.delete(entry[0]);
            entry[1].callback();
            return true;
        },
        get scheduled() { return [...timers.values()].filter((timer) => timer.delay !== 25000); },
        pagehide() { windowListeners.pagehide(); },
        pageshow(persisted) { windowListeners.pageshow({ persisted }); },
    };
}

async function settle() {
    for (let index = 0; index < 8; index++) await new Promise((resolve) => setImmediate(resolve));
}

async function initialCheckAndConfirmationUseIdsWithoutDisplayingThem() {
    const confirmation = deferred();
    const ui = harness((request) => request.body.action === 'confirm'
        ? confirmation.promise
        : Promise.resolve(response(payload({ suggestions: [suggestion], suggestionCount: 1 }))));
    assert.equal(ui.requests.length, 0);
    assert.ok(ui.tick(1500));
    await settle();
    assert.equal(ui.requests[0].options.method, 'POST');
    assert.deepEqual(ui.requests[0].body, { action: 'check' });
    assert.equal(ui.requests[0].options.headers['X-CSRF-Token'], 'csrf-fixture');
    const button = ui.action('confirm');
    assert.equal(button.getAttribute('aria-label'), 'Reconnect Original movie to Current movie <script> (2026)');
    assert.ok(ui.panel.textContent.includes('Current movie <script>'));
    assert.ok(ui.panel.textContent.includes('2026'));
    assert.ok(!ui.panel.textContent.includes(suggestion.sourceId));
    assert.ok(!ui.panel.textContent.includes(suggestion.candidates[0].id));
    const poster = ui.nodes.get('suggestions').querySelectorAll('*').find((node) => node.tagName === 'IMG');
    assert.equal(poster.src, '/api/image.php?item=new-Hidden456&type=Primary&maxWidth=120');
    assert.equal(poster.alt, '');
    ui.click(button);
    await settle();
    assert.equal(button.disabled, true);
    assert.deepEqual(ui.requests[1].body, { action: 'confirm', sourceId: suggestion.sourceId, targetId: suggestion.candidates[0].id });
    confirmation.resolve(response(payload({ repairs: [{ sourceId: suggestion.sourceId, targetTitle: 'Current movie', automatic: false }], repairCount: 1 })));
    await settle();
    assert.equal(ui.document.activeElement, ui.title);
    assert.ok(ui.action('undo'));
    assert.equal(ui.nodes.get('repairs-wrap').hidden, false);
    assert.equal(ui.nodes.get('suggestions').children.length, 0);
}

async function separateUndoAndResumeSendTheSelectedSource() {
    const ui = harness(() => Promise.resolve(response(payload({
        suggestions: [suggestion], suggestionCount: 1,
        repairs: [{ sourceId: 'repaired-source', targetTitle: 'Repaired movie', automatic: true }], repairCount: 1,
        dismissed: [{ sourceId: 'dismissed-source', sourceTitle: 'Separate movie' }], dismissedCount: 1,
    }))));
    ui.tick(1500);
    await settle();
    for (const [action, id] of [['dismiss', suggestion.sourceId], ['undo', 'repaired-source'], ['resume', 'dismissed-source']]) {
        ui.click(ui.action(action));
        await settle();
        assert.deepEqual(ui.requests.at(-1).body, { action, sourceId: id });
    }
}

async function pagingUsesReadRequestsAndRestoresControlsAfterFailure() {
    let fail = false;
    const ui = harness((request) => {
        if (fail) return Promise.reject(new Error('Paging failed.'));
        return Promise.resolve(response(payload({ page: Number(new URL(request.url, 'http://fixture').searchParams.get('page')), pages: 3 })));
    });
    ui.tick(1500);
    await settle();
    ui.click(ui.nodes.get('next'));
    await settle();
    assert.equal(ui.requests.at(-1).options.method || 'GET', 'GET');
    assert.equal(ui.requests.at(-1).body, null);
    assert.equal(ui.requests.at(-1).url, '/api/movie-recovery.php?page=2');
    assert.equal(ui.nodes.get('page').textContent, 'Page 2 of 3');
    assert.equal(ui.nodes.get('prev').disabled, false);
    fail = true;
    ui.click(ui.nodes.get('next'));
    await settle();
    assert.equal(ui.nodes.get('error').hidden, false);
    assert.equal(ui.nodes.get('error').textContent, 'Paging failed.');
    assert.equal(ui.nodes.get('next').disabled, false, 'Failed paging must restore the available next-page control.');
    assert.equal(ui.nodes.get('page').textContent, 'Page 2 of 3');
    fail = false;
    ui.click(ui.nodes.get('next'));
    await settle();
    assert.equal(ui.requests.at(-1).url, '/api/movie-recovery.php?page=3', 'Retry must request the page after the one still shown.');
    assert.equal(ui.nodes.get('page').textContent, 'Page 3 of 3');
    assert.equal(ui.nodes.get('next').disabled, true);
}

async function stalePollingCannotOverwriteAConfirmation() {
    const oldPoll = deferred();
    const confirmed = deferred();
    const ui = harness((request, number) => {
        if (number === 1) return Promise.resolve(response(payload({ suggestions: [suggestion], suggestionCount: 1 })));
        return request.body.action === 'check' ? oldPoll.promise : confirmed.promise;
    });
    ui.tick(1500);
    await settle();
    const button = ui.action('confirm');
    ui.tick(60000);
    await settle();
    ui.click(button);
    await settle();
    assert.equal(ui.requests.length, 2, 'The confirmation waits behind the active check.');
    oldPoll.resolve(response(payload({ message: 'Stale poll result', suggestions: [], suggestionCount: 0 })));
    await settle();
    assert.equal(ui.nodes.get('status').textContent, 'Movie metadata checked.');
    assert.equal(ui.requests.length, 3);
    confirmed.resolve(response(payload({ message: 'Confirmed result', repairs: [{ sourceId: suggestion.sourceId, targetTitle: 'Current movie' }], repairCount: 1 })));
    await settle();
    assert.equal(ui.nodes.get('status').textContent, 'Confirmed result');
    assert.ok(ui.action('undo'));
    assert.equal(ui.scheduled.length, 1, 'Only one polling timer remains after confirmation.');
}

async function mutationFailureRestoresControlAndAuthFailureStopsRetries() {
    let failure = false;
    const ui = harness(() => failure
        ? Promise.resolve(response({ error: 'Another check is running.' }, 409))
        : Promise.resolve(response(payload({ suggestions: [suggestion], suggestionCount: 1 }))));
    ui.tick(1500);
    await settle();
    const button = ui.action('confirm');
    failure = true;
    ui.click(button);
    await settle();
    assert.equal(button.disabled, false);
    assert.equal(button.getAttribute('aria-label'), 'Reconnect Original movie to Current movie <script> (2026)');
    assert.equal(ui.nodes.get('error').textContent, 'Another check is running.');
    assert.equal(ui.scheduled.length, 1);

    for (const status of [401, 403, 419]) {
        const denied = harness(() => Promise.resolve(response({ error: 'Sign in or refresh.' }, status)));
        denied.tick(1500);
        await settle();
        assert.equal(denied.scheduled.length, 0, 'Authentication and CSRF failures must stop retries.');
        assert.equal(denied.nodes.get('error').textContent, 'Sign in or refresh.');
        assert.equal(denied.requests.length, 1);
    }
    ui.pagehide();
    assert.equal(ui.scheduled.length, 0);
}

async function persistedPageRestoreResumesChecksWithoutRevivingAuthenticationFailures() {
    const ui = harness(() => Promise.resolve(response(payload())));
    ui.tick(1500);
    await settle();
    assert.equal(ui.requests.length, 1);
    ui.pagehide();
    assert.equal(ui.scheduled.length, 0);
    ui.pageshow(false);
    assert.equal(ui.scheduled.length, 0, 'An ordinary page show must not resume a suspended instance.');
    ui.pageshow(true);
    assert.equal(ui.scheduled.length, 1);
    assert.ok(ui.tick(1500));
    await settle();
    assert.equal(ui.requests.length, 2, 'A restored page must check movie replacements again.');
    assert.deepEqual(ui.requests.at(-1).body, { action: 'check' });
    assert.equal(ui.scheduled.length, 1, 'Restoring a page must retain only one polling loop.');

    for (const status of [401, 403, 419]) {
        const denied = harness(() => Promise.resolve(response({ error: 'Sign in or refresh.' }, status)));
        denied.tick(1500);
        await settle();
        denied.pagehide();
        denied.pageshow(true);
        assert.equal(denied.scheduled.length, 0, 'Page restoration must preserve terminal authentication and CSRF failures.');
        assert.equal(denied.tick(1500), false);
        await settle();
        assert.equal(denied.requests.length, 1);
    }
}

async function headingFocusAllowsFreshResultsWhileFocusedActionsKeepTheirControls() {
    const ui = harness((request, number) => Promise.resolve(response(number === 1
        ? payload({ suggestions: [suggestion], suggestionCount: 1 })
        : payload({ repairs: [{ sourceId: suggestion.sourceId, targetTitle: number === 2 ? 'Confirmed title' : 'Updated title' }], repairCount: 1 }))));
    ui.tick(1500);
    await settle();
    ui.click(ui.action('confirm'));
    await settle();
    assert.equal(ui.document.activeElement, ui.title);
    assert.ok(ui.nodes.get('repairs').textContent.includes('Confirmed title'));
    assert.ok(ui.tick(4000));
    await settle();
    assert.deepEqual(ui.requests.at(-1).body, { action: 'check' });
    assert.ok(ui.nodes.get('repairs').textContent.includes('Updated title'), 'Static heading focus must allow fresh recovery results.');
    assert.ok(!ui.nodes.get('repairs').textContent.includes('Confirmed title'));
    assert.equal(ui.document.activeElement, ui.title);

    const focused = harness((request, number) => Promise.resolve(response(payload({
        suggestions: [{ ...suggestion, sourceTitle: number === 1 ? 'Original movie' : 'Updated source title' }], suggestionCount: 1,
    }))));
    focused.tick(1500);
    await settle();
    const button = focused.action('confirm');
    button.focus();
    focused.tick(60000);
    await settle();
    assert.equal(focused.action('confirm'), button, 'Polling must preserve the action currently holding keyboard focus.');
    assert.equal(focused.document.activeElement, button);
    assert.equal(button.isConnected, true);
}

(async () => {
    await initialCheckAndConfirmationUseIdsWithoutDisplayingThem();
    await separateUndoAndResumeSendTheSelectedSource();
    await stalePollingCannotOverwriteAConfirmation();
    await mutationFailureRestoresControlAndAuthFailureStopsRetries();
    await pagingUsesReadRequestsAndRestoresControlsAfterFailure();
    await persistedPageRestoreResumesChecksWithoutRevivingAuthenticationFailures();
    await headingFocusAllowsFreshResultsWhileFocusedActionsKeepTheirControls();
    console.log('Movie recovery frontend tests passed.');
})().catch((error) => { console.error(error); process.exitCode = 1; });
