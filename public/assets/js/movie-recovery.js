(function () {
    'use strict';

    var panel = document.querySelector('[data-movie-recovery]');
    var notice = document.querySelector('[data-movie-recovery-notice]');
    var csrf = document.querySelector('meta[name="csrf-token"]');
    if (!csrf || !csrf.content) return;
    var page = 1;
    var pages = 1;
    var timer = null;
    var queue = Promise.resolve();
    var generation = 0;
    var acting = false;
    var stopped = false;
    var suspended = false;
    var lastRendered = '';

    function find(name) { return panel && panel.querySelector('[data-movie-recovery-' + name + ']'); }
    function node(tag, text, className) {
        var element = document.createElement(tag);
        if (text) element.textContent = text;
        if (className) element.className = className;
        return element;
    }
    function actionButton(label, action, sourceId, targetId) {
        var button = node('button', label, 'settings-secondary-btn');
        button.type = 'button';
        button.dataset.recoveryAction = action;
        button.dataset.sourceId = sourceId;
        if (targetId) button.dataset.targetId = targetId;
        return button;
    }
    function request(action, data) {
        var controller = new AbortController();
        var timeout = window.setTimeout(function () { controller.abort(); }, 25000);
        var options = { credentials: 'same-origin', cache: 'no-store', signal: controller.signal };
        if (action) {
            options.method = 'POST';
            options.headers = { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf.content };
            options.body = JSON.stringify(Object.assign({ action: action }, data || {}));
        }
        return fetch('/api/movie-recovery.php?page=' + page, options).then(function (response) {
            if (response.status === 401 || response.status === 403 || response.status === 419) stopped = true;
            return response.json().then(function (body) {
                if (!response.ok) throw new Error(body.error || 'Movie replacements could not be checked.');
                if (!body || typeof body.state !== 'string' || !Array.isArray(body.suggestions)) {
                    throw new Error('Movie replacements returned an incomplete response.');
                }
                return body;
            });
        }).finally(function () { window.clearTimeout(timeout); });
    }
    function enqueue(action, data) {
        var result = queue.then(function () { return request(action, data); });
        queue = result.catch(function () {});
        return result;
    }
    function render(data, force) {
        if (notice) {
            notice.hidden = !data.suggestionCount;
            notice.textContent = data.suggestionCount === 1
                ? 'A removed movie has a possible replacement. Review it in Settings.'
                : data.suggestionCount + ' removed movies have possible replacements. Review them in Settings.';
        }
        if (!panel) return;
        find('status').textContent = data.message || 'Movie replacement checks run automatically.';
        var signature = JSON.stringify([data.suggestions, data.repairs, data.dismissed, data.page, data.pages]);
        var focusedList = ['suggestions', 'repairs', 'dismissed'].some(function (name) {
            return find(name).contains(document.activeElement);
        });
        if (!force && (signature === lastRendered || focusedList)) return;
        lastRendered = signature;
        var suggestions = find('suggestions');
        suggestions.replaceChildren();
        data.suggestions.forEach(function (item) {
            var card = node('article', '', 'movie-recovery-item');
            card.appendChild(node('h3', item.sourceTitle || 'Removed movie'));
            card.appendChild(node('p', item.plays + (item.plays === 1 ? ' saved play. Choose the same movie to reconnect it.' : ' saved plays. Choose the same movie to reconnect them.')));
            item.candidates.forEach(function (candidate) {
                var row = node('div', '', 'movie-recovery-candidate');
                var poster = node('img');
                poster.alt = '';
                poster.loading = 'lazy';
                poster.src = '/api/image.php?item=' + encodeURIComponent(candidate.id) + '&type=Primary&maxWidth=120';
                row.appendChild(poster);
                var copy = node('div');
                copy.appendChild(node('strong', candidate.title));
                if (candidate.year) copy.appendChild(node('small', String(candidate.year)));
                var button = actionButton('Use this movie', 'confirm', item.sourceId, candidate.id);
                button.setAttribute('aria-label', 'Reconnect ' + (item.sourceTitle || 'removed movie') + ' to ' + candidate.title + (candidate.year ? ' (' + candidate.year + ')' : ''));
                copy.appendChild(button);
                row.appendChild(copy);
                card.appendChild(row);
            });
            card.appendChild(actionButton('Keep separate', 'dismiss', item.sourceId));
            suggestions.appendChild(card);
        });
        var repairs = find('repairs');
        repairs.replaceChildren();
        (data.repairs || []).forEach(function (item) {
            var card = node('article', '', 'movie-recovery-item');
            card.appendChild(node('h3', item.targetTitle || item.sourceTitle));
            card.appendChild(node('p', item.automatic ? 'Reconnected automatically. Undo keeps these entries separate until you choose to check them again.' : 'Reconnected after confirmation. Every original play is still saved.'));
            card.appendChild(actionButton('Undo', 'undo', item.sourceId));
            repairs.appendChild(card);
        });
        find('repairs-wrap').hidden = !data.repairCount;
        find('repairs-label').textContent = 'Reconnected movies (' + (data.repairCount || 0) + ')';
        var dismissed = find('dismissed');
        dismissed.replaceChildren();
        (data.dismissed || []).forEach(function (item) {
            var card = node('article', '', 'movie-recovery-item');
            card.appendChild(node('h3', item.sourceTitle || 'Removed movie'));
            card.appendChild(actionButton('Check again', 'resume', item.sourceId));
            dismissed.appendChild(card);
        });
        find('dismissed-wrap').hidden = !data.dismissedCount;
        find('dismissed-label').textContent = 'Kept separate (' + (data.dismissedCount || 0) + ')';
        page = data.page || 1;
        pages = data.pages || 1;
        find('pager').hidden = data.pages <= 1;
        find('page').textContent = 'Page ' + page + ' of ' + (data.pages || 1);
        find('prev').disabled = page <= 1;
        find('next').disabled = page >= data.pages;
    }
    function showError(error) {
        if (!panel) return;
        var message = find('error');
        message.hidden = false;
        message.textContent = error.name === 'AbortError' ? 'The check took too long. It will retry automatically.' : error.message;
    }
    function schedule(delay) {
        window.clearTimeout(timer);
        if (!stopped && !suspended) timer = window.setTimeout(tick, delay);
    }
    function tick() {
        if (document.hidden || acting) { schedule(15000); return; }
        var revision = generation;
        enqueue('check').then(function (data) {
            if (revision !== generation) return;
            if (panel) find('error').hidden = true;
            render(data, false);
            schedule(['scanning', 'recovering', 'pending', 'checking'].indexOf(data.state) >= 0 ? 4000 : 60000);
        }).catch(function (error) {
            if (revision !== generation) return;
            showError(error);
            schedule(60000);
        });
    }
    if (panel) panel.addEventListener('click', function (event) {
        var button = event.target.closest('button');
        if (!button || !panel.contains(button) || acting) return;
        var action = button.dataset.recoveryAction;
        if (!action && button !== find('refresh') && button !== find('prev') && button !== find('next')) return;
        var previousPage = page;
        if (button === find('prev')) page = Math.max(1, page - 1);
        if (button === find('next')) page += 1;
        acting = true;
        generation += 1;
        window.clearTimeout(timer);
        button.disabled = true;
        find('error').hidden = true;
        enqueue(action || (button === find('refresh') ? 'check' : null), { sourceId: button.dataset.sourceId, targetId: button.dataset.targetId }).then(function (data) {
            render(data, true);
            if (action) document.getElementById('movie-recovery-title').focus({ preventScroll: true });
        }).catch(function (error) {
            page = previousPage;
            showError(error);
        }).finally(function () {
            acting = false;
            if (button.isConnected && button !== find('prev') && button !== find('next')) button.disabled = false;
            find('prev').disabled = page <= 1;
            find('next').disabled = page >= pages;
            schedule(4000);
        });
    });
    window.addEventListener('pagehide', function () { suspended = true; window.clearTimeout(timer); });
    window.addEventListener('pageshow', function (event) {
        if (!event.persisted) return;
        suspended = false;
        schedule(1500);
    });
    schedule(1500);
}());
