<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="local-fixture">
    <link rel="stylesheet" href="/public/assets/css/dashboard.css">
    <title>History upgrade local check</title>
</head>
<body>
    <main style="max-width: 45rem; margin: 3rem auto; padding: 1rem">
        <h1>History upgrade local check</h1>
        <p>The response button completes one pending local request. No server or notification service is contacted.</p>
        <button id="resolve-batch" type="button">Complete pending batch</button>
        <p id="request-count" aria-live="polite">Batch requests: 0</p>
    </main>
    <?php readfile(dirname(__DIR__, 2) . '/templates/_history_library_upgrade_dialog.twig'); ?>
    <script>
        (function () {
            var pending = [];
            var count = 0;
            window.fetch = function (url, options) {
                if (url !== '/api/history-library-upgrade.php') {
                    return Promise.reject(new Error('Unexpected local request.'));
                }
                if (options.method === 'GET') {
                    return Promise.resolve({ ok: true, json: function () {
                        return Promise.resolve({ required: true, state: 'pending', total: 3, processed: 0, percent: 0 });
                    } });
                }
                count += 1;
                document.getElementById('request-count').textContent = 'Batch requests: ' + count;
                return new Promise(function (resolve) { pending.push(resolve); });
            };
            document.getElementById('resolve-batch').addEventListener('click', function () {
                var resolve = pending.shift();
                if (resolve) {
                    resolve({ ok: true, json: function () {
                        return Promise.resolve({ required: true, state: 'running', total: 3, processed: 1, percent: 33 });
                    } });
                }
            });
        }());
    </script>
    <script src="/public/assets/js/history-library-upgrade.js"></script>
</body>
</html>
