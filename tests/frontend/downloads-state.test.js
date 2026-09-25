'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const rootDir = path.resolve(__dirname, '..', '..');

function run(file, globals) {
    vm.runInNewContext(fs.readFileSync(path.join(rootDir, file), 'utf8'), globals, { filename: file });
}

function testDownloadsFormattingAndStableAnnouncements() {
    const hooks = {};
    run('public/assets/js/downloads.js', {
        window: { JellydashDownloadsTestHooks: hooks },
        document: { querySelector: () => null },
        Number, Set, Error, FormData, AbortController,
    });

    assert.equal(hooks.formatBytes(0), '0 B');
    assert.equal(hooks.formatBytes(18_400_000), '18.4 MB');
    assert.equal(hooks.formatBytes(null), 'Unknown size');
    assert.equal(hooks.formatEta(137), '2m 17s');
    assert.equal(hooks.formatRelativeTime(970, 1000, false), 'Just now');
    assert.equal(hooks.formatRelativeTime(100, 1000, true), 'Recorded 15m ago');
    assert.deepEqual(Array.from(hooks.parseValues(' sonarr, radarr, sonarr, , ')), ['sonarr', 'radarr']);
    assert.equal(hooks.categoryText({ provider: 'transmission', category: 'sonarr', tags: ['sonarr', 'radarr'] }),
        'sonarr, radarr', 'all Transmission labels should be visible');
    assert.equal(hooks.categoryText({ provider: 'transmission', category: '', tags: [] }), 'Unlabelled');
    assert.equal(hooks.categoryText({ provider: 'sabnzbd', category: '' }), 'Uncategorized');
    const warning = hooks.historyStatus({ provider: 'nzbget', status: 'warning' });
    assert.equal(warning.label, '⚠ Needs attention');
    assert.match(warning.className, /downloads-warning/);
    assert.doesNotMatch(warning.className, /downloads-failed/);
    assert.equal(warning.help, 'Check NZBGet for details.');
    assert.equal(hooks.historyStatus({ provider: 'nzbget', status: 'failed' }).label, '× Failed');
    assert.equal(hooks.historyStatus({ provider: 'nzbget', status: 'completed' }).label, '✓ Completed');

    const first = hooks.overviewSignature({
        active_count: 2,
        workers_enabled: true,
        connections: [{ id: 'one', status: 'connected', speed: 100 }],
    });
    const speedOnly = hooks.overviewSignature({
        active_count: 2,
        workers_enabled: true,
        connections: [{ id: 'one', status: 'connected', speed: 900 }],
    });
    const offline = hooks.overviewSignature({
        active_count: 2,
        workers_enabled: true,
        connections: [{ id: 'one', status: 'offline', speed: null }],
    });
    assert.equal(first, speedOnly, 'speed ticks must not trigger live-region announcements');
    assert.notEqual(first, offline, 'connection state changes should be announced');

    const stale = hooks.staleOverview({
        speed: 9_000_000,
        active_count: 3,
        connections: [{ id: 'one', enabled: true, status: 'connected', speed: 9_000_000 }],
        items: [{ state: 'downloading', speed: 9_000_000, eta: 20, stale: false }],
    });
    assert.equal(stale.speed, null);
    assert.equal(stale.active_count, 0);
    assert.equal(stale.connections[0].status, 'offline');
    assert.equal(stale.connections[0].speed, null);
    assert.equal(stale.items[0].stale, true);
    assert.equal(stale.items[0].speed, null);
    assert.equal(stale.items[0].eta, null);
    assert.equal(hooks.emptyOverviewMode([{ enabled: true, status: 'offline' }]), 'unavailable');
    assert.equal(hooks.emptyOverviewMode([{ enabled: true, status: 'waiting' }]), 'first-sync');
    assert.equal(hooks.emptyOverviewMode([{ enabled: true, status: 'connected' }]), 'idle');

    const quiet = { monitoring_enabled: true, speed: 0, partial: false,
        connections: [{ enabled: true, status: 'connected', partial: false }], items: [] };
    assert.equal(hooks.transferSpeedState(quiet).label, 'Idle');
    assert.equal(hooks.transferSpeedState({ ...quiet, speed: null }).label, 'Speed unavailable', 'missing speed is not idle');
    assert.equal(hooks.transferSpeedState({ ...quiet, speed: null, connections: [{ status: 'offline' }] }).label, 'Speed unavailable');
    assert.equal(hooks.transferSpeedState({ ...quiet, speed: null, connections: [{ status: 'waiting' }] }).label, 'Waiting for update');
    assert.equal(hooks.transferSpeedState({ ...quiet, speed: null, connections: [{ status: 'stale' }] }).label, 'Updates delayed');
    assert.equal(hooks.transferSpeedState({ ...quiet, monitoring_enabled: false, speed: 100 }).label, 'Monitoring off');
    assert.equal(hooks.transferSpeedState({ ...quiet, items: [{ state: 'processing' }] }).label, 'Processing');
    assert.equal(hooks.transferSpeedState({ ...quiet, items: [{ state: 'error' }] }).label, 'Needs attention');
    assert.equal(hooks.transferSpeedState({ ...quiet, speed: 100 }), null, 'known traffic keeps the numeric readout');
    assert.equal(hooks.transferSpeedState({ ...quiet, items: [{ state: 'downloading' }] }), null, 'a downloading item at zero speed is not idle');
    assert.equal(hooks.transferSpeedState({ ...quiet, partial: true }), null, 'an incomplete queue cannot prove idle');
}

