(function () {
    const root = document.querySelector('[data-now-playing-root]');
    const label = document.querySelector('[data-live-label]');
    const dot = document.querySelector('[data-live-dot]');
    let hasLoaded = false;

    if (!root || !label || !dot) {
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

    function methodBadge(stream) {
        const isTranscode = Boolean(stream.isTranscode);
        return `
            <span class="method-badge ${isTranscode ? 'is-transcode' : 'is-direct'}">
                <i></i>
                <span>${escapeHtml(stream.methodLabel || (isTranscode ? 'Transcoding' : 'Direct Play'))}</span>
            </span>
        `;
    }

    function progress(stream, large) {
        return `
            <div class="progress-block ${large ? 'is-large' : ''}">
                <div class="progress-track"><span style="width: ${escapeAttr(stream.progressPct || '0%')}"></span></div>
                <div class="progress-labels">
                    <span>${escapeHtml(stream.timeLabel || '0:00 / 0:00')}</span>
                    <span>${escapeHtml(stream.remaining || '0 min left')}</span>
                </div>
            </div>
        `;
    }

    function watcher(stream, large) {
        const avatarUrl = stream.avatarUrl || '';
        const avatarClass = 'watcher-avatar' + (large ? ' is-large' : '');
        const img = avatarUrl
            ? `<img class="watcher-avatar-image" data-avatar-img src="${escapeAttr(avatarUrl)}" alt="">`
            : '';

        return `
            <div class="watcher-row ${large ? 'is-large' : ''}">
                <span class="${avatarClass}" style="background: ${escapeAttr(stream.avatarBg || '')}">${escapeHtml(stream.initials || 'U')}${img}</span>
                <span>
                    <strong>${escapeHtml(stream.user || 'Unknown user')}</strong>
                    <small>${escapeHtml(stream.deviceLine || '')}</small>
                </span>
            </div>
        `;
    }

    function card(stream) {
        return `
            <article class="stream-card">
                <div class="stream-backdrop" style="background-image: ${escapeAttr(stream.backdrop || '')}"></div>
                <div class="stream-card-overlay"></div>
                <div class="stream-watermark">${escapeHtml(stream.initials || '')}</div>

                <div class="stream-card-content">
                    <div class="stream-card-top">
                        <span class="now-pill ${stream.isPaused ? 'is-paused' : ''} ${stream.isLive && !stream.isPaused ? 'is-live-tv' : ''}"><i></i>${escapeHtml(stream.statusLabel || 'Now Playing')}</span>
                        <div class="playback-stack">
                            ${methodBadge(stream)}
                            <span class="quality-chip">${escapeHtml(stream.quality || '')}</span>
                        </div>
                    </div>

                    <div class="stream-card-spacer"></div>

                    <div>
                        <div class="kind-label">${escapeHtml(stream.kindLabel || '')}</div>
                        <h3>${escapeHtml(stream.title || 'Unknown title')}</h3>
                        <p>${escapeHtml(stream.subtitle || '')}</p>
                    </div>

                    ${watcher(stream, false)}
                    ${progress(stream, false)}
                </div>
            </article>
        `;
    }

    function emptyState() {
        return `
            <div class="empty-state">
                <div class="empty-orbit" aria-hidden="true">
                    <span></span>
                    <svg class="icon-filled" viewBox="0 0 24 24" role="img" focusable="false">
                        <path d="M6 4v16a1 1 0 0 0 1.524 .852l13 -8a1 1 0 0 0 0 -1.704l-13 -8a1 1 0 0 0 -1.524 .852z"></path>
                    </svg>
                </div>
                <h2>All quiet on the server</h2>
                <p>No active playback right now. Streams appear here the moment someone hits play.</p>
                <small><span class="status-dot"></span>listening for sessions...</small>
            </div>
        `;
    }

    function errorState() {
        return `
            <div class="empty-state">
                <div class="empty-orbit" aria-hidden="true">
                    <span></span>
                    <svg class="icon-filled" viewBox="0 0 24 24" role="img" focusable="false">
                        <path d="M12 1.67c.955 0 1.845 .467 2.39 1.247l.105 .16l8.114 13.548a2.914 2.914 0 0 1 -2.307 4.363l-.195 .008h-16.225a2.914 2.914 0 0 1 -2.582 -4.2l.099 -.185l8.11 -13.538a2.914 2.914 0 0 1 2.491 -1.403zm.01 13.33l-.127 .007a1 1 0 0 0 0 1.986l.117 .007l.127 -.007a1 1 0 0 0 0 -1.986l-.117 -.007zm-.01 -7a1 1 0 0 0 -.993 .883l-.007 .117v4l.007 .117a1 1 0 0 0 1.986 0l.007 -.117v-4l-.007 -.117a1 1 0 0 0 -.993 -.883z"></path>
                    </svg>
                </div>
                <h2>Could not load sessions</h2>
                <p>Jellyfin did not answer this request. Jellydash will keep trying automatically.</p>
                <small><span class="status-dot"></span>waiting for Jellyfin...</small>
            </div>
        `;
    }

    function renderStreams(payload) {
        const streams = Array.isArray(payload.streams) ? payload.streams : [];
        root.classList.toggle('has-streams', streams.length > 0);

        if (streams.length === 0) {
            root.innerHTML = emptyState();
            return;
        }

        const cards = streams.map(card).join('');

        root.innerHTML = `<div class="stream-grid">${cards}</div>`;
    }

    function setText(selector, value) {
        const element = document.querySelector(selector);
        if (element) {
            element.textContent = String(value);
            if (selector === '[data-nav-count]') {
                element.classList.remove('is-loading');
            }
        }
    }

    function updateStats(payload) {
        const stats = payload.stats || {};
        const activeStreams = Number(stats.active_streams || 0);
        const activeUsers = Number(stats.active_users || 0);

        label.textContent = activeStreams > 0
            ? activeStreams + ' ' + (activeStreams === 1 ? 'stream' : 'streams')
                + ' - ' + activeUsers + ' ' + (activeUsers === 1 ? 'user' : 'users')
            : 'No active sessions';
        dot.classList.toggle('is-live', activeStreams > 0);
        dot.classList.toggle('is-idle', activeStreams === 0);

        setText('[data-nav-count]', activeStreams);
        setText('[data-stat="watch_today"]', stats.watch_today || '0m');

        const activeBlock = document.querySelector('[data-stat-block="active_streams"]');
        const bandwidthBlock = document.querySelector('[data-stat-block="bandwidth"]');
        const transcodeBlock = document.querySelector('[data-stat-block="transcoding"]');

        if (activeStreams > 0) {
            if (activeBlock) {
                activeBlock.innerHTML = `<span>${activeStreams}</span> <small>${activeStreams === 1 ? 'stream' : 'streams'}</small>`;
            }
            if (bandwidthBlock) {
                bandwidthBlock.innerHTML = `<span>${escapeHtml(stats.bandwidth_mbps || '0.0')}</span> <small>Mbps</small>`;
            }
            if (transcodeBlock) {
                transcodeBlock.innerHTML = `<span>${Number(stats.transcodes || 0)}</span> <small>of ${activeStreams} streams</small>`;
            }
            return;
        }

        if (activeBlock) {
            activeBlock.innerHTML = '<span class="stat-placeholder">Idle</span>';
        }
        if (bandwidthBlock) {
            bandwidthBlock.innerHTML = '<span class="stat-placeholder">Idle</span>';
        }
        if (transcodeBlock) {
            transcodeBlock.innerHTML = '<span class="stat-placeholder">None</span>';
        }
    }

    function renderError() {
        if (hasLoaded) {
            return;
        }

        root.innerHTML = errorState();
        label.textContent = 'Could not load sessions';
        dot.classList.remove('is-live');
        dot.classList.add('is-idle');
        setText('[data-nav-count]', '-');

        ['active_streams', 'bandwidth', 'transcoding'].forEach((name) => {
            const block = document.querySelector(`[data-stat-block="${name}"]`);
            if (block) {
                block.innerHTML = '<span class="stat-placeholder">Unavailable</span>';
            }
        });
    }

    async function refreshNowPlaying() {
        const response = await fetch('/api/now-playing.php', {
            headers: { Accept: 'application/json' },
            cache: 'no-store',
        });

        if (!response.ok) {
            throw new Error('Now Playing request failed with HTTP ' + response.status);
        }

        const payload = await response.json();
        updateStats(payload);
        renderStreams(payload);
        hasLoaded = true;

        window.dispatchEvent(new CustomEvent('jellydash:now-playing', { detail: payload }));
    }

    refreshNowPlaying().catch(renderError);
    window.setInterval(() => {
        refreshNowPlaying().catch(renderError);
    }, 5000);
}());
