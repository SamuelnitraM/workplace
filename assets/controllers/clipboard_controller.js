import { Controller } from '@hotwired/stimulus';

/*
 * Copies a text to the clipboard: the value of the "source" target (textarea, input) or the "text" value.
 * The "label" target shows the confirmation message until the pointer or the focus leaves the element.
 *
 * Usage:
 *   <div data-controller="clipboard" data-clipboard-text-value="https://…">
 *       <button data-action="clipboard#copy"><span data-clipboard-target="label">Copier le lien</span></button>
 *   </div>
 */
export default class extends Controller {
    static targets = ['source', 'label'];
    static values = { text: String, done: { type: String, default: 'Copié !' } };

    async copy() {
        const text = this.hasSourceTarget ? this.sourceTarget.value : this.textValue;
        try {
            await navigator.clipboard.writeText(text);
        } catch {
            // Clipboard API unavailable (insecure context): the source text stays selected for a manual copy
            if (this.hasSourceTarget) this.sourceTarget.select();
            return;
        }
        if (!this.hasLabelTarget) return;
        const label = this.labelTarget;
        label.dataset.originalText ??= label.textContent;
        label.textContent = this.doneValue;
        // The confirmation stays until the pointer or the focus leaves the control
        const restore = () => { label.textContent = label.dataset.originalText; };
        this.element.addEventListener('pointerleave', restore, { once: true });
        this.element.addEventListener('focusout', restore, { once: true });
    }
}
