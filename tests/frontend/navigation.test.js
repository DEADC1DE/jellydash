'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const source = fs.readFileSync(path.resolve(__dirname, '../../public/assets/js/nav.js'), 'utf8');
const releaseSource = fs.readFileSync(path.resolve(__dirname, '../../public/assets/js/release-highlights.js'), 'utf8');

function fixture(desktop = false, original = {}) {
    const listeners = new Map();
    const document = {
        activeElement: null,
        inertViolations: [],
        addEventListener(type, callback) {
            listeners.set(type, [...(listeners.get(type) || []), callback]);
        },
        dispatch(type, event) {
            for (const callback of listeners.get(type) || []) callback(event);
        },
    };

    class Element {
        constructor(tag, attributes = {}) {
            this.tagName = tag.toUpperCase();
            this.attributes = new Map(Object.entries(attributes));
            this.children = [];
            this.parentElement = null;
            this.listeners = new Map();
            this.hidden = false;
            this.disabled = false;
            this.open = false;
            this.visible = true;
            const classes = new Set();
            this.classList = { add: (value) => classes.add(value), remove: (value) => classes.delete(value), contains: (value) => classes.has(value) };
        }
        get inert() { return this.attributes.has('inert'); }
        set inert(value) {
            if (value && this.contains(document.activeElement)) document.inertViolations.push(this);
            value ? this.setAttribute('inert', '') : this.removeAttribute('inert');
        }
        get tabIndex() {
            if (this.attributes.has('tabindex')) return Number(this.getAttribute('tabindex'));
            return ['BUTTON', 'INPUT'].includes(this.tagName) || (this.tagName === 'A' && this.attributes.has('href')) ? 0 : -1;
        }
        setAttribute(name, value) { this.attributes.set(name, String(value)); }
        getAttribute(name) { return this.attributes.get(name) ?? null; }
        removeAttribute(name) { this.attributes.delete(name); }
        appendChild(child) { child.parentElement = this; this.children.push(child); return child; }
        replaceChildren() { this.children.forEach((child) => { child.parentElement = null; }); this.children = []; }
        prepend(child) { child.parentElement = this; this.children.unshift(child); }
        contains(other) { return this === other || this.children.some((child) => child.contains(other)); }
        descendants() { return this.children.flatMap((child) => [child, ...child.descendants()]); }
        closest(selector) {
            let element = this;
            while (element) {
                if (selector.includes('[hidden]') && element.hidden) return element;
                if (selector.includes('[inert]') && element.inert) return element;
                if (selector.includes('[aria-hidden="true"]') && element.getAttribute('aria-hidden') === 'true') return element;
                if (selector === 'a[href]' && element.tagName === 'A' && element.getAttribute('href')) return element;
                if (selector === 'dialog[open]' && element.tagName === 'DIALOG' && element.open) return element;
                element = element.parentElement;
            }
            return null;
        }
        getClientRects() {
            let element = this;
            while (element) {
                if (element.hidden || !element.visible) return [];
                element = element.parentElement;
            }
            return [{}];
        }
        querySelectorAll() { return this.descendants().filter((element) => element.tabIndex >= 0); }
        addEventListener(type, callback) { this.listeners.set(type, [...(this.listeners.get(type) || []), callback]); }
        click(target = this) {
            for (let element = this; element; element = element.parentElement) {
                for (const callback of element.listeners.get('click') || []) callback({ target });
            }
        }
        focus() {
            if (this.closest('[hidden], [inert]') || !this.getClientRects().length || this.disabled) return;
            document.activeElement = this;
            document.dispatch('focusin', { target: this });
        }
    }

    const body = new Element('body');
    document.body = body;
    document.activeElement = body;
    const shell = body.appendChild(new Element('div'));
    const topbar = shell.appendChild(new Element('header'));
    const toggle = topbar.appendChild(new Element('button', { 'aria-expanded': 'false', 'aria-label': 'Open menu' }));
    const mobileBrand = topbar.appendChild(new Element('a', { href: '/now-playing' }));
    const bell = topbar.appendChild(new Element('button'));
    const backdrop = shell.appendChild(new Element('div')); backdrop.hidden = true;
    const sidebar = shell.appendChild(new Element('aside', original));
    const brand = sidebar.appendChild(new Element('a', { href: '/now-playing' }));
    const navLink = sidebar.appendChild(new Element('a', { href: '/history' }));
    const hiddenButton = sidebar.appendChild(new Element('button')); hiddenButton.hidden = true;
    const disabledButton = sidebar.appendChild(new Element('button')); disabledButton.disabled = true;
    const lastButton = sidebar.appendChild(new Element('button'));
    const main = shell.appendChild(new Element('main'));
    const contentLink = main.appendChild(new Element('a', { href: '#content' }));
    const dialog = body.appendChild(new Element('dialog')); dialog.hidden = true;
    const dialogButton = dialog.appendChild(new Element('button'));
    let dialogOpener;
    dialog.showModal = function () {
        dialogOpener = document.activeElement;
        dialog.hidden = false;
        dialog.open = true;
        dialogButton.focus();
    };
    dialog.close = function () {
        dialog.open = false;
        dialog.hidden = true;
        document.activeElement = body;
        if (dialogOpener) dialogOpener.focus();
        document.dispatch('close', { target: dialog });
    };
    document.querySelector = (selector) => ({ '.app-shell': shell, '[data-nav-toggle]': toggle, '[data-nav-backdrop]': backdrop }[selector] || null);
    document.getElementById = () => sidebar;
    document.createElement = (tag) => new Element(tag);
    const query = { matches: desktop, addEventListener(type, callback) { this.onchange = callback; } };
    vm.runInNewContext(source, { document, window: { matchMedia: () => query } });
    function resize(matches) { query.matches = matches; query.onchange({ matches }); }
    function key(key, shiftKey = false) {
        const event = { key, shiftKey, target: document.activeElement, defaultPrevented: false, preventDefault() { this.defaultPrevented = true; } };
        document.dispatch('keydown', event);
        return event;
    }
    function tab(shift = false) {
        const event = key('Tab', shift);
        if (!event.defaultPrevented) {
            const options = body.descendants().filter((element) => element.tabIndex >= 0 && !element.disabled && !element.closest('[hidden], [inert]') && element.getClientRects().length);
            const current = options.indexOf(document.activeElement);
            options[(current + (shift ? -1 : 1) + options.length) % options.length].focus();
        }
        return event;
    }
    async function loadReleaseHighlights(autoShow = false) {
        const nodes = new Map();
        for (const selector of ['[data-release-version]', '[data-release-title]', '[data-release-summary]', '[data-release-highlights]', '[data-release-links]']) {
            nodes.set(selector, dialog.appendChild(new Element('span')));
        }
        dialog.querySelector = (selector) => nodes.get(selector) || null;
        dialog.querySelectorAll = (selector) => selector === '[data-release-close]' ? [dialogButton] : [];
        const root = new Element('div'); root.dataset = { appVersion: '1.14.0' };
        const label = lastButton.appendChild(new Element('span'));
        lastButton.hidden = true;
        const originalQuerySelector = document.querySelector;
        document.querySelector = (selector) => ({
            '[data-update-status]': root,
            '[data-release-changes]': lastButton,
            '[data-release-changes-label]': label,
            '[data-release-dialog]': dialog,
        }[selector] || originalQuerySelector(selector));
        vm.runInNewContext(releaseSource, {
            document, Promise, URL,
            window: { localStorage: { getItem: () => null, setItem() {} } },
            fetch: async () => ({ ok: true, json: async () => ({
                version: '1.14.0', auto_show: autoShow,
                title: 'Release changes', summary: 'Synthetic release fixture.',
                highlights: ['A fixture change.'],
                links: [{ label: 'Release', url: 'https://github.com/themartz90/jellydash/releases/tag/v1.14.0' }],
            }) }),
        }, { filename: 'public/assets/js/release-highlights.js' });
        for (let index = 0; index < 6; index++) await new Promise((resolve) => setImmediate(resolve));
    }
    return { document, shell, topbar, toggle, mobileBrand, bell, backdrop, sidebar, brand, navLink, lastButton, main, contentLink, dialog, dialogButton, resize, key, tab, loadReleaseHighlights };
}

