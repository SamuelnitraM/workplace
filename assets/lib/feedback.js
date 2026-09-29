/*
 * Short confirmation shown in place of a label (« Copié ! », « Lien copié ! »), kept until the pointer or the focus
 * leaves the given element; the original text is then restored.
 *
 *   import { confirmUntilLeave } from '../lib/feedback.js';
 *   confirmUntilLeave(this.labelTarget, 'Copié !', this.element);
 */
const pending = new WeakMap();

export function confirmUntilLeave(label, text, scope = label) {
    pending.get(label)?.abort();
    label.dataset.originalText ??= label.textContent;
    label.textContent = text;
    const listeners = new AbortController();
    pending.set(label, listeners);
    const restore = () => {
        listeners.abort();
        pending.delete(label);
        label.textContent = label.dataset.originalText;
    };
    scope.addEventListener('pointerleave', restore, { signal: listeners.signal });
    scope.addEventListener('focusout', restore, { signal: listeners.signal });
}
