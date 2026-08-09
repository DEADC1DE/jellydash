// Playback push notifications: wires every [data-push-toggle] control (the
// header bell + the sidebar "Notifications" row) to subscribe/unsubscribe the
// browser to Web Push. Kept external because the page CSP blocks inline scripts.
(function () {
    'use strict';

    var meta = document.querySelector('meta[name="vapid-public-key"]');
    var csrfMeta = document.querySelector('meta[name="csrf-token"]');
    var toggles = Array.prototype.slice.call(document.querySelectorAll('[data-push-toggle]'));
    if (!meta || !meta.content || !csrfMeta || !csrfMeta.content || toggles.length === 0) {
        return; // Server hasn't configured VAPID, or nothing to wire up.
    }

    var VAPID_KEY = meta.content;
    var CSRF_TOKEN = csrfMeta.content;
    var supported = 'serviceWorker' in navigator && 'PushManager' in window && 'Notification' in window;
    var busy = false;

    function urlBase64ToUint8Array(base64String) {
        var padding = '='.repeat((4 - (base64String.length % 4)) % 4);
        var base64 = (base64String + padding).replace(/-/g, '+').replace(/_/g, '/');
        var raw = window.atob(base64);
        var output = new Uint8Array(raw.length);
        for (var i = 0; i < raw.length; ++i) {
            output[i] = raw.charCodeAt(i);
        }
        return output;
    }

    var LABELS = {
        on: 'On',
        off: 'Off',
        working: 'Working…',
        blocked: 'Blocked in browser',
        unsupported: 'Not supported'
    };

    function setState(state) {
        toggles.forEach(function (el) {
            el.hidden = false;
            var isOn = state === 'on';
            el.classList.toggle('is-on', isOn);
            el.setAttribute('aria-pressed', isOn ? 'true' : 'false');
            el.disabled = state === 'working' || state === 'blocked' || state === 'unsupported';
            var label = el.querySelector('[data-push-state]');
            if (label) {
                label.textContent = LABELS[state] || '';
            }
            if (state === 'blocked') {
                el.title = 'Notifications are blocked for this site in your browser settings.';
            } else {
                el.removeAttribute('title');
            }
        });
    }

    function postJson(url, body) {
        return fetch(url, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': CSRF_TOKEN
            },
            credentials: 'same-origin',
            body: body ? JSON.stringify(body) : null
        });
    }

    function subscribe() {
        setState('working');
        return Notification.requestPermission().then(function (permission) {
            if (permission !== 'granted') {
                setState(permission === 'denied' ? 'blocked' : 'off');
                return;
            }
            return navigator.serviceWorker.ready.then(function (reg) {
                return reg.pushManager.getSubscription().then(function (existing) {
                    return existing || reg.pushManager.subscribe({
                        userVisibleOnly: true,
                        applicationServerKey: urlBase64ToUint8Array(VAPID_KEY)
                    });
                });
            }).then(function (sub) {
                return postJson('/api/push/subscribe.php', sub).then(function (res) {
                    if (!res.ok) {
                        throw new Error('storing the subscription failed with HTTP ' + res.status);
                    }
                    setState('on');
                    // Immediate confirmation so the user knows it works.
                    postJson('/api/push/test.php').catch(function () {});
                });
            }).catch(function (err) {
                // Surface the real reason (push-service errors differ per
                // browser/OS) instead of silently snapping back to off.
                console.warn('[jellydash] enabling notifications failed:', err && err.name, err && err.message);
                setState('off');
            });
        });
    }

    function unsubscribe() {
        setState('working');
        return navigator.serviceWorker.ready.then(function (reg) {
            return reg.pushManager.getSubscription();
        }).then(function (sub) {
            if (!sub) {
                setState('off');
                return;
            }
            var endpoint = sub.endpoint;
            return sub.unsubscribe().then(function () {
                return postJson('/api/push/unsubscribe.php', { endpoint: endpoint }).catch(function () {});
            }).then(function () {
                setState('off');
            });
        }).catch(function (err) {
            console.warn('[jellydash] disabling notifications failed:', err && err.name, err && err.message);
            setState('off');
        });
    }

    function onToggle() {
        if (busy) {
            return;
        }
        busy = true;
        var isOn = this.classList.contains('is-on');
        (isOn ? unsubscribe() : subscribe()).then(function () {
            busy = false;
        }, function () {
            busy = false;
        });
    }

    if (!supported) {
        // Reveal only the labelled row so the user sees *why*; a bare disabled
        // bell in the header would just be confusing.
        toggles.forEach(function (el) {
            if (el.querySelector('[data-push-state]')) {
                el.hidden = false;
                el.disabled = true;
                el.querySelector('[data-push-state]').textContent = LABELS.unsupported;
            }
        });
        return;
    }

    toggles.forEach(function (el) {
        el.addEventListener('click', onToggle);
    });

    // Reflect the current subscription state on load.
    if (Notification.permission === 'denied') {
        setState('blocked');
    } else {
        navigator.serviceWorker.ready.then(function (reg) {
            return reg.pushManager.getSubscription();
        }).then(function (sub) {
            setState(sub ? 'on' : 'off');
        }).catch(function () {
            setState('off');
        });
    }
})();