let cases = 0;
function test(name, check) { check(); cases++; console.log('PASS ' + name); }

test('closed phone sidebar is excluded from keyboard navigation', () => {
    const f = fixture();
    f.bell.focus();
    f.tab();
    assert.equal(f.document.activeElement, f.contentLink, 'Tab after the topbar bell must reach the page content.');
    assert.equal(f.sidebar.inert, true);
    assert.equal(f.sidebar.getAttribute('aria-hidden'), 'true');
    assert.equal(f.toggle.getAttribute('aria-expanded'), 'false');
});

test('opening moves focus into the drawer before disabling the background', () => {
    const f = fixture(); f.toggle.focus(); f.toggle.click();
    assert.equal(f.document.activeElement, f.brand);
    assert.equal(f.sidebar.inert, false);
    assert.equal(f.sidebar.getAttribute('aria-modal'), 'true');
    assert.equal(f.sidebar.getAttribute('role'), 'dialog');
    assert.equal(f.topbar.inert, true); assert.equal(f.main.inert, true);
    assert.equal(f.backdrop.hidden, false);
    assert.equal(f.document.inertViolations.length, 0);
});

test('Tab and ShiftTab wrap visible enabled drawer controls', () => {
    const f = fixture(); f.toggle.click();
    f.tab(true); assert.equal(f.document.activeElement, f.lastButton);
    f.tab(); assert.equal(f.document.activeElement, f.brand);
    f.tab(); assert.equal(f.document.activeElement, f.navLink);
    f.lastButton.focus(); f.tab(true); assert.equal(f.document.activeElement, f.navLink);
    f.contentLink.focus(); assert.equal(f.document.activeElement, f.navLink, 'Inert page controls cannot steal focus.');
});

