'use strict';

(() => {
    const indicator = document.querySelector('[data-download-indicator]');
    if (!indicator) return;

    const state = { timer: null, controller: null, failures: 0, sequence: 0, stopped: false, lastData: null, lastSuccessAt: null };
    const isFiniteNumber = (value) => typeof value === 'number' && Number.isFinite(value);
    const monitoringEnabled = (data) => data?.monitoring_enabled === undefined
        ? data?.workers_enabled !== false : data.monitoring_enabled === true;

    function formatBytes(value) {
        if (!isFiniteNumber(value) || value < 0) return '';
        const units = ['B', 'KB', 'MB', 'GB', 'TB'];
        let amount = value;
        let unit = 0;
        while (amount >= 1000 && unit < units.length - 1) {
            amount /= 1000;
            unit += 1;
        }
        const digits = amount >= 100 ? 0 : amount >= 10 ? 1 : 2;
        return `${amount.toFixed(digits)} ${units[unit]}/s`;
    }

    function summaryText(data, connections, includeSpeed = true) {
        if (!monitoringEnabled(data) || connections.every((item) => item.enabled === false || item.status === 'disabled')) {
            return 'Download monitoring off';
        }
        const active = Number.isInteger(data.active_count) && data.active_count > 0 ? data.active_count : 0;
        const offline = connections.filter((item) => item.status === 'offline').length;
        const available = connections.some((item) => ['connected', 'stale', 'waiting'].includes(item.status));
        if (!available && offline) return 'Downloads unavailable';
        if (active > 0) {
            const parts = [`${active} downloading`];
            if (includeSpeed && isFiniteNumber(data.speed)) parts.push(formatBytes(data.speed));
            if (offline) parts.push(`${offline} offline`);
            return parts.join(' · ');
        }
        if (connections.every((item) => item.status === 'waiting')) return 'Downloads waiting for first sync';
        if (connections.every((item) => item.status === 'stale')) return 'Downloads update delayed';
        return offline ? `Downloads idle · ${offline} offline` : 'Downloads idle';
    }

    function element(tag, className, text) {
        const node = document.createElement(tag);
        node.className = className;
        if (text !== undefined) node.textContent = text;
        return node;
    }

    function icon(path, className) {
        const svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
        svg.setAttribute('viewBox', '0 0 24 24');
        svg.setAttribute('aria-hidden', 'true');
        svg.setAttribute('class', className);
        const shape = document.createElementNS('http://www.w3.org/2000/svg', 'path');
        shape.setAttribute('d', path);
        svg.append(shape);
        return svg;
    }

    function renderWidget(detail, speed, active, attention, accessibleText) {
        const fragment = document.createDocumentFragment();
        const mark = element('span', 'download-indicator-icon');
        mark.append(icon('M12 3v11m-4-4 4 4 4-4M5 15v5h14v-5', ''));
        const copy = element('span', 'download-indicator-copy');
        copy.append(element('span', 'download-indicator-title', 'Downloads'),
            element('span', 'download-indicator-detail', detail));
        fragment.append(mark, copy);
        if (active && isFiniteNumber(speed) && speed >= 0) {
            const [amount, unit] = formatBytes(speed).split(' ');
            const reading = element('span', 'download-indicator-speed');
            reading.append(element('strong', '', amount), element('small', '', unit));
            fragment.append(reading);
        }
        fragment.append(icon('m9 5 7 7-7 7', 'download-indicator-arrow'));
        indicator.replaceChildren(fragment);
        indicator.setAttribute('data-active', String(active));
        indicator.setAttribute('data-attention', String(attention));
        indicator.hidden = false;
        indicator.setAttribute('aria-label', `${accessibleText}. Open Downloads.`);
        indicator.setAttribute('title', accessibleText);
    }

    function render(data) {
        const connections = Array.isArray(data?.connections) ? data.connections : [];
        if (data?.configured !== true || connections.length === 0) {
            indicator.hidden = true;
            indicator.replaceChildren();
            return;
        }
        const enabled = connections.filter((item) => item.enabled !== false && item.status !== 'disabled');
        const summary = summaryText(data, enabled, false);
        const labels = {
            'Download monitoring off': 'Monitoring off',
            'Downloads unavailable': 'Clients unavailable',
            'Downloads idle': 'No active downloads',
            'Downloads waiting for first sync': 'Waiting for first update',
            'Downloads update delayed': 'Updates delayed',
        };
        const detail = labels[summary] || summary.replace(/^Downloads idle/, 'No active downloads');
        const active = monitoringEnabled(data) && enabled.some((item) => item.status === 'connected')
            && Number.isInteger(data.active_count) && data.active_count > 0;
        const attention = monitoringEnabled(data) && enabled.some((item) => ['offline', 'stale'].includes(item.status));
        renderWidget(detail, data.speed, active, attention, summaryText(data, enabled));
    }

    function unavailableText(lastSuccessAt, now = Math.floor(Date.now() / 1000)) {
        if (!Number.isInteger(lastSuccessAt)) return 'Downloads unavailable';
        const age = Math.max(0, now - lastSuccessAt);
        if (age < 60) return `Downloads unavailable · last response ${age}s ago`;
        if (age < 3600) return `Downloads unavailable · last response ${Math.floor(age / 60)}m ago`;
        return `Downloads unavailable · last response ${Math.floor(age / 3600)}h ago`;
    }

    function renderUnavailable() {
        renderWidget('Status unavailable', null, false, true, unavailableText(state.lastSuccessAt));
    }

    function schedule(delay) {
        if (state.stopped || document.hidden) return;
        window.clearTimeout(state.timer);
        state.timer = window.setTimeout(refresh, delay);
    }

    async function refresh() {
        if (state.stopped || document.hidden || state.controller) return;
        const sequence = ++state.sequence;
        const controller = new AbortController();
        state.controller = controller;
        let timedOut = false;
        const timeout = window.setTimeout(() => { timedOut = true; controller.abort(); }, 8000);
        try {
            const response = await fetch('/api/downloads/overview.php?summary=1', {
                cache: 'no-store', credentials: 'same-origin', signal: controller.signal,
            });
            if (!response.ok) throw new Error(`HTTP ${response.status}`);
            const data = await response.json();
            if (sequence !== state.sequence) return;
            state.failures = 0;
            state.lastData = data;
            state.lastSuccessAt = Math.floor(Date.now() / 1000);
            render(data);
            schedule(5000);
        } catch (error) {
            if (sequence !== state.sequence || (error?.name === 'AbortError' && !timedOut)) return;
            state.failures += 1;
            renderUnavailable();
            schedule(Math.min(60000, 5000 * (2 ** Math.min(state.failures, 4))));
        } finally {
            window.clearTimeout(timeout);
            if (state.controller === controller) state.controller = null;
        }
    }

    function stop() {
        window.clearTimeout(state.timer);
        state.timer = null;
        if (state.controller) state.controller.abort();
        state.controller = null;
    }

    document.addEventListener('visibilitychange', () => {
        if (document.hidden) stop();
        else refresh();
    });
    window.addEventListener('pagehide', () => { state.stopped = true; stop(); });
    window.addEventListener('pageshow', () => { state.stopped = false; refresh(); });
    refresh();

    const hooks = window.JellydashDownloadIndicatorTestHooks = window.JellydashDownloadIndicatorTestHooks || {};
    hooks.formatBytes = formatBytes;
    hooks.summaryText = summaryText;
    hooks.unavailableText = unavailableText;
    hooks.render = render;
    hooks.renderUnavailable = renderUnavailable;
    hooks.refresh = refresh;
})();
