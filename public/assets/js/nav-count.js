(function () {
    const navCount = document.querySelector('[data-nav-count]');
    let hasLoaded = false;
    let refreshInFlight = false;
    let authExpired = false;
    let pageSuspended = false;
    let resumePending = false;
    let activeController = null;
    let requestSequence = 0;
    let latestAppliedSequence = 0;

    if (!navCount) {
        return;
    }

    // Now Playing already refreshes the full session payload every five
    // seconds and updates this badge from that response. Do not start a second
    // polling loop for the same endpoint on that page.
    if (document.querySelector('[data-now-playing-root]')) {
        return;
    }

    function resume() {
        pageSuspended = false;
        if (refreshInFlight) resumePending = true;
        else refresh().catch(clearLoadingState);
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
        const response = await fetch('/api/now-playing.php', {
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
        if (sequence !== requestSequence || sequence < latestAppliedSequence) {
            return;
        }
        latestAppliedSequence = sequence;
        const stats = payload.stats || {};
        navCount.textContent = String(Number(stats.active_streams || 0));
        navCount.classList.remove('is-loading');
        navCount.classList.remove('is-stale');
        navCount.removeAttribute('title');
        hasLoaded = true;
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
            if (resumePending) { resumePending = false; refresh().catch(clearLoadingState); }
        }
    }

    function clearLoadingState() {
        navCount.textContent = '-';
        navCount.classList.remove('is-loading');
        navCount.classList.add('is-stale');
        navCount.setAttribute('title', authExpired ? 'Session expired. Sign in again.' : hasLoaded ? 'Now Playing count is unavailable' : 'Could not load Now Playing count');
    }

    if (window.JellydashFrontendTestHooks) {
        window.JellydashFrontendTestHooks.refreshNavCount = refresh;
        window.JellydashFrontendTestHooks.clearNavCount = clearLoadingState;
    }

    refresh().catch(clearLoadingState);
    window.setInterval(() => {
        refresh().catch(clearLoadingState);
    }, 10000);
    if (document.addEventListener) {
        document.addEventListener('visibilitychange', () => {
            if (document.hidden) { ++requestSequence; activeController?.abort(); }
            else {
                resume();
            }
        });
    }
    if (window.addEventListener) {
        window.addEventListener('pagehide', () => { pageSuspended = true; ++requestSequence; activeController?.abort(); });
        window.addEventListener('pageshow', resume);
    }
}());
