(function () {
    var form = document.querySelector('.activity-filters');
    if (form) {
        var input = form.querySelector('.activity-search');
        var select = form.querySelector('.activity-range');
        var reset = form.querySelector('.activity-reset');
        var timer = null;

        var apply = function () {
            var list = document.querySelector('.activity-list');
            var params = new URLSearchParams();
            var query = input.value.trim();
            if (query !== '') { params.set('q', query); }
            if (select.value !== '') { params.set('range', select.value); }
            var qs = params.toString();
            var url = qs !== '' ? '/activity?' + qs : '/activity';

            if (list) { list.classList.add('is-loading'); }

            fetch(url).then(function (response) {
                return response.ok ? response.text() : null;
            }).then(function (html) {
                list = document.querySelector('.activity-list');
                if (list) { list.classList.remove('is-loading'); }
                if (html === null) { return; }

                var doc = new DOMParser().parseFromString(html, 'text/html');
                var freshList = doc.querySelector('.activity-list');
                var freshPager = doc.querySelector('.activity-pager');
                var freshNote = doc.querySelector('.activity-truncated');

                // The truncation note must be handled while the old list is
                // still attached — it anchors next to the list in the DOM.
                var currentNote = document.querySelector('.activity-truncated');
                var anchor = document.querySelector('.activity-list');
                if (freshNote && !currentNote && anchor) { anchor.parentNode.insertBefore(freshNote, anchor); }
                else if (freshNote && currentNote) { currentNote.replaceWith(freshNote); }
                else if (!freshNote && currentNote) { currentNote.remove(); }

                if (freshList) { document.querySelector('.activity-list').replaceWith(freshList); }
                var currentPager = document.querySelector('.activity-pager');
                if (freshPager && currentPager) { currentPager.replaceWith(freshPager); }

                var freshHeader = doc.querySelector('.header-copy p');
                var header = document.querySelector('.header-copy p');
                if (freshHeader && header) { header.textContent = freshHeader.textContent; }

                if (reset) { reset.hidden = query === '' && select.value === ''; }

                window.history.replaceState({}, '', url);
            }).catch(function () {
                list = document.querySelector('.activity-list');
                if (list) { list.classList.remove('is-loading'); }
            });
        };

        input.addEventListener('input', function () {
            window.clearTimeout(timer);
            timer = window.setTimeout(apply, 450);
        });

        select.addEventListener('change', function () {
            window.clearTimeout(timer);
            apply();
        });

        form.addEventListener('submit', function (event) {
            event.preventDefault();
            window.clearTimeout(timer);
            apply();
        });
    }
})();

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
