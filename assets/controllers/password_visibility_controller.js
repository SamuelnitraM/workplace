import { Controller } from '@hotwired/stimulus';

/*
 * Bouton afficher / masquer un mot de passe : libellé fixe, état porté par aria-pressed (bouton bascule).
 *
 * Usage :
 *   <div data-controller="password-visibility">
 *     <input type="password" data-password-visibility-target="input">
 *     <button type="button" aria-pressed="false" aria-label="Afficher le mot de passe"
 *             data-password-visibility-target="button" data-action="password-visibility#toggle">
 *       <span data-password-visibility-target="showIcon">…</span>
 *       <span data-password-visibility-target="hideIcon" hidden>…</span>
 *     </button>
 *   </div>
 */
export default class extends Controller {
    static targets = ['input', 'button', 'showIcon', 'hideIcon'];

    connect() {
        this.render(false);
    }

    disconnect() {
        // Ne jamais laisser le mot de passe en clair dans un cache de page (Turbo)
        this.inputTarget.type = 'password';
    }

    toggle() {
        this.render(this.inputTarget.type === 'password');
        this.inputTarget.focus();
    }

    render(visible) {
        this.inputTarget.type = visible ? 'text' : 'password';
        this.buttonTarget.setAttribute('aria-pressed', visible ? 'true' : 'false');
        if (this.hasShowIconTarget) this.showIconTarget.hidden = visible;
        if (this.hasHideIconTarget) this.hideIconTarget.hidden = !visible;
    }
}
