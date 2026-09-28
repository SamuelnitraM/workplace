/*
 * Alpine.js, served by the importmap from the site itself, started once for the whole visit.
 * Turbo Drive keeps the JavaScript context: Alpine's MutationObserver initialises the components of every new page.
 * Components are registered with Alpine.data() before their markup is inserted (see army_form_controller.js).
 * window.Alpine is kept for inline expressions and debugging.
 */
import Alpine from 'alpinejs';

window.Alpine = Alpine;
Alpine.start();

export default Alpine;
