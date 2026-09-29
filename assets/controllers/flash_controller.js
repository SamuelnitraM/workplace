import { Controller } from '@hotwired/stimulus';
import { afterTransitions } from '../lib/motion.js';

/*
 * Flash message: fades out after a delay (inherently time-based: reading time), manual closing.
 * The message is temporary for Turbo: it is left out of the page cache, so going back does not show it again.
 *
 * Usage: <div data-controller="flash" data-flash-delay-value="4000">… <button data-action="flash#close">✕</button></div>
 */
export default class extends Controller {
    static values = { delay: { type: Number, default: 4000 } };

    connect() {
        this.element.setAttribute('data-turbo-temporary', '');
        this.timer = setTimeout(() => this.fadeOut(), this.delayValue);
    }

    disconnect() {
        clearTimeout(this.timer);
    }

    close() {
        this.element.remove();
    }

    fadeOut() {
        const el = this.element;
        el.style.transition = 'opacity 0.5s ease, transform 0.5s ease';
        el.style.opacity = '0';
        el.style.transform = 'translateY(-10px)';
        afterTransitions(el).then(() => el.remove());
    }
}