function testIndicatorUsesDownloadingCountAndClientWideSpeed() {
    const hooks = {};
    const listeners = {};
    function element(tag) {
        return { tag, children: [], attributes: {}, textContent: '',
            append(...nodes) { this.children.push(...nodes); },
            replaceChildren(...nodes) { this.children = nodes; },
            setAttribute(name, value) { this.attributes[name] = value; },
        };
    }
    const indicator = element('a');
    const document = {
        hidden: true,
        querySelector: (selector) => selector === '[data-download-indicator]' ? indicator : null,
        addEventListener: (type, callback) => { listeners[type] = callback; },
        createElement: element,
        createElementNS: (_, tag) => element(tag),
        createDocumentFragment: () => element('fragment'),
    };
    const window = {
        JellydashDownloadIndicatorTestHooks: hooks,
        addEventListener: (type, callback) => { listeners[type] = callback; },
        setTimeout: () => 1,
        clearTimeout() {},
    };
    run('public/assets/js/download-indicator.js', {
        window, document, Number, Set, AbortController,
        fetch: async () => { throw new Error('hidden page should not fetch'); },
    });

    const connected = [{ provider: 'qbittorrent', status: 'connected', enabled: true }];
    assert.equal(
        hooks.summaryText({ active_count: 2, speed: 18_400_000, workers_enabled: true }, connected),
        '2 downloading · 18.4 MB/s',
    );
    assert.equal(
        hooks.summaryText({ active_count: 0, speed: 18_400_000, workers_enabled: true }, connected),
        'Downloads idle',
        'non-downloading work must not be presented as active downloads',
    );
    assert.equal(
        hooks.summaryText({ active_count: 0, speed: null, workers_enabled: true }, [{ status: 'offline' }]),
        'Downloads unavailable',
    );
    assert.equal(
        hooks.summaryText({ active_count: 0, speed: null, workers_enabled: true }, [{ status: 'disabled', enabled: false }]),
        'Download monitoring off',
    );
    assert.equal(
        hooks.summaryText({ active_count: 2, speed: 18_400_000, workers_enabled: false, monitoring_enabled: true }, connected),
        '2 downloading · 18.4 MB/s',
        'a successful external collection stays live with the built-in worker disabled',
    );
    assert.equal(
        hooks.summaryText({ active_count: 0, workers_enabled: false, monitoring_enabled: true }, [{ status: 'offline', enabled: true }]),
        'Downloads unavailable',
        'a failed first external attempt should show an outage',
    );
    assert.equal(
        hooks.summaryText({ active_count: 2, speed: 18_400_000, workers_enabled: false, monitoring_enabled: false }, connected),
        'Download monitoring off',
        'disabled monitoring takes precedence over cached activity',
    );
    assert.equal(hooks.unavailableText(990, 1000), 'Downloads unavailable · last response 10s ago');
    assert.equal(hooks.unavailableText(900, 1000), 'Downloads unavailable · last response 1m ago');
    assert.doesNotMatch(hooks.unavailableText(990, 1000), /downloading|MB\/s/);

    const find = (node, className) => node.className === className
        ? node : node.children.map((child) => find(child, className)).find(Boolean);
    const data = { configured: true, connections: connected, monitoring_enabled: true, active_count: 2, speed: 18_400_000 };
    hooks.render(data);
    assert.equal(find(indicator, 'download-indicator-title').textContent, 'Downloads');
    assert.equal(find(indicator, 'download-indicator-detail').textContent, '2 downloading');
    assert.equal(find(indicator, 'download-indicator-speed').children[0].textContent, '18.4');
    assert.equal(find(indicator, 'download-indicator-speed').children[1].textContent, 'MB/s');
    assert.match(indicator.attributes['aria-label'], /18.4 MB\/s.*Open Downloads/);
    hooks.render({ ...data, speed: null });
    assert.equal(find(indicator, 'download-indicator-speed'), undefined, 'unknown filtered speed must not become zero');
    hooks.renderUnavailable();
    assert.equal(find(indicator, 'download-indicator-speed'), undefined, 'an API outage removes the previous speed');
    assert.equal(find(indicator, 'download-indicator-detail').textContent, 'Status unavailable');
    assert.equal(indicator.attributes['data-active'], 'false');
    hooks.render({ ...data, monitoring_enabled: false });
    assert.equal(find(indicator, 'download-indicator-speed'), undefined);
    assert.equal(find(indicator, 'download-indicator-detail').textContent, 'Monitoring off');
    hooks.render({ ...data, active_count: 0, connections: [{ status: 'stale', enabled: true }, { status: 'disabled', enabled: false }] });
    assert.equal(find(indicator, 'download-indicator-detail').textContent, 'Updates delayed', 'disabled clients do not hide stale monitoring');
    hooks.render({ configured: false, connections: [] });
    assert.equal(indicator.hidden, true);
    assert.equal(indicator.children.length, 0);
}

