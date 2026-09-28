(function () {
    var csrfMeta = document.querySelector('meta[name="csrf-token"]');
    var csrfToken = csrfMeta ? csrfMeta.content : '';

    document.querySelectorAll('[data-health-action]').forEach(function (btn) {
        var originalLabel = btn.textContent;
        btn.addEventListener('click', function () {
            var action = btn.getAttribute('data-health-action');
            var id = btn.getAttribute('data-health-id');
            // Immediate feedback: some tasks finish in well under a second,
            // so without this a click can look like nothing happened.
            btn.disabled = true;
            btn.textContent = action === 'trigger' ? 'Starting…' : 'Stopping…';
            fetch('/api/module.php?m=server-health', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                    'X-CSRF-Token': csrfToken,
                },
                body: 'action=' + encodeURIComponent(action) + '&id=' + encodeURIComponent(id),
            }).then(function (response) {
                return response.ok ? response.json() : { ok: false };
            }).then(function (data) {
                if (data.ok === true) {
                    btn.textContent = 'Done';
                    setTimeout(function () { location.reload(); }, 400);
                } else {
                    btn.disabled = false;
                    btn.textContent = originalLabel;
                    alert('Task action failed.');
                }
            }).catch(function () {
                btn.disabled = false;
                btn.textContent = originalLabel;
                alert('Task action failed.');
            });
        });
    });
})();
