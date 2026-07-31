// Registers the service worker so the app is installable as a standalone PWA
// (kept in an external file because the page CSP blocks inline scripts).
if ('serviceWorker' in navigator) {
    window.addEventListener('load', function () {
        navigator.serviceWorker.register('/sw.js').catch(function () {});
    });
}