function testClientFiltersAndSpeedFallbacks() {
    const hooks = {};
    run('public/assets/js/downloads.js', {
        window: { JellydashDownloadsTestHooks: hooks },
        document: { querySelector: () => null },
        Number, Set, Error, FormData, AbortController,
    });
    const data = {
        connections: [
            { id: 'first', provider: 'sabnzbd', enabled: true, status: 'connected', speed: 24_200_000,
                history: { status: 'error', error: 'history_update_failed' } },
            { id: 'second', provider: 'sabnzbd', enabled: true, status: 'connected', speed: 5_000_000,
                history: { status: 'connected', error: null } },
            { id: 'offline', provider: 'qbittorrent', enabled: true, status: 'offline', speed: 99_000_000 },
        ],
        items: [
            { connection_id: 'first', provider: 'sabnzbd', state: 'downloading', stale: false, speed: null },
            { connection_id: 'second', provider: 'sabnzbd', state: 'downloading', stale: false, speed: null },
            { connection_id: 'offline', provider: 'qbittorrent', state: 'downloading', stale: true, speed: null },
        ],
        history: [{ connection_id: 'second', title: 'Newest combined completion' }],
        history_by_connection: { first: [{ connection_id: 'first', title: 'Older first-client completion' }] },
    };
    const selected = hooks.selectedOverview(data, 'first');
    assert.equal(selected.connections.length, 1, 'same-provider clients must filter by connection ID');
    assert.equal(selected.all_connections.length, 3, 'other client filters must remain available');
    assert.equal(selected.items.length, 1);
    assert.equal(selected.history[0].title, 'Older first-client completion');
    assert.equal(selected.connections[0].history.status, 'error', 'history health follows the selected client');
    assert.equal(selected.speed, 24_200_000);
    assert.equal(selected.active_count, 1);
    assert.equal(data.connections.length, 3, 'filtering must not modify the cached overview');
    assert.equal(hooks.selectedOverview(data, '').speed, null, 'an unavailable client must not make a partial sum look complete');
    assert.equal(hooks.selectedOverview(data, 'offline').speed, null);
    assert.equal(hooks.selectedOverview(data, 'offline').active_count, 0);
    assert.equal(hooks.selectedOverview(data, 'removed-client').items.length, 3, 'removed filters return to all clients');

    const fallback = hooks.cardSpeed(data.items[0], data.connections);
    assert.equal(fallback.value, 24_200_000);
    assert.equal(fallback.clientWide, true, 'client speed must be labelled as client-wide');
    assert.equal(hooks.cardSpeed({ ...data.items[0], speed: 12 }, data.connections).value, 12);
    assert.equal(hooks.cardSpeed({ ...data.items[0], stale: true }, data.connections).value, null);
    assert.equal(hooks.cardSpeed({ ...data.items[0], state: 'processing' }, data.connections).value, null);
    assert.equal(hooks.cardSpeed(data.items[2], data.connections).value, null);
    assert.equal(hooks.cardSpeed({ ...data.items[0], provider: 'transmission' }, data.connections).value, null,
        'torrent cards must not present an aggregate connection speed as one torrent speed');
    assert.equal(hooks.cardSpeed({ ...data.items[0], provider: 'deluge' }, data.connections).value, null);
    assert.equal(hooks.cardSpeed({ ...data.items[0], provider: 'nzbget' }, data.connections).clientWide, true);

    const failed = hooks.selectedOverview(hooks.staleOverview(data), 'first');
    assert.equal(failed.speed, null, 'changing filters after an API failure must keep telemetry unavailable');
    assert.equal(failed.active_count, 0);
    assert.equal(failed.history.length, 1, 'cached completion history survives outages');
    assert.equal(failed.connections[0].history.error, 'history_update_failed', 'a page outage retains the saved history error');
    const stopped = hooks.selectedOverview({ ...data, workers_enabled: false }, 'first');
    assert.equal(stopped.speed, null);
    assert.equal(stopped.active_count, 0);
    assert.equal(hooks.cardSpeed(stopped.items[0], stopped.connections).value, null, 'disabled monitoring cannot show a live per-card speed');
    assert.equal(stopped.items[0].eta, null);
    const external = hooks.selectedOverview({ ...data, workers_enabled: false, monitoring_enabled: true }, 'first');
    assert.equal(external.speed, 24_200_000, 'external collection preserves current speed');
    assert.equal(external.active_count, 1);
    assert.equal(external.items[0].stale, false);
    const neverStarted = hooks.selectedOverview({ ...data, workers_enabled: true, monitoring_enabled: false }, 'first');
    assert.equal(neverStarted.speed, null, 'the explicit monitoring state takes precedence');
    assert.equal(neverStarted.active_count, 0);
    assert.equal(neverStarted.items[0].stale, true);

    const filtered = {
        workers_enabled: true,
        connections: [
            { id: 'qb', provider: 'qbittorrent', filter_mode: 'selected', enabled: true, status: 'connected', speed: 0, speed_complete: true, client_speed: 500, outside_speed: 450 },
            { id: 'sab', provider: 'sabnzbd', filter_mode: 'selected', enabled: true, status: 'connected', speed: null, speed_complete: false, client_speed: 900, outside_speed: null },
        ], items: [],
    };
    assert.equal(hooks.selectedOverview(filtered, 'qb').speed, 0, 'excluded traffic must not leak into the headline');
    assert.equal(hooks.selectedOverview(filtered, 'qb').outside_speed, 450);
    assert.equal(hooks.selectedOverview(filtered, '').speed, null, 'unknown SAB split must not become a false zero');
    const expired = hooks.staleOverview(filtered);
    assert.equal(expired.connections[0].outside_speed, null);
    assert.equal(expired.connections[0].client_speed, null);
    assert.equal(hooks.selectedOverview(expired, 'qb').outside_speed, null);
    assert.equal(hooks.selectedOverview({ ...filtered, workers_enabled: false }, 'qb').outside_speed, null);
}

