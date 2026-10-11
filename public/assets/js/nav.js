(function () {
    const shell = document.querySelector('.app-shell');
    const toggle = document.querySelector('[data-nav-toggle]');
    const sidebar = document.getElementById('dashboard-sidebar');
    const backdrop = document.querySelector('[data-nav-backdrop]');

    if (!shell || !toggle || !sidebar) {
        return;
    }

    const desktopQuery = window.matchMedia('(min-width: 901px)');
    const originalAttributes = new Map(['role', 'aria-modal', 'aria-label', 'aria-hidden'].map(function (name) {
        return [name, sidebar.getAttribute(name)];
    }));
    const originalInert = sidebar.inert;
    let backgroundState = [];
    let lastFocusedElement = document.activeElement;
    const dialogOpeners = new WeakMap();

    function restoreAttributes() {
        originalAttributes.forEach(function (value, name) {
            if (value === null) {
                sidebar.removeAttribute(name);
            } else {
                sidebar.setAttribute(name, value);
            }
        });
    }

    function focusableItems() {
        return Array.from(sidebar.querySelectorAll('a[href], button, input, select, textarea, [tabindex]')).filter(function (element) {
            return element.tabIndex >= 0 && !element.disabled
                && !element.closest('[hidden], [inert], [aria-hidden="true"]')
                && element.getClientRects().length > 0;
        });
    }

    function isOpen() {
        return shell.classList.contains('nav-open');
    }

    function open() {
        if (desktopQuery.matches || isOpen()) {
            return;
        }

        sidebar.inert = false;
        sidebar.setAttribute('aria-hidden', 'false');
        sidebar.setAttribute('role', 'dialog');
        sidebar.setAttribute('aria-modal', 'true');
        sidebar.setAttribute('aria-label', originalAttributes.get('aria-label') || 'Navigation menu');
        shell.classList.add('nav-open');
        toggle.setAttribute('aria-expanded', 'true');
        if (backdrop) {
            backdrop.hidden = false;
        }
        const firstItem = focusableItems()[0];
        if (firstItem) {
            firstItem.focus();
        }

        // Move focus first, then disable the page behind the drawer.
        backgroundState = Array.from(shell.children).filter(function (element) {
            return element !== sidebar && element !== backdrop && !element.contains(document.activeElement);
        }).map(function (element) {
            const state = { element: element, inert: element.inert };
            element.inert = true;
            return state;
        });
    }

    function close() {
        const wasOpen = isOpen();
        backgroundState.forEach(function (state) {
            state.element.inert = state.inert;
        });
        backgroundState = [];
        shell.classList.remove('nav-open');
        toggle.setAttribute('aria-expanded', 'false');
        if (backdrop) {
            backdrop.hidden = true;
        }

        if (!desktopQuery.matches && (sidebar.contains(document.activeElement) || wasOpen)) {
            // Do not leave focus in the drawer when it becomes inert.
            if (!document.activeElement || !document.activeElement.closest('dialog[open]')) {
                toggle.focus();
            }
        }

        restoreAttributes();
        sidebar.inert = desktopQuery.matches ? originalInert : true;
        if (!desktopQuery.matches) {
            sidebar.setAttribute('aria-hidden', 'true');
        }
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

    sidebar.addEventListener('click', function (event) {
        if (event.target.closest('a[href]')) {
            close();
        }
    });

    document.addEventListener('focusin', function (event) {
        const dialog = event.target.closest('dialog[open]');
        if (dialog && (!lastFocusedElement || !dialog.contains(lastFocusedElement))) {
            dialogOpeners.set(dialog, lastFocusedElement);
        }
        lastFocusedElement = event.target;
        if (isOpen() && !sidebar.contains(event.target) && !dialog) {
            const firstItem = focusableItems()[0];
            if (firstItem) {
                firstItem.focus();
            }
        }
    });

    // Native restoration cannot reach a sidebar opener hidden by a phone resize.
    document.addEventListener('close', function (event) {
        const opener = dialogOpeners.get(event.target);
        dialogOpeners.delete(event.target);
        if (!desktopQuery.matches && sidebar.inert && opener && sidebar.contains(opener)
            && (document.activeElement === document.body || event.target.contains(document.activeElement))) {
            toggle.focus();
        }
    }, true);

    document.addEventListener('keydown', function (event) {
        if (!isOpen() || (event.target && event.target.closest('dialog[open]'))) {
            return;
        }
        if (event.key === 'Escape') {
            event.preventDefault();
            close();
        } else if (event.key === 'Tab') {
            const items = focusableItems();
            const first = items[0];
            const last = items[items.length - 1];
            if (!first) {
                return;
            }
            if (event.shiftKey && (document.activeElement === first || !sidebar.contains(document.activeElement))) {
                event.preventDefault();
                last.focus();
            } else if (!event.shiftKey && (document.activeElement === last || !sidebar.contains(document.activeElement))) {
                event.preventDefault();
                first.focus();
            }
        }
    });

    // Reset both directions so phone navigation cannot retain desktop focus.
    desktopQuery.addEventListener('change', close);
    close();
})();
