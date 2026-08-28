(function () {
    var btn = document.querySelector('[data-activity-resolve]');
    if (!btn) { return; }

    var csrfMeta = document.querySelector('meta[name="csrf-token"]');
    var csrfToken = csrfMeta ? csrfMeta.content : '';

    btn.addEventListener('click', function () {
        btn.disabled = true;
        fetch('/api/module.php?m=activity-feed', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
                'X-CSRF-Token': csrfToken,
            },
            body: 'action=resolve-ghosts',
        }).then(function (response) { return response.json(); }).then(function (data) {
            btn.disabled = false;
            if (data.ok) {
                var count = Object.keys(data.resolved || {}).length;
                alert(count + ' user(s) resolved.');
            } else {
                alert(data.error || 'Could not resolve ghost users.');
            }
        }).catch(function () {
            btn.disabled = false;
            alert('Could not resolve ghost users.');
        });
    });
})();