function testTurnedOffAndFirstSetupRemainDistinct() {
    const hooks = {};
    function element(tag) {
        const result = { tag, children: [], dataset: {}, attributes: {}, _text: '' };
        result.append = (...children) => result.children.push(...children);
        result.replaceChildren = (...children) => { result._text = ''; result.children = children; };
        result.setAttribute = (name, value) => { result.attributes[name] = value; };
        result.addEventListener = () => {};
        result.querySelector = () => null;
        result.querySelectorAll = () => [];
        Object.defineProperty(result, 'lastChild', { get: () => result.children.at(-1) });
        Object.defineProperty(result, 'textContent', {
            get: () => result._text + result.children.map((child) => child.textContent ?? String(child)).join(''),
            set: (value) => { result._text = String(value); result.children = []; },
        });
        return result;
    }
    const root = element('main');
    root.dataset.canManage = 'true';
    run('public/assets/js/downloads.js', {
        window: { JellydashDownloadsTestHooks: hooks, addEventListener() {} },
        document: { hidden: true, addEventListener() {},
            querySelector: (selector) => selector === '[data-downloads-root]' ? root : null,
            querySelectorAll: () => [], createElement: element, createElementNS: (_, tag) => element(tag),
            createDocumentFragment: () => element('fragment'), createTextNode: (value) => ({ textContent: value }) },
        Number, Set, Error, FormData, AbortController,
    });
    root.append(element('old activity'));
    hooks.renderOverview({ feature_enabled: false, configured: false });
    assert.match(root.textContent, /Downloads are turned off/);
    assert.match(root.textContent, /Open Settings/);
    assert.doesNotMatch(root.textContent, /Add client|Connect your download client/);
    root.dataset.canManage = 'false';
    hooks.renderOverview({ feature_enabled: false, configured: false });
    assert.doesNotMatch(root.textContent, /Open Settings|sign-in/);
    root.dataset.canManage = 'true';
    hooks.renderOverview({ feature_enabled: true, configured: false });
    assert.match(root.textContent, /Connect your download client/);
    assert.match(root.textContent, /Add client/);
    assert.doesNotMatch(root.textContent, /turned off|sign-in/);
}

