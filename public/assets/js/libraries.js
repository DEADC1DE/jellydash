(function () {
    const root = document.querySelector('[data-libraries-root]');
    const summaryRoot = document.querySelector('[data-library-summary]');
    const gridRoot = document.querySelector('[data-library-grid]');
    const status = document.querySelector('[data-libraries-status]');

    if (!root || !summaryRoot || !gridRoot || !status) {
        return;
    }

    function escapeHtml(value) {
        return String(value ?? '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function escapeAttr(value) {
        return escapeHtml(value).replace(/`/g, '&#096;');
    }

    function icon(library) {
        if (library.isTv) {
            return '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><rect x="4" y="6" width="16" height="11" rx="2"></rect><path d="M9 21h6"></path><path d="M12 17v4"></path></svg>';
        }

        if (library.isAnime) {
            return '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M12 3l2.5 6.5L21 12l-6.5 2.5L12 21l-2.5-6.5L3 12l6.5-2.5L12 3z"></path></svg>';
        }

        if (library.isEvent) {
            return '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M7 4v16"></path><path d="M17 4v16"></path><path d="M4 8h16"></path><path d="M4 16h16"></path></svg>';
        }

        return '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><rect x="4" y="5" width="16" height="14" rx="2"></rect><path d="M8 5v14"></path><path d="M16 5v14"></path><path d="M4 9h4"></path><path d="M16 9h4"></path><path d="M4 15h4"></path><path d="M16 15h4"></path></svg>';
    }

    function renderSummary(items) {
        summaryRoot.classList.remove('is-loading');
        summaryRoot.innerHTML = (Array.isArray(items) ? items : []).map((item) => `
            <article class="library-summary-card">
                <span><i style="background: ${escapeAttr(item.color)}"></i>${escapeHtml(item.label)}</span>
                <strong>${escapeHtml(item.value)}</strong>
                <small>${escapeHtml(item.sub)}</small>
            </article>
        `).join('');
    }

    function stat(label, value) {
        return `
            <div>
                <dt>${escapeHtml(label)}</dt>
                <dd>${escapeHtml(value)}</dd>
            </div>
        `;
    }

    function renderLibrary(library) {
        const breakdown = Array.isArray(library.breakdown) ? library.breakdown : [];

        return `
            <article class="library-card" style="--library-accent: ${escapeAttr(library.accent)}; --library-chip-bg: ${escapeAttr(library.chipBg)}; --library-chip-border: ${escapeAttr(library.chipBorder)};">
                <div class="library-banner">
                    <span class="library-banner-art" style="background-image: ${escapeAttr(library.banner)}"></span>
                    <span class="library-banner-overlay"></span>
                    <span class="library-type-chip">${icon(library)}${escapeHtml(library.type)}</span>
                    <div class="library-title-block">
                        <span class="library-glyph">${escapeHtml(library.glyph)}</span>
                        <div>
                            <h2 class="library-title">${escapeHtml(library.name)}</h2>
                            <p>${escapeHtml(library.totalFiles)} files &middot; ${escapeHtml(library.size)}</p>
                        </div>
                    </div>
                </div>

                <div class="library-body">
                    <dl class="library-stat-grid">
                        ${stat('Total Time', library.totalTime)}
                        ${stat('Total Files', library.totalFiles)}
                        ${stat('Library Size', library.size)}
                        ${stat('Total Plays', library.totalPlays)}
                        ${stat('Total Playback', library.playback)}
                        ${stat('Last Activity', library.lastActivity)}
                    </dl>

                    <div class="library-last-played">
                        <span>Last Played</span>
                        <strong>${escapeHtml(library.lastPlayed)}</strong>
                        <small>${escapeHtml(library.lastUser)}</small>
                    </div>

                    <div class="library-breakdown">
                        ${breakdown.map((item) => `<span><strong style="color: ${escapeAttr(item.color)}">${escapeHtml(item.value)}</strong>${escapeHtml(item.label)}</span>`).join('')}
                    </div>
                </div>
            </article>
        `;
    }

    function renderLibraries(libraries) {
        gridRoot.classList.remove('is-loading');

        if (!Array.isArray(libraries) || libraries.length === 0) {
            gridRoot.innerHTML = `
                <article class="library-empty-state">
                    <strong>No matching Jellyfin libraries found.</strong>
                    <span>Expected TV Shows, Movies, Stand-Up Comedy, Anime, and PPV & Events.</span>
                </article>
            `;
            return;
        }

        gridRoot.innerHTML = libraries.map(renderLibrary).join('');
    }

    function setStatus(text, error) {
        status.classList.toggle('is-error', Boolean(error));
        status.innerHTML = '<i></i>' + escapeHtml(text);
    }

    async function loadLibraries() {
        const response = await fetch('/api/libraries.php', {
            headers: { Accept: 'application/json' },
            cache: 'no-store',
        });

        if (!response.ok) {
            throw new Error('Libraries request failed with HTTP ' + response.status);
        }

        const payload = await response.json();
        renderSummary(payload.summary);
        renderLibraries(payload.libraries);
        setStatus(payload.refreshedLabel || (payload.cached ? 'Cached library stats' : 'Live from Jellyfin'), false);
    }

    loadLibraries().catch(() => {
        setStatus('Could not load library stats', true);
        gridRoot.classList.remove('is-loading');
        gridRoot.innerHTML = `
            <article class="library-empty-state">
                <strong>Could not load Jellyfin libraries.</strong>
                <span>Try refreshing once Jellyfin responds again.</span>
            </article>
        `;
    });
}());
