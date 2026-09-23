import { Controller } from '@hotwired/stimulus';
import { getCount } from '../lib/realtime.js';

/*
 * Badge de compteur global (messages privés non lus, notifications…), mis à jour par l'évènement
 * « hf:counts » émis par lib/realtime.js.
 *
 * Usage : <span data-controller="counter" data-counter-kind-value="messages" class="hidden …"></span>
 */
export default class extends Controller {
    static values = { kind: String };

    connect() {
        this.onCounts = event => this.render(event.detail[this.kindValue]);
        document.addEventListener('hf:counts', this.onCounts);
        const known = getCount(this.kindValue);
        if (known !== undefined) this.render(known);
    }

    disconnect() {
        document.removeEventListener('hf:counts', this.onCounts);
    }

    render(count) {
        if (count === undefined) return;
        this.element.textContent = count > 0 ? (count > 99 ? '99+' : String(count)) : '';
        this.element.classList.toggle('hidden', !(count > 0));
    }
}
