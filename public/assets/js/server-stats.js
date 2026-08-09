(function () {
    const card = document.querySelector('[data-server-card]');
    const status = card ? card.querySelector('[data-server-status]') : null;
    const cpu = card ? card.querySelector('[data-server-cpu]') : null;
    const cpuBar = card ? card.querySelector('[data-server-cpu-bar]') : null;
    const ram = card ? card.querySelector('[data-server-ram]') : null;
    const ramBar = card ? card.querySelector('[data-server-ram-bar]') : null;

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

    refresh().catch(() => apply({ available: false, status: 'Unavailable' }));
    window.setInterval(() => {
        refresh().catch(() => apply({ available: false, status: 'Unavailable' }));
    }, 10000);
}());
