(function () {
    // Keep account settings in the same dialog across safe POST/Redirect/GET
    // actions, and allow invitation links to be copied in place.
    document.addEventListener('click', function (event) {
        const target = event.target instanceof Element ? event.target : null;
        if (!target) {
            return;
        }

        const openButton = target.closest('[data-user-manager-open]');
        if (openButton) {
            const dialog = document.getElementById(openButton.getAttribute('data-user-manager-open'));
            if (dialog && typeof dialog.showModal === 'function') {
                dialog.showModal();
            }
            return;
        }

        const closeButton = target.closest('[data-user-manager-close]');
        if (closeButton) {
            const dialog = closeButton.closest('dialog[data-user-manager-dialog]');
            if (dialog?.open) {
                dialog.close();
            }
            return;
        }

        if (target.matches('dialog[data-user-manager-dialog]')) {
            target.close();
            return;
        }

        const button = target.closest('[data-copy-invite]');
        if (!button || typeof navigator.clipboard?.writeText !== 'function') {
            return;
        }

        navigator.clipboard.writeText(button.getAttribute('data-copy-invite') || '').then(function () {
            const original = button.textContent;
            button.textContent = 'Copied!';
            button.disabled = true;
            window.setTimeout(function () {
                button.textContent = original;
                button.disabled = false;
            }, 1500);
        }).catch(function () {
            // Clipboard unavailable (permissions/insecure context): the link
            // stays selectable right next to the button.
        });
    });

    function initializeUserDialogs() {
        document.querySelectorAll('dialog[data-user-manager-dialog]').forEach(function (dialog) {
            const allLibraries = dialog.querySelector('[data-user-all-libraries]');
            const libraryInputs = dialog.querySelectorAll('[data-user-library]');
            if (!allLibraries) {
                return;
            }

            const updateLibraryMode = function () {
                libraryInputs.forEach(function (input) {
                    if (allLibraries.checked) {
                        input.checked = false;
                    }
                    input.disabled = allLibraries.checked;
                });
            };

            allLibraries.addEventListener('change', updateLibraryMode);
            updateLibraryMode();
        });

        if (window.location.hash.length > 1) {
            let dialogId;
            try {
                dialogId = decodeURIComponent(window.location.hash.slice(1));
            } catch (_) {
                return;
            }
            const dialog = document.getElementById(dialogId);
            if (dialog?.matches('dialog[data-user-manager-dialog]') && typeof dialog.showModal === 'function') {
                dialog.showModal();
            }
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initializeUserDialogs, { once: true });
    } else {
        initializeUserDialogs();
    }
})();
