import { Controller } from '@hotwired/stimulus';
import { EMOJI_GROUPS } from '../lib/emoji.js';

/*
 * Emoji picker shared by every text field of the site (forum editor, private messages, group chat).
 *
 * Usage (the controller builds its own button and panel):
 *   <div data-controller="emoji-picker" data-emoji-picker-input-value="reply-content"
 *        data-emoji-picker-placement-value="down"></div>
 *   - input: id of the <textarea> / <input> that receives the emoji; without it, the first text field
 *     of the closest <form> is used;
 *   - placement: "up" (default) or "down", side on which the panel opens.
 * The chosen emoji is inserted at the caret and an "input" event is dispatched, so that the field's own
 * listeners (drafts, counters, mention suggestions) react as if it had been typed.
 */
const HELMET_ICON = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" class="size-4" aria-hidden="true">'
    + '<path d="M5 12.5V10a7 7 0 0 1 14 0v2.5"/><path d="M5 12.5l1.2 4L9 19"/><path d="M19 12.5l-1.2 4L15 19"/>'
    + '<path d="M9 15h6l-.9 6H9.9z"/><path d="M11 17v2"/><path d="M13 17v2"/>'
    + '<circle cx="8.9" cy="11" r="1.7"/><circle cx="15.1" cy="11" r="1.7"/>'
    + '</svg>';

export default class extends Controller {
    static values = { input: String, placement: { type: String, default: 'up' } };

    connect() {
        this.element.classList.add('emoji-picker');
        this.toggleButton = document.createElement('button');
        this.toggleButton.type = 'button';
        this.toggleButton.className = 'emoji-picker-toggle';
        this.toggleButton.innerHTML = HELMET_ICON;
        this.toggleButton.setAttribute('aria-label', 'Insérer une émoticône');
        this.toggleButton.dataset.tooltip = 'Émoticônes';
        this.toggleButton.setAttribute('aria-haspopup', 'true');
        this.toggleButton.setAttribute('aria-expanded', 'false');
        this.toggleButton.addEventListener('click', () => this.toggle());
        this.element.append(this.toggleButton);
        this.panel = null;
        this.closeOnOutsidePointer = event => {
            if (!this.element.contains(event.target)) this.close();
        };
    }

    disconnect() {
        document.removeEventListener('pointerdown', this.closeOnOutsidePointer);
        this.toggleButton.remove();
        this.panel?.remove();
    }

    toggle() {
        this.panel && !this.panel.hidden ? this.close() : this.open();
    }

    open() {
        this.panel ??= this.buildPanel();
        this.panel.hidden = false;
        this.toggleButton.setAttribute('aria-expanded', 'true');
        document.addEventListener('pointerdown', this.closeOnOutsidePointer);
        this.panel.querySelector('button')?.focus();
    }

    close({ restoreFocus = false } = {}) {
        if (!this.panel || this.panel.hidden) return;
        this.panel.hidden = true;
        this.toggleButton.setAttribute('aria-expanded', 'false');
        document.removeEventListener('pointerdown', this.closeOnOutsidePointer);
        if (restoreFocus) this.toggleButton.focus();
    }

    /** Panel built on first opening: one titled grid per emoji group. */
    buildPanel() {
        const panel = document.createElement('div');
        panel.className = `emoji-picker-panel emoji-picker-panel-${this.placementValue === 'down' ? 'down' : 'up'}`;
        panel.setAttribute('role', 'dialog');
        panel.setAttribute('aria-label', 'Émoticônes');
        panel.hidden = true;
        for (const group of EMOJI_GROUPS) {
            const title = document.createElement('p');
            title.className = 'emoji-picker-group';
            title.textContent = group.label;
            const grid = document.createElement('div');
            grid.className = 'emoji-picker-grid';
            for (const emoji of group.emoji) {
                const button = document.createElement('button');
                button.type = 'button';
                button.className = 'emoji-picker-emoji';
                button.textContent = emoji;
                button.addEventListener('click', () => this.insert(emoji));
                grid.append(button);
            }
            panel.append(title, grid);
        }
        panel.addEventListener('keydown', event => {
            if (event.key === 'Escape') {
                event.preventDefault();
                event.stopPropagation();
                this.close({ restoreFocus: true });
            }
        });
        this.element.append(panel);
        return panel;
    }

    /** Text field receiving the emoji: the configured id, or the first text field of the enclosing form. */
    field() {
        if (this.inputValue) return document.getElementById(this.inputValue);
        return this.element.closest('form')?.querySelector('textarea, input[type="text"], input:not([type])') ?? null;
    }

    insert(emoji) {
        const input = this.field();
        if (!input) return;
        const start = input.selectionStart ?? input.value.length;
        const end = input.selectionEnd ?? input.value.length;
        input.setRangeText(emoji, start, end, 'end');
        input.dispatchEvent(new Event('input', { bubbles: true }));
        this.close();
        input.focus();
    }
}
