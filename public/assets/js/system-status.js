(function () {
    'use strict';

    const root = document.querySelector('[data-system-status]');
    const nav = document.querySelector('[data-system-status-nav]');
    if (!root && !nav) return;

    const summary = root && root.querySelector('[data-system-status-summary]');
    const components = root && root.querySelector('[data-system-status-components]');
    const copyButton = root && root.querySelector('[data-system-status-copy]');
    const feedback = root && root.querySelector('[data-system-status-feedback]');
    const manual = root && root.querySelector('[data-system-status-manual]');
    const manualText = root && root.querySelector('[data-system-status-text]');
    const navLabel = document.querySelector('[data-system-status-nav-label]');
    const navDot = document.querySelector('[data-system-status-nav-dot]');
    const allowedStates = ['healthy', 'checking', 'delayed', 'failed', 'disabled', 'unknown'];
    let diagnostics = null;
    let requestSequence = 0;
    let inFlight = false;
    let firstResult = true;
    let activeController = null;
    let authExpired = false;
    let pageSuspended = false;
    let resumePending = false;
    let renderedRows = [];
    let failureState = null;

    function settleInitialAnchor() {
        if (firstResult && window.location.hash === '#system-status') {
            root.scrollIntoView({ block: 'start' });
        }
        firstResult = false;
    }

    function age(epoch) {
        if (!Number.isFinite(epoch) || epoch <= 0) return 'No successful check yet';
        const seconds = Math.max(0, Math.floor(Date.now() / 1000) - epoch);
        if (seconds < 60) return 'Last successful check just now';
        if (seconds < 3600) return 'Last successful check ' + Math.floor(seconds / 60) + ' min ago';
        if (seconds < 86400) return 'Last successful check ' + Math.floor(seconds / 3600) + ' hr ago';
        return 'Last successful check ' + Math.floor(seconds / 86400) + ' days ago';
    }

    function shortAge(epoch) {
        if (!Number.isFinite(epoch) || epoch <= 0) return '';
        return age(epoch).replace('Last successful check', '').trim();
    }

    function renderNav(state) {
        const known = state === 'healthy' || state === 'attention' ? state : 'unknown';
        const label = known === 'healthy' ? 'Healthy' : known === 'attention' ? 'Needs attention' : 'Status unknown';
        if (navLabel && navLabel.textContent !== label) navLabel.textContent = label;
        if (navDot && navDot.className !== 'is-' + known) navDot.className = 'is-' + known;
        if (nav) nav.setAttribute('aria-label', 'System status, ' + label.toLowerCase());
    }

    function clearRows() {
        if (!components) return;
        while (components.firstChild) components.removeChild(components.firstChild);
    }

    function render(payload) {
        if (!payload || !Array.isArray(payload.components) || !payload.diagnostics || Array.isArray(payload.diagnostics) || typeof payload.diagnostics !== 'object') {
            throw new Error('Invalid system status response.');
        }
        renderNav(payload.state);
        diagnostics = payload.diagnostics;
        if (!root) return;
        const hadFailure = failureState !== null;
        failureState = null;
        const nextRows = [];
        payload.components.forEach((component, index) => {
            const state = allowedStates.includes(component.state) ? component.state : 'unknown';
            const key = String(component.id || index);
            const previous = renderedRows.find((entry) => entry.key === key);
            const row = previous ? previous.row : document.createElement('div');
            if (row.className !== 'system-status-row is-' + state) row.className = 'system-status-row is-' + state;
            const stateNode = previous ? previous.stateNode : document.createElement('span');
            if (stateNode.className !== 'system-status-state') stateNode.className = 'system-status-state';
            const stateText = state.charAt(0).toUpperCase() + state.slice(1);
            if (stateNode.textContent !== stateText) stateNode.textContent = stateText;
            const copy = previous ? previous.copy : document.createElement('span');
            const label = previous ? previous.label : document.createElement('strong');
            const detail = previous ? previous.detail : document.createElement('small');
            const labelText = String(component.label || 'Unknown component');
            if (label.textContent !== labelText) label.textContent = labelText;
            const lastSuccess = Number(component.last_success_at);
            const details = [String(component.message || '')];
            if (state !== 'disabled' || (Number.isFinite(lastSuccess) && lastSuccess > 0)) {
                details.push(age(lastSuccess));
            }
            if (String(component.id || '').endsWith('_notifications') && Number(component.last_delivery_at) > 0) {
                details.push('Last delivery attempt ' + shortAge(Number(component.last_delivery_at)));
            }
            const pending = Number(component.pending_retries || 0);
            if (pending > 0) details.push(pending + ' pending ' + (pending === 1 ? 'retry' : 'retries'));
            const detailText = details.filter(Boolean).join(' · ');
            if (detail.textContent !== detailText) detail.textContent = detailText;
            if (!previous) {
                copy.appendChild(label);
                copy.appendChild(detail);
                row.appendChild(stateNode);
                row.appendChild(copy);
            }
            nextRows.push({ key, row, stateNode, copy, label, detail });
        });
        if (hadFailure || nextRows.length !== renderedRows.length || nextRows.some((entry, index) => entry.row !== renderedRows[index].row)) {
            clearRows();
            nextRows.forEach((entry) => components.appendChild(entry.row));
        }
        renderedRows = nextRows;
        const summaryText = payload.state === 'healthy' ? 'Background checks are healthy' : payload.state === 'attention' ? 'Some checks need attention' : 'System status is unknown';
        if (summary.textContent !== summaryText) summary.textContent = summaryText;
        copyButton.disabled = false;
        settleInitialAnchor();
    }

    function renderFailure(error) {
        const expired = error && (error.status === 401 || error.status === 403);
        const nextFailure = expired ? 'auth' : 'outage';
        if (failureState === nextFailure) return;
        failureState = nextFailure;
        renderedRows = [];
        diagnostics = null;
        renderNav('unknown');
        if (!root) return;
        copyButton.disabled = true;
        manual.hidden = true;
        manualText.value = '';
        clearRows();
        const row = document.createElement('div');
        row.className = 'system-status-row is-unknown';
        const status = document.createElement('span');
        status.className = 'system-status-state';
        status.textContent = 'Unavailable';
        const message = document.createElement('span');
        const title = document.createElement('strong');
        title.textContent = error && (error.status === 401 || error.status === 403) ? 'Session expired' : 'Status unavailable';
        message.appendChild(title);
        if (error && (error.status === 401 || error.status === 403)) {
            const link = document.createElement('a');
            link.href = '/login';
            link.textContent = 'Sign in again';
            message.appendChild(link);
        }
        row.appendChild(status);
        row.appendChild(message);
        components.appendChild(row);
        summary.textContent = 'Status unavailable';
        settleInitialAnchor();
    }

    function resume() {
        pageSuspended = false;
        if (inFlight) resumePending = true;
        else refresh();
    }

    async function refresh() {
        if (authExpired || pageSuspended || inFlight || document.hidden) return;
        inFlight = true;
        const sequence = ++requestSequence;
        const controller = typeof AbortController === 'function' ? new AbortController() : null;
        activeController = controller;
        const timeout = window.setTimeout(() => controller && controller.abort(), 8000);
        try {
            const response = await fetch('/api/system-status.php', { headers: { Accept: 'application/json' }, cache: 'no-store', signal: controller ? controller.signal : undefined });
            if (!response.ok) {
                const error = new Error('System status request failed.');
                error.status = response.status;
                throw error;
            }
            const payload = await response.json();
            if (sequence === requestSequence) render(payload);
        } catch (error) {
            if (sequence === requestSequence) {
                if (error.status === 401 || error.status === 403) authExpired = true;
                renderFailure(error);
            }
        } finally {
            window.clearTimeout(timeout);
            if (activeController === controller) activeController = null;
            inFlight = false;
            if (resumePending) { resumePending = false; refresh(); }
        }
    }

    async function copyDiagnostics() {
        if (!diagnostics) return;
        const text = JSON.stringify(diagnostics, null, 2);
        try {
            if (!navigator.clipboard || typeof navigator.clipboard.writeText !== 'function') throw new Error('Clipboard unavailable.');
            await navigator.clipboard.writeText(text);
            feedback.textContent = 'Diagnostics copied.';
            manual.hidden = true;
        } catch (error) {
            manualText.value = text;
            manual.hidden = false;
            manualText.focus();
            manualText.select();
            feedback.textContent = 'Copy was not available. Select the diagnostics below and copy them manually.';
        }
    }

    if (copyButton) copyButton.addEventListener('click', copyDiagnostics);
    document.addEventListener('visibilitychange', () => {
        if (document.hidden) { ++requestSequence; activeController?.abort(); }
        else resume();
    });
    if (window.addEventListener) {
        window.addEventListener('pagehide', () => { pageSuspended = true; ++requestSequence; activeController?.abort(); });
        window.addEventListener('pageshow', resume);
    }
    if (window.JellydashFrontendTestHooks) window.JellydashFrontendTestHooks.systemStatus = { render, renderFailure, refresh, copyDiagnostics };
    refresh();
    window.setInterval(refresh, 30000);
}());
