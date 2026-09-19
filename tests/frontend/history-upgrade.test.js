'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const script = fs.readFileSync(path.resolve(__dirname, '..', '..', 'public', 'assets', 'js', 'history-library-upgrade.js'), 'utf8');
const pending = { required: true, state: 'pending', total: 3, processed: 0, percent: 0, busy: false };
const running = { ...pending, state: 'running', processed: 1, percent: 33 };

function element() {
    const listeners = {};
    return {
        hidden: false, textContent: '', style: {}, attributes: {},
        addEventListener(name, callback) { listeners[name] = callback; },
        setAttribute(name, value) { this.attributes[name] = value; },
        focus() {},
        click() { if (listeners.click) listeners.click(); },
        event(name) { if (listeners[name]) listeners[name]({ preventDefault() {} }); },
    };
}

function deferred() {
    let resolve;
    const promise = new Promise((done) => { resolve = done; });
    return { promise, resolve };
}

function harness(onFetch) {
    const nodes = new Map();
    const child = (selector) => {
        if (!nodes.has(selector)) nodes.set(selector, element());
        return nodes.get(selector);
    };
    const dialog = Object.assign(element(), {
        open: false,
        querySelector: child,
        showModal() { this.open = true; },
        close() { this.open = false; },
    });
    const reopen = element();
    reopen.hidden = true;
    const timers = new Map();
    let nextTimer = 1;
    const requests = [];
    vm.runInNewContext(script, {
        document: {
            querySelector(selector) {
                if (selector === '[data-history-library-upgrade]') return dialog;
                if (selector === '[data-history-library-upgrade-reopen]') return reopen;
                if (selector === 'meta[name="csrf-token"]') return { content: 'csrf' };
                return null;
            },
        },
        window: {
            setTimeout(callback) { const id = nextTimer++; timers.set(id, callback); return id; },
            clearTimeout(id) { timers.delete(id); },
        },
        fetch: (url, options) => {
            requests.push(options.method);
            return onFetch(options.method, requests.length);
        },
        Promise,
        Number,
        Math,
        String,
        Error,
    }, { filename: 'public/assets/js/history-library-upgrade.js' });

    return {
        dialog, reopen, requests,
        get timerCount() { return timers.size; },
        hide() { child('[data-history-library-upgrade-hide]').click(); },
        tick() {
            const callbacks = Array.from(timers.values());
            timers.clear();
            callbacks.forEach((callback) => callback());
        },
    };
}

function response(payload) {
    return { ok: true, json: async () => payload };
}

async function settle() {
    for (let index = 0; index < 6; ++index) await new Promise((resolve) => setImmediate(resolve));
}

async function testHiddenDialogDoesNotContinuePostingAfterPendingResponse() {
    const firstPost = deferred();
    const secondPost = deferred();
    let postCount = 0;
    const ui = harness((method) => method === 'GET'
        ? Promise.resolve(response(pending))
        : (++postCount === 1 ? firstPost.promise : secondPost.promise));

    await settle();
    assert.equal(ui.dialog.open, true);
    ui.tick();
    await settle();
    assert.equal(postCount, 1);
    ui.hide();
    assert.equal(ui.dialog.open, false);
    firstPost.resolve(response(running));
    await settle();
    assert.equal(ui.timerCount, 0);
    ui.tick();
    assert.equal(postCount, 1);

    ui.reopen.click();
    await settle();
    ui.tick();
    await settle();
    assert.equal(postCount, 2);
    secondPost.resolve(response(running));
    await settle();
    assert.equal(ui.timerCount, 1);
    ui.hide();
    assert.equal(ui.timerCount, 0);
}

async function testOverlappingOldResponseCannotStartAnotherLoopAfterReopen() {
    const oldPost = deferred();
    const newPost = deferred();
    let postCount = 0;
    const ui = harness((method) => method === 'GET'
        ? Promise.resolve(response(pending))
        : (++postCount === 1 ? oldPost.promise : newPost.promise));
    await settle();
    ui.tick();
    await settle();
    ui.hide();
    ui.reopen.click();
    await settle();
    ui.tick();
    await settle();
    assert.equal(postCount, 2);

    oldPost.resolve(response(running));
    await settle();
    assert.equal(ui.timerCount, 0);
    newPost.resolve(response(running));
    await settle();
    assert.equal(ui.timerCount, 1);
    ui.hide();
}

(async () => {
    await testHiddenDialogDoesNotContinuePostingAfterPendingResponse();
    await testOverlappingOldResponseCannotStartAnotherLoopAfterReopen();
    process.stdout.write('History upgrade state tests passed.\n');
})().catch((error) => {
    console.error(error);
    process.exitCode = 1;
});
