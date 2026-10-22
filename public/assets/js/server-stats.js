(function () {
    const card = document.querySelector('[data-server-card]');
    const status = card ? card.querySelector('[data-server-status]') : null;
    const cpu = card ? card.querySelector('[data-server-cpu]') : null;
    const cpuBar = card ? card.querySelector('[data-server-cpu-bar]') : null;
    const ram = card ? card.querySelector('[data-server-ram]') : null;
    const ramBar = card ? card.querySelector('[data-server-ram-bar]') : null;
    let refreshInFlight = false;
    let authExpired = false;
    let pageSuspended = false;
    let resumePending = false;
    let activeController = null;
    let requestSequence = 0;
    let latestAppliedSequence = 0;

    if (!card) {
        return;
    }

    function pct(value) {
        const number = Number(value || 0);
        return Math.max(0, Math.min(100, number)) + '%';
    }

    function apply(data) {
        card.classList.toggle('is-unavailable', !data.available);

        if (status) {
            status.textContent = data.status || 'Unavailable';
        }
        if (cpu) {
            cpu.textContent = data.cpu_label || 'N/A';
        }
        if (cpuBar) {
            cpuBar.style.width = data.cpu_pct === null ? '0%' : pct(data.cpu_pct);
        }
        if (ram) {
            ram.textContent = data.ram_label || 'N/A';
        }
        if (ramBar) {
            ramBar.style.width = data.ram_pct === null ? '0%' : pct(data.ram_pct);
        }
    }

    function renderFailure() {
        apply({ available: false, status: authExpired ? 'Session expired. Sign in again.' : 'Unavailable' });
    }

    function resume() {
        pageSuspended = false;
        if (refreshInFlight) resumePending = true;
        else refresh().catch(renderFailure);
    }

    async function refresh() {
        if (authExpired || pageSuspended || refreshInFlight || document.hidden) {
            return;
        }
        refreshInFlight = true;
        const sequence = ++requestSequence;
        const controller = typeof AbortController === 'function' ? new AbortController() : null;
        activeController = controller;
        const timer = window.setTimeout ? window.setTimeout(() => controller?.abort(), 8000) : null;

        try {
        const response = await fetch('/api/server-stats.php', {
            headers: { Accept: 'application/json' },
            cache: 'no-store',
            signal: controller?.signal,
        });

        if (!response.ok) {
            const error = new Error('Request failed with HTTP ' + response.status);
            error.status = response.status;
            throw error;
        }

        const payload = await response.json();
        if (sequence === requestSequence && sequence >= latestAppliedSequence) {
            latestAppliedSequence = sequence;
            apply(payload);
        }
        } catch (error) {
            if (sequence !== requestSequence) return;
            if (error.status === 401 || error.status === 403) authExpired = true;
            throw error;
        } finally {
            if (timer !== null && window.clearTimeout) {
                window.clearTimeout(timer);
            }
            if (activeController === controller) {
                activeController = null;
            }
            refreshInFlight = false;
            if (resumePending) { resumePending = false; refresh().catch(renderFailure); }
        }
    }

    refresh().catch(renderFailure);
    window.setInterval(() => {
        refresh().catch(renderFailure);
    }, 10000);
    document.addEventListener('visibilitychange', () => {
        if (document.hidden) { ++requestSequence; activeController?.abort(); }
        else {
            resume();
        }
    });
    window.addEventListener('pagehide', () => { pageSuspended = true; ++requestSequence; activeController?.abort(); });
    window.addEventListener('pageshow', resume);
}());
