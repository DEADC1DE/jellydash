(function () {
    const card = document.querySelector('[data-server-card]');

    if (!card) {
        return;
    }

    const status = card.querySelector('[data-server-status]');
    const cpu = card.querySelector('[data-server-cpu]');
    const cpuBar = card.querySelector('[data-server-cpu-bar]');
    const ram = card.querySelector('[data-server-ram]');
    const ramBar = card.querySelector('[data-server-ram-bar]');
    const navCount = document.querySelector('[data-nav-count]');

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

    async function refresh() {
        const response = await fetch('/api/server-stats.php', {
            headers: { Accept: 'application/json' },
            cache: 'no-store',
        });

        if (!response.ok) {
            throw new Error('Server stats request failed with HTTP ' + response.status);
        }

        apply(await response.json());
    }

    async function refreshNowPlayingCount() {
        if (!navCount) {
            return;
        }

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

    refresh().catch(() => apply({ available: false, status: 'Unavailable' }));
    refreshNowPlayingCount().catch(() => {});
    window.setInterval(() => {
        refresh().catch(() => apply({ available: false, status: 'Unavailable' }));
        refreshNowPlayingCount().catch(() => {});
    }, 10000);
}());
