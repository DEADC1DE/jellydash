(function () {
    function init() {
        var page = document.querySelector('[data-now-playing-page]');
        if (!page) {
            return; // Not on Now Playing — nothing to do.
        }

        var csrfMeta = document.querySelector('meta[name="csrf-token"]');
        var csrfToken = csrfMeta ? csrfMeta.content : '';

        // Set once a 403 is seen — no client-side role check exists (the
        // module can't know the role without a core edit), so a non-admin
        // sees the buttons until their first click 403s. After that, stop
        // showing the confusing error and remove the control bars for good.
        var forbidden = false;

        function hideControlsPermanently() {
            forbidden = true;
            document.querySelectorAll('[data-session-control]').forEach(function (bar) {
                bar.remove();
            });
            observer.disconnect();
        }

        function sendAction(action, sessionId, button) {
            button.disabled = true;
            fetch('/api/module.php?m=session-control', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                    'X-CSRF-Token': csrfToken,
                },
                body: 'action=' + encodeURIComponent(action) + '&sessionId=' + encodeURIComponent(sessionId),
            }).then(function (response) {
                if (response.status === 403) {
                    hideControlsPermanently();
                    return null;
                }
                if (!response.ok) {
                    button.disabled = false;
                    alert('Could not ' + action + ' this session.');
                    return null;
                }
                return response.json();
            }).then(function (data) {
                button.disabled = false;
                if (data && data.ok === false) {
                    alert('Could not ' + action + ' this session.');
                }
            }).catch(function () {
                button.disabled = false;
                alert('Could not ' + action + ' this session.');
            });
        }

        function decorate(card) {
            if (card.querySelector('[data-session-control]')) {
                return; // already decorated
            }
            var sessionId = card.getAttribute('data-stream-id');
            if (!sessionId) {
                return;
            }

            var bar = document.createElement('div');
            bar.setAttribute('data-session-control', '');
            bar.className = 'session-control-bar';

            var stopBtn = document.createElement('button');
            stopBtn.type = 'button';
            stopBtn.className = 'session-control-btn';
            stopBtn.textContent = 'Stop';
            stopBtn.addEventListener('click', function () { sendAction('stop', sessionId, stopBtn); });

            var kickBtn = document.createElement('button');
            kickBtn.type = 'button';
            kickBtn.className = 'session-control-btn session-control-btn-danger';
            kickBtn.textContent = 'Kick user';
            kickBtn.addEventListener('click', function () {
                if (confirm('Sign this user out on this device?')) {
                    sendAction('kick', sessionId, kickBtn);
                }
            });

            bar.appendChild(stopBtn);
            bar.appendChild(kickBtn);
            // Append inside .stream-card-content (the padded flex column that
            // holds all real card content) rather than the outer .stream-card
            // (which has no padding of its own and clips with overflow:hidden)
            // — otherwise the bar sits flush against the raw card edges.
            var content = card.querySelector('.stream-card-content');
            (content || card).appendChild(bar);
        }

        function decorateAll() {
            if (forbidden) {
                return;
            }
            document.querySelectorAll('.stream-card[data-stream-id]').forEach(decorate);
        }

        // Now Playing re-renders cards on its own poll cycle — a light
        // MutationObserver keeps the buttons attached without touching core JS.
        var observer = new MutationObserver(decorateAll);
        decorateAll();
        observer.observe(page, { childList: true, subtree: true });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