function testWarningHistoryRendersAsNeedsAttention() {
    const hooks = {};
    function element(tag) {
        const result = { tag, className: '', children: [], attributes: {}, _text: '' };
        result.append = (...children) => result.children.push(...children);
        result.replaceChildren = (...children) => { result._text = ''; result.children = children; };
        result.addEventListener = () => {};
        result.setAttribute = (name, value) => { result.attributes[name] = value; };
        Object.defineProperty(result, 'textContent', {
            get: () => result._text + result.children.map((child) => child.textContent ?? String(child)).join(''),
            set: (value) => { result._text = String(value); result.children = []; },
        });
        return result;
    }
    run('public/assets/js/downloads.js', {
        window: { JellydashDownloadsTestHooks: hooks },
        document: { querySelector: () => null, createElement: element, createElementNS: (_, tag) => element(tag),
            createTextNode: (value) => ({ textContent: String(value) }) },
        Number, Set, Error, FormData, AbortController,
    });
    const history = hooks.renderHistory({ now: 1000, history: [{
        provider: 'nzbget', status: 'warning', title: 'Postprocessing warning', category: 'Movies',
        connection_name: 'NZBGet', observed_at: 900, size: 1000,
    }] });
    const find = (node, className) => node.className?.split(' ').includes(className)
        ? node : node.children?.map((child) => find(child, className)).find(Boolean);
    const warning = find(history, 'downloads-warning');
    assert.ok(warning, 'NZBGet warning should use its amber status class');
    assert.match(warning.textContent, /Needs attention/);
    assert.match(warning.textContent, /Check NZBGet for details\./);
    assert.doesNotMatch(warning.textContent, /Completed|Failed/);

    const affected = hooks.renderHistory({ now: 1000, connections: [
        { name: 'First', enabled: true, history: { status: 'error', error: 'history_update_failed' } },
        { name: 'Second', enabled: true, history: { status: 'connected', error: null } },
    ], history: Array.from({ length: 25 }, (_, i) => ({
        provider: 'qbittorrent', status: 'completed', title: `Result ${i}`,
        observed_at: 900 - i, size: 1000,
    })) });
    const note = find(affected, 'downloads-history-update-note');
    assert.match(note.textContent, /Recent activity couldn't update for First/);
    assert.doesNotMatch(note.textContent, /Second/);
    const countRows = (node) => (node.className?.split(' ').includes('downloads-history-row') ? 1 : 0)
        + (node.children || []).reduce((sum, child) => sum + countRows(child), 0);
    assert.equal(countRows(affected), 20, 'the visible list uses the twenty-result limit');
    const recovered = hooks.renderHistory({ now: 1000, connections: [
        { name: 'First', enabled: true, history: { status: 'connected', error: null } },
    ], history: [{ provider: 'qbittorrent', status: 'completed', title: 'Saved result', observed_at: 900 }] });
    assert.equal(find(recovered, 'downloads-history-update-note'), undefined, 'a successful history refresh clears the note');
    const emptyError = hooks.renderHistory({ now: 1000, connections: [
        { name: 'First', enabled: true, history: { status: 'error', error: 'history_update_failed' } },
    ], history: [] });
    assert.match(find(emptyError, 'downloads-history-update-note').textContent, /Recent activity couldn't update/);
}

async function testLateConnectionTestsCannotRestoreStaleReceipts() {
    const hooks = {};
    const message = () => ({ textContent: '', hidden: true, classList: { toggle() {} } });
    const resultMessage = message();
    const errorMessage = message();
    const values = { name: 'Downloader', url: 'http://first.test', username: 'owner', secret: 'password', verify_tls: 'on', filter_mode: 'all' };
    const button = { disabled: false, textContent: 'Test connection' };
    const form = { reportValidity: () => true, elements: {
        url: { value: values.url }, username: { required: false }, secret: { required: false },
        categories: { placeholder: '' },
        name: { value: values.name, focus() {} },
    } };
    const fields = {
        '[data-downloads-client-form]': form,
        '[data-downloads-test-result]': resultMessage,
        '[data-downloads-editor-error]': errorMessage,
        '[data-downloads-test]': button,
    };
    for (const selector of ['provider-choice', 'editor-fields', 'editor-title', 'editor-description',
        'username-field', 'username-label', 'secret-label', 'tags-field', 'save', 'secret-help', 'selected-label',
        'category-label', 'category-help', 'deluge-label-note']) {
        fields[`[data-downloads-${selector}]`] = {};
    }
    const editor = {
        open: true,
        close() { this.open = false; },
        querySelector: (selector) => fields[selector] || null,
    };
    const pending = [];
    const script = fs.readFileSync(path.join(rootDir, 'public/assets/js/downloads.js'), 'utf8')
        .replace('hooks.formatBytes = formatBytes;', 'hooks.testConnection = testConnection; hooks.invalidateReceipt = invalidateReceipt; hooks.closeEditor = closeEditor; hooks.chooseProvider = chooseProvider; hooks.formPayload = formPayload; hooks.management = management; hooks.formatBytes = formatBytes;');
    vm.runInNewContext(script, {
        window: { JellydashDownloadsTestHooks: hooks },
        document: { querySelector: (selector) => selector === '[data-downloads-editor]' ? editor : null },
        FormData: class { get(name) { return values[name] ?? null; } },
        fetch: () => new Promise((resolve) => pending.push(resolve)),
        Number, Set, Error, AbortController,
    });
    hooks.management.provider = 'qbittorrent';
    hooks.chooseProvider('transmission');
    assert.equal(form.elements.username.required, true);
    assert.equal(fields['[data-downloads-tags-field]'].hidden, true);
    assert.equal(fields['[data-downloads-category-label]'].textContent, 'Labels');
    assert.equal(hooks.formPayload('test').username, 'owner');
    assert.equal(hooks.formPayload('test').tags.length, 0);
    values.url = 'http://first.test/transmission/rpc/';
    assert.equal(hooks.formPayload('test').url, 'http://first.test');
    hooks.chooseProvider('deluge');
    assert.equal(form.elements.username.required, false);
    assert.equal(fields['[data-downloads-username-field]'].hidden, true);
    assert.equal(fields['[data-downloads-deluge-label-note]'].hidden, false);
    assert.equal(hooks.formPayload('test').username, '');
    values.url = 'http://first.test/json';
    assert.equal(hooks.formPayload('test').url, 'http://first.test');
    hooks.chooseProvider('nzbget');
    assert.equal(form.elements.username.required, false);
    assert.equal(fields['[data-downloads-username-field]'].hidden, false);
    assert.equal(fields['[data-downloads-username-label]'].textContent, 'Control username (optional)');
    assert.equal(fields['[data-downloads-category-label]'].textContent, 'Categories');
    assert.equal(hooks.formPayload('test').username, 'owner');
    values.username = '';
    assert.equal(hooks.formPayload('test').username, '', 'NZBGet accepts password-only control authentication');
    values.username = 'owner';
    values.url = 'http://first.test/jsonrpc';
    assert.equal(hooks.formPayload('test').url, 'http://first.test');
    values.url = 'http://first.test';
    hooks.chooseProvider('qbittorrent');
    const finish = async (receipt, ok = true) => {
        pending.shift()({ ok, status: ok ? 200 : 502, json: async () => ok
            ? { receipt, version: '5.1', categories: [], tags: [] }
            : { error: 'Could not connect.' } });
        await Promise.resolve();
        await Promise.resolve();
    };

    const changedInput = hooks.testConnection(button);
    values.url = 'http://second.test';
    form.elements.url.value = values.url;
    hooks.invalidateReceipt();
    await finish('old-url');
    await changedInput;
    assert.equal(hooks.management.receipt, null, 'late success must not authorize changed connection values');
    assert.equal(resultMessage.hidden, true, 'late success must not label the changed form connected');

    const validInput = hooks.testConnection(button);
    await finish('current-url');
    await validInput;
    assert.equal(hooks.management.receipt, 'current-url', 'an unchanged test result remains usable');

    hooks.invalidateReceipt();
    const changedProvider = hooks.testConnection(button);
    hooks.chooseProvider('sabnzbd');
    await finish('old-provider');
    await changedProvider;
    assert.equal(hooks.management.receipt, null, 'switching providers rejects a late result');

    hooks.chooseProvider('qbittorrent');
    const staleError = hooks.testConnection(button);
    values.secret = 'new-password';
    hooks.invalidateReceipt();
    await finish('ignored-error', false);
    await staleError;
    assert.equal(errorMessage.hidden, true, 'late test errors must not replace the current form state');

    const closedEditor = hooks.testConnection(button);
    hooks.closeEditor();
    editor.open = true;
    const reopenedEditor = hooks.testConnection(button);
    await finish('closed-editor');
    await closedEditor;
    assert.equal(hooks.management.receipt, null, 'closing and reopening rejects the old test result');
    await finish('reopened-editor');
    await reopenedEditor;
    assert.equal(hooks.management.receipt, 'reopened-editor', 'the new editor test stays usable');
}

function testDownloadsScriptsKeepTheSecurityAndPollingContract() {
    const downloads = fs.readFileSync(path.join(rootDir, 'public/assets/js/downloads.js'), 'utf8');
    const indicator = fs.readFileSync(path.join(rootDir, 'public/assets/js/download-indicator.js'), 'utf8');

    assert.doesNotMatch(downloads, /\.innerHTML\s*=/, 'download titles and errors must be rendered as text');
    assert.match(downloads, /credentials: 'same-origin'/);
    assert.match(downloads, /'X-CSRF-Token': csrfToken\(\)/);
    assert.match(downloads, /document\.hidden/);
    assert.match(downloads, /AbortController/);
    assert.match(downloads, /Math\.min\(60000/);
    assert.match(indicator, /overview\.php\?summary=1/);
    assert.match(indicator, /document\.hidden/);
    assert.match(indicator, /AbortController/);
}

testDownloadsFormattingAndStableAnnouncements();
testIndicatorUsesDownloadingCountAndClientWideSpeed();
testClientFiltersAndSpeedFallbacks();
testTurnedOffAndFirstSetupRemainDistinct();
testWarningHistoryRendersAsNeedsAttention();
testDownloadsScriptsKeepTheSecurityAndPollingContract();
testLateConnectionTestsCannotRestoreStaleReceipts().then(() => {
    console.log('Downloads frontend state tests passed.');
}).catch((error) => { console.error(error); process.exitCode = 1; });
