import { Controller } from '@hotwired/stimulus';
import { previousPage } from '../lib/navigation_history.js';

/*
 * « Retour » link to the previous page (templates/_partials/_back_link.html.twig): when that page is the previous
 * entry of the history, going back restores it as it was left (Turbo restoration visit, scroll position);
 * otherwise (page opened in a new tab, after a back/forward move) the link is followed normally.
 */
export default class extends Controller {
    return(event) {
        if (event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
        const previous = previousPage();
        if (!previous || window.history.length < 2) return;
        const previousUrl = new URL(previous);
        if (previousUrl.origin !== window.location.origin || previousUrl.pathname + previousUrl.search !== this.element.getAttribute('href')) return;
        event.preventDefault();
        window.history.back();
    }
}
