document.querySelectorAll('[data-day-detail]').forEach((day, index) => {
    const show = () => {
        document.querySelector('[data-chart-detail]').textContent = day.dataset.dayDetail;
        document.querySelectorAll('.recap-day.is-selected').forEach((other) => other.classList.remove('is-selected'));
        day.classList.add('is-selected');
        const picker = document.querySelector('[data-day-picker]');
        if (picker) picker.value = String(index);
    };
    day.addEventListener('click', show);
    day.addEventListener('focus', show);
});
document.querySelectorAll('.recap-page img').forEach((poster) => {
    poster.addEventListener('error', () => { poster.hidden = true; });
    if (poster.complete && poster.naturalWidth === 0) poster.hidden = true;
});
document.querySelector('[data-day-picker]')?.addEventListener('change', (event) => {
    if (event.target.value !== '') {
        document.querySelectorAll('[data-day-detail]')[Number(event.target.value)].click();
    } else {
        document.querySelectorAll('.recap-day.is-selected').forEach((day) => day.classList.remove('is-selected'));
        document.querySelector('[data-chart-detail]').textContent = 'Select a day to see its watch time.';
    }
});
