import { Controller } from '@hotwired/stimulus';

/*
 * Retour en direct sur les règles d'un champ (formulaire d'inscription) : chaque règle passe de « • » gris
 * à « ✓ » (text-success-text) dès qu'elle est respectée. Purement indicatif : la validation serveur reste la référence.
 *
 * Usage :
 *   <div data-controller="field-rules">
 *     <input data-field-rules-target="input" data-action="input->field-rules#check">
 *     <ul>
 *       <li data-field-rules-target="rule" data-rule="minlength" data-param="3">
 *         <span data-icon aria-hidden="true">•</span> 3 caractères minimum <span data-state class="sr-only"></span>
 *       </li>
 *     </ul>
 *   </div>
 *
 * Règles : minlength, maxlength (param = nombre), pattern (param = expression régulière complète, sans drapeaux), email.
 */
export default class extends Controller {
    static targets = ['input', 'rule'];

    static metClasses = ['text-success-text'];
    static unmetClasses = ['text-muted'];

    connect() {
        this.check();
    }

    check() {
        const value = this.inputTarget.value;
        this.ruleTargets.forEach((rule) => this.render(rule, value !== '' && this.isMet(rule, value)));
    }

    isMet(rule, value) {
        const param = rule.dataset.param ?? '';
        const length = [...value].length;

        switch (rule.dataset.rule) {
            case 'minlength':
                return length >= Number(param);
            case 'maxlength':
                return length <= Number(param);
            case 'pattern':
                try {
                    return new RegExp(param).test(value);
                } catch {
                    return false;
                }
            case 'email':
                // Même contrôle que le navigateur pour <input type="email">
                return !this.inputTarget.validity.typeMismatch;
            default:
                return false;
        }
    }

    render(rule, met) {
        rule.classList.remove(...(met ? this.constructor.unmetClasses : this.constructor.metClasses));
        rule.classList.add(...(met ? this.constructor.metClasses : this.constructor.unmetClasses));
        rule.dataset.met = met ? 'true' : 'false';

        const icon = rule.querySelector('[data-icon]');
        if (icon) {
            icon.textContent = met ? '✓' : '•';
        }
        const state = rule.querySelector('[data-state]');
        if (state) {
            state.textContent = met ? ' (respectée)' : ' (non respectée)';
        }
    }
}
