(function () {
    const page = document.querySelector('[data-now-playing-page]');
    const panel = document.querySelector('[data-recently-added]');
    const row = document.querySelector('[data-recently-added-row]');
    const windowLabel = document.querySelector('[data-recently-added-window]');
    const toggle = document.querySelector('[data-recently-added-toggle]');
    const toggleLabel = document.querySelector('[data-recently-added-toggle-label]');
    const countLabel = document.querySelector('[data-recently-added-count]');
    const liveRoot = document.querySelector('[data-now-playing-root]');

    if (!page || !panel || !row || !windowLabel || !toggle || !toggleLabel || !countLabel) {
        return;
    }

    let playbackActive = liveRoot?.classList.contains('has-streams') || false;
    let userExpanded = false;

    function applyCompactState() {
        const canCollapse = playbackActive;
        const collapsed = canCollapse && !userExpanded;

        panel.classList.toggle('is-collapsible', canCollapse);
        panel.classList.toggle('is-collapsed', collapsed);
        toggle.hidden = !canCollapse;
        toggle.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
        toggleLabel.textContent = collapsed ? 'Show' : 'Hide';
        row.setAttribute('aria-hidden', collapsed ? 'true' : 'false');
        row.tabIndex = collapsed ? -1 : 0;
    }

    function setPlaybackActive(active) {
        if (active !== playbackActive) {
            playbackActive = active;
            userExpanded = false;
        }

        applyCompactState();
    }

    toggle.addEventListener('click', () => {
        userExpanded = !userExpanded;
        applyCompactState();
    });

    window.addEventListener('jellydash:now-playing', (event) => {
        const streams = Array.isArray(event.detail?.streams) ? event.detail.streams : [];
        setPlaybackActive(streams.length > 0);
    });

    applyCompactState();

    row.addEventListener('keydown', (event) => {
        const behavior = window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth';
        if (event.key === 'ArrowLeft' || event.key === 'ArrowRight') {
            event.preventDefault();
            const cardWidth = row.querySelector('.recent-media-card')?.getBoundingClientRect().width || 108;
            const gap = Number.parseFloat(window.getComputedStyle(row).columnGap) || 12;
            row.scrollBy({ left: event.key === 'ArrowRight' ? cardWidth + gap : -(cardWidth + gap), behavior });
        } else if (event.key === 'Home' || event.key === 'End') {
            event.preventDefault();
            row.scrollTo({ left: event.key === 'End' ? row.scrollWidth : 0, behavior });
        }
    });

    function escapeHtml(value) {
        return String(value ?? '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function shortDateLabel(value) {
        const label = String(value ?? '');
        if (label.toLowerCase() === 'yesterday') {
            return '1d';
        }

        const days = label.match(/^(\d+) days? ago$/i);
        return days ? `${days[1]}d` : label;
    }

    function card(item) {
        const tone = Math.max(1, Math.min(5, Number(item.tone) || 1));
        const poster = item.poster
            ? `<img src="${escapeHtml(item.poster)}" alt="" loading="lazy" decoding="async">`
            : '';

        return `
            <article class="recent-media-card">
                <div class="recent-media-poster is-tone-${tone}">
                    ${poster}
                    <span class="recent-media-library">${escapeHtml(item.library || 'Media')}</span>
                    <span class="recent-media-date" title="${escapeHtml(item.dateLabel || '')}" data-short-date="${escapeHtml(shortDateLabel(item.dateLabel))}"><i></i><span>${escapeHtml(item.dateLabel || '')}</span></span>
                </div>
                <strong title="${escapeHtml(item.title || '')}">${escapeHtml(item.title || 'Unknown title')}</strong>
                <span class="recent-media-meta" title="${escapeHtml(item.meta || '')}">${escapeHtml(item.meta || '')}</span>
            </article>
        `;
    }

    async function loadRecentlyAdded() {
        const response = await fetch('/api/recently-added.php', {
            headers: { Accept: 'application/json' },
            cache: 'no-store',
        });
        if (!response.ok) {
            return;
        }

        const payload = await response.json();
        const items = Array.isArray(payload.items) ? payload.items : [];
        if (items.length === 0) {
            return;
        }

        row.innerHTML = items.map(card).join('');
        row.querySelectorAll('img').forEach((image) => {
            image.addEventListener('error', () => image.remove(), { once: true });
        });

        const days = Math.max(1, Number(payload.windowDays) || 14);
        windowLabel.textContent = `Last ${days} days`;
        countLabel.textContent = `${items.length} fresh`;
        page.classList.add('has-recently-added');
        panel.hidden = false;
        applyCompactState();
        window.requestAnimationFrame(() => panel.classList.add('is-visible'));
    }

    loadRecentlyAdded().catch(() => {
        // Optional by design: Jellyfin or cache failures leave the shelf hidden.
    });
}());
