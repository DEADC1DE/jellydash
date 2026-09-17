'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const script = fs.readFileSync(path.resolve(__dirname, '..', '..', 'public', 'assets', 'js', 'libraries.js'), 'utf8');

function element(initialClasses = []) {
    const classes = new Set(initialClasses);
    return {
        innerHTML: '', textContent: '',
        classList: {
            contains(name) { return classes.has(name); },
            remove(name) { classes.delete(name); },
            toggle(name, on) { on ? classes.add(name) : classes.delete(name); },
        },
    };
}

function harness(fetchResult) {
    const cards = Array.from({ length: 4 }, () => {
        const card = element(['is-loading']);
        const value = element();
        const note = element();
        card.querySelector = (selector) => selector === 'strong' ? value : (selector === 'small' ? note : null);
        return { card, value, note };
    });
    const summary = element(['is-loading']);
    summary.querySelectorAll = () => cards.map(({ card }) => card);
    const grid = element(['is-loading']);
    const status = element();
    const nodes = {
        '[data-libraries-root]': element(),
        '[data-library-summary]': summary,
        '[data-library-grid]': grid,
        '[data-libraries-status]': status,
    };
    vm.runInNewContext(script, {
        document: { querySelector: (selector) => nodes[selector] || null },
        window: { setTimeout: () => 1, clearTimeout() {} },
        fetch: fetchResult,
        AbortController,
        String,
        Array,
        Error,
    }, { filename: 'public/assets/js/libraries.js' });

    return { summary, grid, status, cards };
}

async function settle() {
    for (let index = 0; index < 6; ++index) await new Promise((resolve) => setImmediate(resolve));
}

async function testFailedRequestSettlesBothSections() {
    for (const fetchResult of [
        async () => ({ ok: false, status: 502 }),
        async () => { throw new Error('Connection lost'); },
    ]) {
        const page = harness(fetchResult);
        await settle();
        assert.equal(page.summary.classList.contains('is-loading'), false);
        assert.equal(page.grid.classList.contains('is-loading'), false);
        assert.equal(page.status.classList.contains('is-error'), true);
        assert.match(page.grid.innerHTML, /Could not load Jellyfin libraries/);
        for (const { card, value, note } of page.cards) {
            assert.equal(card.classList.contains('is-loading'), false);
            assert.equal(value.textContent, 'N/A');
            assert.equal(note.textContent, 'Could not load');
        }
    }
}

async function testEmptyResultDoesNotNamePersonalLibraries() {
    const page = harness(async () => ({
        ok: true,
        json: async () => ({ summary: [], libraries: [], refreshedLabel: 'Live from Jellyfin' }),
    }));
    await settle();
    assert.equal(page.grid.classList.contains('is-loading'), false);
    assert.match(page.grid.innerHTML, /Jellyfin did not return any media libraries/);
    assert.doesNotMatch(page.grid.innerHTML, /Stand-Up Comedy|PPV & Events/);
}

(async () => {
    await testFailedRequestSettlesBothSections();
    await testEmptyResultDoesNotNamePersonalLibraries();
    process.stdout.write('Libraries state tests passed.\n');
})().catch((error) => {
    console.error(error);
    process.exitCode = 1;
});