test('Escape, backdrop and destination restore focus and state', () => {
    for (const dismiss of [(f) => f.key('Escape'), (f) => f.backdrop.click(), (f) => f.sidebar.click(f.navLink)]) {
        const f = fixture(); f.toggle.focus(); f.toggle.click(); dismiss(f);
        assert.equal(f.document.activeElement, f.toggle);
        assert.equal(f.sidebar.inert, true); assert.equal(f.main.inert, false); assert.equal(f.topbar.inert, false);
        assert.equal(f.toggle.getAttribute('aria-expanded'), 'false'); assert.equal(f.backdrop.hidden, true);
        assert.equal(f.sidebar.getAttribute('aria-modal'), null); assert.equal(f.sidebar.getAttribute('role'), null);
        assert.equal(f.document.inertViolations.length, 0);
    }
});

test('desktop navigation keeps native keyboard order and existing attributes', () => {
    const f = fixture(true, { role: 'complementary', 'aria-label': 'Server navigation', 'aria-modal': 'false', 'aria-hidden': 'false' });
    assert.equal(f.sidebar.inert, false);
    f.brand.focus(); f.tab(); assert.equal(f.document.activeElement, f.navLink);
    f.sidebar.click(f.navLink); assert.equal(f.document.activeElement, f.navLink);
    assert.equal(f.sidebar.getAttribute('role'), 'complementary');
    assert.equal(f.sidebar.getAttribute('aria-label'), 'Server navigation');
    assert.equal(f.sidebar.getAttribute('aria-modal'), 'false');
    assert.equal(f.sidebar.getAttribute('aria-hidden'), 'false');
});

test('breakpoint changes release the background and avoid hiding active focus', () => {
    const f = fixture(true); f.brand.focus(); f.resize(false);
    assert.equal(f.document.activeElement, f.toggle); assert.equal(f.sidebar.inert, true);
    f.toggle.click(); f.navLink.focus(); f.resize(true);
    assert.equal(f.document.activeElement, f.navLink); assert.equal(f.sidebar.inert, false);
    assert.equal(f.main.inert, false);
    f.resize(false); assert.equal(f.document.activeElement, f.toggle);
    f.toggle.click(); f.resize(true);
    assert.equal(f.document.activeElement, f.brand, 'A desktop resize must preserve focus on the visible brand link.');
    assert.equal(f.document.inertViolations.length, 0);
});

test('restores pre-existing inert and ARIA values', () => {
    const f = fixture(false, { role: 'complementary', 'aria-label': 'Server navigation' });
    f.main.inert = true;
    f.toggle.click(); f.key('Escape');
    assert.equal(f.main.inert, true); assert.equal(f.topbar.inert, false);
    assert.equal(f.sidebar.getAttribute('role'), 'complementary');
    assert.equal(f.sidebar.getAttribute('aria-label'), 'Server navigation');
    f.resize(true);
    assert.equal(f.sidebar.getAttribute('aria-hidden'), null);
    assert.equal(f.sidebar.getAttribute('role'), 'complementary');
    assert.equal(f.sidebar.getAttribute('aria-label'), 'Server navigation');
});

