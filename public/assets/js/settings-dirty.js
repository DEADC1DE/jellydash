(function () {
    const form = document.querySelector('[data-settings-form]');
    const bar = document.querySelector('[data-settings-dirty-bar]');

    if (!form || !bar) {
        return;
    }

    const snapshot = () => new URLSearchParams(new FormData(form)).toString();
    const initialState = snapshot();
    let submitting = false;

    function isDirty() {
        return snapshot() !== initialState;
    }

    function sync() {
        bar.hidden = !isDirty();
    }

    form.addEventListener('input', sync);
    form.addEventListener('change', sync);
    form.addEventListener('reset', () => window.setTimeout(sync, 0));
    form.addEventListener('submit', () => {
        submitting = true;
        bar.hidden = true;
    });

    window.addEventListener('pageshow', sync);
    window.addEventListener('beforeunload', (event) => {
        if (submitting || !isDirty()) {
            return;
        }

        event.preventDefault();
        event.returnValue = '';
    });
}());
