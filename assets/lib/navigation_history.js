/*
 * Page visited just before the current one, kept across Turbo Drive visits (document.referrer only reflects the
 * first page load). After a back/forward move (restoration visit) the previous history entry is unknown: null.
 * Imported once by assets/app.js so that every visit is recorded; read by back_link_controller.js.
 */
let currentLocation = window.location.href;
let previousLocation = document.referrer || null;
let restoring = false;

document.addEventListener('turbo:visit', event => {
    restoring = event.detail.action === 'restore';
});

document.addEventListener('turbo:load', () => {
    if (window.location.href === currentLocation) return;
    previousLocation = restoring ? null : currentLocation;
    currentLocation = window.location.href;
    restoring = false;
});

/** Absolute URL of the page visited just before, when it is the previous entry of the history. */
export function previousPage() {
    return previousLocation;
}
