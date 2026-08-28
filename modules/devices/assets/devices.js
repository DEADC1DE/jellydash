(function () {
    var csrfMeta = document.querySelector('meta[name="csrf-token"]');
    var csrfToken = csrfMeta ? csrfMeta.content : '';

    document.querySelectorAll('[data-devices-delete]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var id = btn.getAttribute('data-devices-delete');
            if (!confirm('Delete this device? It will need to re-authenticate.')) {
                return;
            }
            fetch('/api/module.php?m=devices', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                    'X-CSRF-Token': csrfToken,
                },
                body: 'action=delete&id=' + encodeURIComponent(id),
            }).then(function (response) {
                return response.ok ? response.json() : { ok: false };
            }).then(function (data) {
                if (data.ok === true) {
                    var row = document.querySelector('[data-device-row="' + id + '"]');
                    if (row) { row.remove(); }
                } else {
                    alert('Could not delete device.');
                }
            }).catch(function () {
                alert('Could not delete device.');
            });
        });
    });
})();
