(function () {
    const root = document.querySelector('[data-now-playing-root]');
    const label = document.querySelector('[data-live-label]');
    const dot = document.querySelector('[data-live-dot]');
    let hasLoaded = false;
    let refreshInFlight = false;
    let authExpired = false;
    let authRendered = false;
    let pageSuspended = false;
    let resumePending = false;
    let activeController = null;
    let requestSequence = 0;
    let latestAppliedSequence = 0;
    let openDiagnosticsId = null;
    let lastSuccessfulRefresh = null;

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
                <div class="progress-track"><span data-progress-fill style="width: ${escapeAttr(stream.progressPct || '0%')}"></span></div>
                <div class="progress-labels">
                    <span data-progress-time>${escapeHtml(stream.timeLabel || '0:00 / Unknown')}</span>
                    <span data-progress-remaining>${escapeHtml(stream.remaining || 'Remaining time unknown')}</span>
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

    function transcodeDetails(stream) {
        const bitrate = stream.bitrateLabel
            ? `<strong class="stream-detail-rate">${escapeHtml(stream.bitrateLabel)}</strong>`
            : '';
        const output = stream.outputMediaLabel || 'Output unknown';
        const reasonCount = Array.isArray(stream.transcodeReasons) ? stream.transcodeReasons.length : 0;
        const reasonLabel = reasonCount === 1 ? 'reason' : 'reasons';

        return `
            <div class="stream-details stream-overlay-summary" aria-label="Transcode summary">
                ${bitrate}
                <span class="stream-overlay-output"><small>Output</small>${escapeHtml(output)}</span>
                <button class="stream-diagnostics-open" type="button" data-diagnostics-open aria-expanded="false" aria-controls="${escapeAttr(stream.diagnosticsId || '')}">
                    ${reasonCount} ${reasonLabel}
                    <svg class="icon-filled" viewBox="0 0 24 24" focusable="false" aria-hidden="true"><path d="M7 6l-.112 .006a1 1 0 0 0-.669 1.619l3.501 4.375l-3.5 4.375a1 1 0 0 0 .78 1.625h6a1 1 0 0 0 .78-.375l4-5a1 1 0 0 0 0-1.25l-4-5a1 1 0 0 0-.78-.375h-6z"></path></svg>
                </button>
            </div>
        `;
    }

    function transcodeDiagnostics(stream) {
        if (!stream.isTranscode) {
            return '';
        }

        const reasons = Array.isArray(stream.transcodeReasons) ? stream.transcodeReasons : [];
        const reasonMarkup = reasons.length > 0
            ? `
                <div class="stream-diagnostics-reasons">
                    <span class="stream-diagnostics-section-label">Why Jellyfin converted it</span>
                    <div class="stream-diagnostics-reason-list">
                        ${reasons.map((reason) => `<span class="stream-diagnostics-reason"><i></i>${escapeHtml(reason)}</span>`).join('')}
                    </div>
                </div>
            `
            : '';
        const bitrate = stream.bitrateLabel
            ? `<span>Output bitrate <strong>${escapeHtml(stream.bitrateLabel)}</strong></span>`
            : '';
        const viewer = [stream.user, stream.device].filter(Boolean).map(escapeHtml).join(' · ');

        return `
            <section class="stream-diagnostics-overlay" id="${escapeAttr(stream.diagnosticsId || '')}" data-diagnostics-overlay aria-hidden="true" aria-label="Transcode details for ${escapeAttr(stream.title || 'this stream')}" inert>
                <header class="stream-diagnostics-header">
                    <span class="stream-diagnostics-heading"><small>Video transcode</small><strong>${escapeHtml(stream.title || 'Unknown title')}</strong></span>
                    <button class="stream-diagnostics-close" type="button" data-diagnostics-close aria-label="Close transcode details">
                        <svg viewBox="0 0 24 24" focusable="false" aria-hidden="true"><path d="M6 6l12 12M18 6l-12 12"></path></svg>
                    </button>
                </header>
                <div class="stream-diagnostics-route">
                    <span class="stream-diagnostics-endpoint"><small>Source</small><strong>${escapeHtml(stream.sourceMediaLabel || 'Source unknown')}</strong></span>
                    <span class="stream-transcode-marker" aria-hidden="true">
                        <svg class="stream-transcode-icon icon-filled" viewBox="0 0 24 24" focusable="false"><path d="M7 6l-.112 .006a1 1 0 0 0-.669 1.619l3.501 4.375l-3.5 4.375a1 1 0 0 0 .78 1.625h6a1 1 0 0 0 .78-.375l4-5a1 1 0 0 0 0-1.25l-4-5a1 1 0 0 0-.78-.375h-6z"></path></svg>
                    </span>
                    <span class="stream-diagnostics-endpoint is-output"><small>Output</small><strong>${escapeHtml(stream.outputMediaLabel || 'Output unknown')}</strong></span>
                </div>
                ${reasonMarkup}
                <footer class="stream-diagnostics-footer">${bitrate}<span>${viewer}</span></footer>
            </section>
        `;
    }

    function streamDetails(stream) {
        if (stream.isTranscode) {
            return transcodeDetails(stream);
        }

        const details = [];

        if (stream.bitrateLabel) {
            details.push(`<strong class="stream-detail-rate">${escapeHtml(stream.bitrateLabel)}</strong>`);
        }
        if (stream.videoPath) {
            details.push(`<span class="stream-detail"><small>Video</small>${escapeHtml(stream.videoPath)}</span>`);
        }
        if (stream.audioPath) {
            details.push(`<span class="stream-detail"><small>Audio</small>${escapeHtml(stream.audioPath)}</span>`);
        }
        if (stream.containerPath) {
            details.push(`<span class="stream-detail"><small>Out</small>${escapeHtml(stream.containerPath)}</span>`);
        }
        return details.length > 0
            ? `<div class="stream-details" aria-label="Playback details">${details.join('')}</div>`
            : '';
    }

    function card(stream) {
        return `
            <article class="stream-card" data-stream-id="${escapeAttr(stream.id || '')}">
                <div class="stream-backdrop" style="background-image: ${escapeAttr(stream.backdrop || '')}"></div>
                <div class="stream-card-overlay"></div>
                <div class="stream-watermark">${escapeHtml(stream.initials || '')}</div>

                <div class="stream-card-content">
                    <div class="stream-card-top">
                        <span class="now-pill ${stream.isPaused ? 'is-paused' : ''} ${stream.isLive && !stream.isPaused ? 'is-live-tv' : ''}" data-stream-status><i></i>${escapeHtml(stream.statusLabel || 'Now Playing')}</span>
                        <div class="playback-stack">
                            ${methodBadge(stream)}
                            <span class="quality-chip">${escapeHtml(stream.quality || '')}</span>
                            ${stream.storage ? `<span class="quality-chip storage-chip">Storage: ${escapeHtml(stream.storage)}</span>` : ''}
                        </div>
                    </div>

                    <div class="stream-card-spacer"></div>

                    <div>
                        <div class="kind-label">${escapeHtml(stream.kindLabel || '')}</div>
                        <h3>${escapeHtml(stream.title || 'Unknown title')}</h3>
                        <p>${escapeHtml(stream.subtitle || '')}</p>
                    </div>

                    ${watcher(stream, false)}
                    ${streamDetails(stream)}
                    ${progress(stream, false)}
                    ${transcodeDiagnostics(stream)}
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
                <p>No active playback right now. New streams appear here automatically.</p>
                <small><span class="status-dot"></span>Checking for sessions...</small>
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

    function createCard(stream) {
        const template = document.createElement('template');
        template.innerHTML = card(stream).trim();
        return template.content.firstElementChild;
    }

    function updateProtectedCard(existing, replacement) {
        function updateNode(current, next) {
            if (current.nodeType !== next.nodeType || current.tagName !== next.tagName) {
                current.replaceWith(next);
                return;
            }
            if (current.nodeType !== 1) {
                if (current.nodeValue !== next.nodeValue) {
                    current.nodeValue = next.nodeValue;
                }
                return;
            }

            const interactionAttributes = current.hasAttribute('data-diagnostics-overlay')
                ? ['aria-hidden', 'inert']
                : (current.hasAttribute('data-diagnostics-open') ? ['aria-expanded', 'disabled'] : []);
            if (current.hasAttribute('data-avatar-img') && next.hasAttribute('data-avatar-img')) {
                interactionAttributes.push('data-avatar-bound');
            }
            if (current.classList.contains('watcher-avatar') && next.classList.contains('watcher-avatar')) {
                const currentImage = current.querySelector('[data-avatar-img]');
                const nextImage = next.querySelector('[data-avatar-img]');
                // Keep the loaded state only while the same avatar is retained.
                next.classList.toggle('has-image', Boolean(currentImage && nextImage
                    && currentImage.getAttribute('src') === nextImage.getAttribute('src')
                    && current.classList.contains('has-image')));
            }
            Array.from(current.attributes).forEach(({ name }) => {
                if (!interactionAttributes.includes(name) && !next.hasAttribute(name)) {
                    current.removeAttribute(name);
                }
            });
            Array.from(next.attributes).forEach(({ name, value }) => {
                if (!interactionAttributes.includes(name) && current.getAttribute(name) !== value) {
                    current.setAttribute(name, value);
                }
            });
            updateChildren(current, next);
        }

        function updateChildren(current, next) {
            const currentChildren = Array.from(current.childNodes);
            const nextChildren = Array.from(next.childNodes);
            const available = new Set(currentChildren);
            nextChildren.forEach((child, index) => {
                const match = currentChildren.find((candidate) => available.has(candidate)
                    && candidate.nodeType === child.nodeType
                    && candidate.tagName === child.tagName
                    && (candidate.nodeType !== 1
                        || candidate.getAttribute('class')?.split(' ')[0] === child.getAttribute('class')?.split(' ')[0]));
                const node = match || child;
                if (match) {
                    available.delete(match);
                    updateNode(match, child);
                }
                if (current.childNodes[index] !== node) {
                    const focused = document.activeElement;
                    current.insertBefore(node, current.childNodes[index] || null);
                    if (focused && node.contains(focused)) {
                        focused.focus();
                    }
                }
            });
            available.forEach((child) => child.remove());
        }

        updateChildren(existing, replacement);
    }

    function focusAfterRemoval() {
        const candidates = [
            ...root.querySelectorAll('[data-diagnostics-open]'),
            document.querySelector('[data-nav-toggle]'),
            document.querySelector('.dashboard-nav a'),
        ];
        const fallback = candidates.find((element) => element && !element.disabled
            && !element.closest?.('[inert]') && !element.closest?.('[hidden]')
            && (typeof element.getClientRects !== 'function' || element.getClientRects().length > 0));
        if (fallback && typeof fallback.focus === 'function') {
            fallback.focus();
        }
    }

    function reconcileStreams(streams) {
        let grid = root.querySelector('.stream-grid');
        if (!grid) {
            root.innerHTML = '<div class="stream-grid"></div>';
            grid = root.querySelector('.stream-grid');
        }

        const existingCards = new Map(Array.from(grid.querySelectorAll('[data-stream-id]'))
            .map((element) => [element.dataset.streamId, element]));
        const activeElement = document.activeElement;
        const selection = typeof window.getSelection === 'function' ? window.getSelection() : null;
        let needsFocusFallback = false;

        streams.forEach((stream, index) => {
            const key = String(stream.id || '');
            const replacement = createCard(stream);
            const existing = existingCards.get(key);
            if (!existing) {
                grid.insertBefore(replacement, grid.children[index] || null);
                return;
            }

            const containedFocus = activeElement && existing.contains(activeElement);
            const protectsInteraction = existing.classList.contains('is-diagnostics-open')
                || containedFocus
                || (selection && !selection.isCollapsed && (
                    (selection.anchorNode && existing.contains(selection.anchorNode))
                    || (selection.focusNode && existing.contains(selection.focusNode))
                ));
            if (protectsInteraction) {
                updateProtectedCard(existing, replacement);
            } else {
                existing.className = replacement.className;
                existing.innerHTML = replacement.innerHTML;
            }
            if (grid.children[index] !== existing) {
                grid.insertBefore(existing, grid.children[index] || null);
                if (activeElement && existing.contains(activeElement)) {
                    activeElement.focus();
                }
            }
            if (containedFocus && !existing.contains(activeElement)) {
                needsFocusFallback = true;
            }
            existingCards.delete(key);
        });

        existingCards.forEach((element) => {
            const containedFocus = activeElement && element.contains(activeElement);
            element.remove();
            if (containedFocus) {
                needsFocusFallback = true;
            }
        });
        if (needsFocusFallback) {
            focusAfterRemoval();
        }
    }

    function renderStreams(payload) {
        const streams = Array.isArray(payload.streams) ? payload.streams : [];
        root.classList.toggle('has-streams', streams.length > 0);

        if (streams.length === 0) {
            const containedFocus = document.activeElement && root.contains(document.activeElement);
            openDiagnosticsId = null;
            root.innerHTML = emptyState();
            if (containedFocus) {
                focusAfterRemoval();
            }
            return;
        }

        reconcileStreams(streams);

        if (openDiagnosticsId !== null) {
            const openCard = Array.from(root.querySelectorAll('[data-stream-id]'))
                .find((candidate) => candidate.dataset.streamId === openDiagnosticsId);

            if (openCard) {
                setDiagnosticsOpen(openCard, true, false);
            } else {
                openDiagnosticsId = null;
            }
        }
    }

    function setDiagnosticsOpen(cardElement, open, moveFocus = true) {
        const trigger = cardElement.querySelector('[data-diagnostics-open]');
        const overlay = cardElement.querySelector('[data-diagnostics-overlay]');
        const close = cardElement.querySelector('[data-diagnostics-close]');

        if (!trigger || !overlay || !close) {
            cardElement.classList.remove('is-diagnostics-open');
            openDiagnosticsId = null;
            return;
        }

        if (open) {
            root.querySelectorAll('.stream-card.is-diagnostics-open').forEach((openCard) => {
                if (openCard !== cardElement) {
                    setDiagnosticsOpen(openCard, false, false);
                }
            });
        }

        cardElement.classList.toggle('is-diagnostics-open', open);
        trigger.setAttribute('aria-expanded', String(open));
        trigger.disabled = open;
        overlay.setAttribute('aria-hidden', String(!open));
        if (open) {
            overlay.inert = false;
        }
        openDiagnosticsId = open ? (cardElement.dataset.streamId || null) : null;

        if (moveFocus) {
            (open ? close : trigger).focus();
        }
        overlay.inert = !open;
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
        dot.classList.remove('is-stale');
        root.classList.remove('is-stale');

        setText('[data-nav-count]', activeStreams);
        setText('[data-stat="watch_today"]', stats.watch_today_available === false ? 'Unavailable' : (stats.watch_today || 'Unavailable'));

        const collectionStatus = document.querySelector('[data-collection-status]');
        if (collectionStatus) {
            const degraded = stats.collection_status === 'degraded';
            const metadataUnavailable = stats.metadata_available === false;
            collectionStatus.hidden = !degraded && !metadataUnavailable;
            collectionStatus.textContent = degraded
                ? 'Playback history collection is delayed.'
                : (metadataUnavailable ? 'Some playback details are unavailable.' : '');
        }

        const bandwidthBlock = document.querySelector('[data-stat-block="bandwidth"]');
        const transcodeBlock = document.querySelector('[data-stat-block="transcoding"]');

        if (activeStreams > 0) {
            if (bandwidthBlock) {
                bandwidthBlock.innerHTML = `<span>${escapeHtml(stats.bandwidth_mbps || '0.0')}</span><small>Mbps</small>`;
            }
            if (transcodeBlock) {
                transcodeBlock.innerHTML = `<span>${Number(stats.transcodes || 0)}</span><small>/ ${activeStreams}</small>`;
            }
            return;
        }

        if (bandwidthBlock) {
            bandwidthBlock.innerHTML = '<span class="stat-placeholder">Idle</span>';
        }
        if (transcodeBlock) {
            transcodeBlock.innerHTML = '<span class="stat-placeholder">None</span>';
        }
    }

    function renderError(error) {
        const expired = error && (error.status === 401 || error.status === 403);
        if (expired) {
            if (authRendered) return;
            authRendered = true;
            root.classList.add('is-stale');
            dot.classList.remove('is-live', 'is-idle');
            dot.classList.add('is-stale');
            label.textContent = 'Session expired. ';
            const link = document.createElement('a');
            link.href = '/login';
            link.textContent = 'Sign in again';
            label.appendChild(link);
            setText('[data-nav-count]', '-');
            return;
        }
        if (hasLoaded) {
            root.classList.add('is-stale');
            dot.classList.remove('is-live', 'is-idle');
            dot.classList.add('is-stale');
            label.textContent = 'Updates paused' + (lastSuccessfulRefresh ? ' since ' + lastSuccessfulRefresh : '') + '.';
            setText('[data-nav-count]', '-');
            return;
        }

        root.innerHTML = errorState();
        label.textContent = 'Could not load sessions';
        dot.classList.remove('is-live');
        dot.classList.add('is-idle');
        setText('[data-nav-count]', '-');

        ['bandwidth', 'transcoding'].forEach((name) => {
            const block = document.querySelector(`[data-stat-block="${name}"]`);
            if (block) {
                block.innerHTML = '<span class="stat-placeholder">Unavailable</span>';
            }
        });
    }

    function resume() {
        pageSuspended = false;
        if (refreshInFlight) resumePending = true;
        else refreshNowPlaying().catch(renderError);
    }

    async function refreshNowPlaying() {
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
                const error = new Error('Now Playing request failed with HTTP ' + response.status);
                error.status = response.status;
                throw error;
            }

            const payload = await response.json();
            if (sequence !== requestSequence || sequence < latestAppliedSequence) {
                return;
            }
            latestAppliedSequence = sequence;
            updateStats(payload);
            renderStreams(payload);
            hasLoaded = true;
            lastSuccessfulRefresh = new Date().toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });

            window.dispatchEvent(new CustomEvent('jellydash:now-playing', { detail: payload }));
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
            if (resumePending) { resumePending = false; refreshNowPlaying().catch(renderError); }
        }
    }

    if (window.JellydashFrontendTestHooks) {
        window.JellydashFrontendTestHooks.refreshNowPlaying = refreshNowPlaying;
        window.JellydashFrontendTestHooks.renderNowPlayingError = renderError;
    }

    root.addEventListener('click', (event) => {
        const target = event.target instanceof Element ? event.target : null;
        const trigger = target?.closest('[data-diagnostics-open]');
        const close = target?.closest('[data-diagnostics-close]');
        const control = trigger || close;

        if (!control) {
            return;
        }

        const cardElement = control.closest('[data-stream-id]');
        if (cardElement) {
            setDiagnosticsOpen(cardElement, Boolean(trigger));
        }
    });

    document.addEventListener('keydown', (event) => {
        if (event.key !== 'Escape' || openDiagnosticsId === null) {
            return;
        }

        const openCard = Array.from(root.querySelectorAll('[data-stream-id]'))
            .find((candidate) => candidate.dataset.streamId === openDiagnosticsId);
        if (openCard) {
            setDiagnosticsOpen(openCard, false);
        }
    });

    refreshNowPlaying().catch(renderError);
    window.setInterval(() => {
        refreshNowPlaying().catch(renderError);
    }, 5000);
    document.addEventListener('visibilitychange', () => {
        if (document.hidden) { ++requestSequence; activeController?.abort(); }
        else {
            resume();
        }
    });
    if (window.addEventListener) {
        window.addEventListener('pagehide', () => { pageSuspended = true; ++requestSequence; activeController?.abort(); });
        window.addEventListener('pageshow', resume);
    }
}());
