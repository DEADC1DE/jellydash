'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const script = fs.readFileSync(path.resolve(__dirname, '..', '..', 'public', 'sw.js'), 'utf8');

function windowClient(url) {
    return {
        url,
        focused: 0,
        navigated: [],
        async focus() { this.focused++; },
        async navigate(target) { this.navigated.push(target); },
    };
}

async function click(openClients, target) {
    const listeners = {};
    const opened = [];
    const scope = {
        URL,
        self: {
            location: { origin: 'https://jellydash.example.test' },
            addEventListener(name, listener) { listeners[name] = listener; },
            clients: {
                async matchAll() { return openClients; },
                async openWindow(url) {
                    opened.push(url);
                    // Installed apps may reuse their current window.
                    if (openClients.length) openClients[0].url = url;
                },
            },
        },
    };
    vm.runInNewContext(script, scope, { filename: 'public/sw.js' });
    let pending;
    let closed = false;
    listeners.notificationclick({
        notification: { data: { url: target }, close() { closed = true; } },
        waitUntil(promise) { pending = promise; },
    });
    await pending;
    assert.equal(closed, true);
    return opened;
}

(async () => {
    const settings = windowClient('https://jellydash.example.test/settings');
    const opened = await click([settings], '/now-playing');
    assert.equal(settings.focused, 1);
    assert.deepEqual(settings.navigated, []);
    assert.equal(settings.url, 'https://jellydash.example.test/settings');
    assert.deepEqual(opened, []);

    const destination = windowClient('https://jellydash.example.test/now-playing');
    const openedAgain = await click([settings, destination], '/now-playing');
    assert.equal(destination.focused, 1);
    assert.deepEqual(openedAgain, []);

    const external = await click([], 'https://unrelated.example.test/path');
    assert.deepEqual(external, ['https://jellydash.example.test/now-playing']);
    const newDestination = await click([], '/jellyseerr');
    assert.deepEqual(newDestination, ['https://jellydash.example.test/jellyseerr']);
    process.stdout.write('Notification click tests passed.\n');
})().catch((error) => {
    console.error(error);
    process.exitCode = 1;
});