test('native dialogs can receive focus and Escape without the drawer intercepting', () => {
    const f = fixture(); f.toggle.click();
    f.dialog.hidden = false; f.dialog.open = true; f.dialogButton.focus();
    assert.equal(f.document.activeElement, f.dialogButton);
    assert.equal(f.key('Tab').defaultPrevented, false);
    assert.equal(f.key('Escape').defaultPrevented, false);
    assert.equal(f.shell.classList.contains('nav-open'), true);
    f.dialog.open = false; f.dialog.hidden = true; f.brand.focus();
    f.key('Escape'); assert.equal(f.document.activeElement, f.toggle);
});

test('an open native dialog keeps focus when the drawer resets at either breakpoint', () => {
    const f = fixture(); f.toggle.click(); f.lastButton.focus(); f.dialog.showModal();
    f.resize(true);
    assert.equal(f.document.activeElement, f.dialogButton);
    assert.equal(f.sidebar.inert, false); assert.equal(f.main.inert, false);
    f.resize(false);
    assert.equal(f.document.activeElement, f.dialogButton);
    assert.equal(f.sidebar.inert, true);
    assert.equal(f.key('Escape').defaultPrevented, false);
    assert.equal(f.shell.classList.contains('nav-open'), false);
});

(async () => {
    const f = fixture();
    await f.loadReleaseHighlights();
    test('the release script opens its native dialog from the drawer and restores the opener', () => {
        assert.equal(f.lastButton.hidden, false);
        f.toggle.focus(); f.toggle.click(); f.lastButton.focus(); f.lastButton.click();
        assert.equal(f.dialog.open, true);
        assert.equal(f.document.activeElement, f.dialogButton);
        assert.equal(f.key('Tab').defaultPrevented, false);
        assert.equal(f.key('Escape').defaultPrevented, false);
        f.dialog.close(); // Model the native Escape default action.
        assert.equal(f.document.activeElement, f.lastButton);
        assert.equal(f.shell.classList.contains('nav-open'), true);
        f.lastButton.click(); f.dialogButton.click();
        assert.equal(f.dialog.open, false);
        assert.equal(f.document.activeElement, f.lastButton);
        f.key('Escape'); assert.equal(f.document.activeElement, f.toggle);
        assert.equal(f.document.inertViolations.length, 0);
    });

    const auto = fixture(); auto.toggle.click();
    await auto.loadReleaseHighlights(true);
    test('an automatically shown release dialog can take focus above an open drawer', () => {
        assert.equal(auto.dialog.open, true);
        assert.equal(auto.document.activeElement, auto.dialogButton);
        assert.equal(auto.key('Escape').defaultPrevented, false);
        auto.dialogButton.click();
        assert.equal(auto.document.activeElement, auto.brand);
        auto.key('Escape'); assert.equal(auto.document.activeElement, auto.toggle);
    });

    const resized = fixture(true);
    await resized.loadReleaseHighlights();
    test('release dialog dismissal after desktop to phone resize focuses the visible menu toggle', () => {
        resized.lastButton.focus(); resized.lastButton.click(); resized.resize(false);
        assert.equal(resized.document.activeElement, resized.dialogButton);
        assert.equal(resized.key('Escape').defaultPrevented, false);
        resized.dialog.close();
        assert.equal(resized.document.activeElement === resized.toggle, true, 'The inert sidebar opener must fall back to the phone menu toggle.');
        assert.equal(resized.sidebar.inert, true);
        assert.equal(resized.shell.classList.contains('nav-open'), false);
    });

    const desktop = fixture();
    await desktop.loadReleaseHighlights();
    test('release dialog dismissal after phone to desktop resize restores its visible sidebar opener', () => {
        desktop.toggle.click(); desktop.lastButton.focus(); desktop.lastButton.click(); desktop.resize(true);
        assert.equal(desktop.document.activeElement, desktop.dialogButton);
        desktop.dialogButton.click();
        assert.equal(desktop.document.activeElement, desktop.lastButton);
        assert.equal(desktop.sidebar.inert, false);
    });

    const content = fixture(true); content.contentLink.focus(); content.dialog.showModal(); content.resize(false);
    test('dialog dismissal after resize preserves a page content opener', () => {
        content.dialog.close();
        assert.equal(content.document.activeElement, content.contentLink);
    });
    console.log('Navigation tests passed: ' + cases + ' cases.');
})().catch((error) => { console.error(error); process.exitCode = 1; });
