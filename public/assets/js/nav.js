(function () {
    const shell = document.querySelector('.app-shell');
    const toggle = document.querySelector('[data-nav-toggle]');
    const sidebar = document.getElementById('dashboard-sidebar');
    const backdrop = document.querySelector('[data-nav-backdrop]');

    if (!shell || !toggle || !sidebar) {
        return;
    }

    const desktopQuery = window.matchMedia('(min-width: 901px)');

    function open() {
        shell.classList.add('nav-open');
        toggle.setAttribute('aria-expanded', 'true');
        if (backdrop) {
            backdrop.hidden = false;
        }
    }

    function close() {
        shell.classList.remove('nav-open');
        toggle.setAttribute('aria-expanded', 'false');
        if (backdrop) {
            backdrop.hidden = true;
        }
    }

    function isOpen() {
        return shell.classList.contains('nav-open');
    }

    toggle.addEventListener('click', function () {
        if (isOpen()) {
            close();
        } else {
            open();
        }
    });

    if (backdrop) {
        backdrop.addEventListener('click', close);
    }

    // Close the drawer after picking a destination.
    sidebar.addEventListener('click', function (event) {
        if (event.target.closest('a[href]')) {
            close();
        }
    });

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape' && isOpen()) {
            close();
            toggle.focus();
        }
    });

    // Never leave the drawer state stuck when resizing up to desktop.
    desktopQuery.addEventListener('change', function (event) {
        if (event.matches) {
            close();
        }
    });
})();
