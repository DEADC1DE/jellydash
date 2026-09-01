(() => {
    const disclosure = document.querySelector('[data-most-watched]');
    if (!(disclosure instanceof HTMLDetailsElement)) {
        return;
    }

    let postersLoaded = false;

    const loadPosters = () => {
        if (postersLoaded || !disclosure.open) {
            return;
        }

        disclosure.querySelectorAll('[data-deferred-poster]').forEach((poster) => {
            const background = poster.getAttribute('data-deferred-poster');
            if (background) {
                poster.style.backgroundImage = background;
            }
            poster.removeAttribute('data-deferred-poster');
        });

        postersLoaded = true;
    };

    disclosure.addEventListener('toggle', loadPosters);
    loadPosters();
})();
