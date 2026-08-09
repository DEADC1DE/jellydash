(function () {
    const navCount = document.querySelector('[data-nav-count]');

    if (!navCount) {
        return;
    }

    async function refresh() {
        const response = await fetch('/api/now-playing.php', {
            headers: { Accept: 'application/json' },
            cache: 'no-store',
        });

        if (!response.ok) {
            throw new Error('Now Playing count request failed with HTTP ' + response.status);
        }

        const payload = await response.json();
        const stats = payload.stats || {};
        navCount.textContent = String(Number(stats.active_streams || 0));
        navCount.classList.remove('is-loading');
    }

    refresh().catch(() => {});
    window.setInterval(() => {
        refresh().catch(() => {});
    }, 10000);
}());
