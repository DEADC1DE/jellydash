(function () {
    // Copy an invitation link to the clipboard with a short visual ack on the
    // button itself, so no extra status element is needed.
    document.addEventListener('click', function (event) {
        const button = event.target instanceof Element
            ? event.target.closest('[data-copy-invite]')
            : null;
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
})();
