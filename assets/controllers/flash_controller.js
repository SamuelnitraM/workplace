import { Controller } from '@hotwired/stimulus';

/*
 * Message flash : disparition progressive après un délai, fermeture manuelle.
 *
 * Usage : <div data-controller="flash" data-flash-delay-value="4000">… <button data-action="flash#close">✕</button></div>
 */
export default class extends Controller {
    static values = { delay: { type: Number, default: 4000 } };

    connect() {
        this.timer = setTimeout(() => this.fadeOut(), this.delayValue);
    }

    disconnect() {
        clearTimeout(this.timer);
        clearTimeout(this.removeTimer);
    }

    close() {
        this.element.remove();
    }

    fadeOut() {
        const el = this.element;
        el.style.transition = 'opacity 0.5s ease, transform 0.5s ease';
        el.style.opacity = '0';
        el.style.transform = 'translateY(-10px)';
        this.removeTimer = setTimeout(() => el.remove(), 500);
    }
}
