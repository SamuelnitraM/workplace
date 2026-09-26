import { Controller } from '@hotwired/stimulus';

/*
 * Member suggestions while typing "@pseudo" in a text field (forum editor, private messages, group chat).
 *
 * Usage: the controller element wraps the field; it builds its own suggestion list.
 *   <div data-controller="mention-suggest" data-mention-suggest-placement-value="up">
 *       <input data-mention-suggest-target="input"
 *              data-action="input->mention-suggest#onInput keydown->mention-suggest#onKeydown blur->mention-suggest#close">
 *   </div>
 *   - placement: "up" (default, list above the field), "inside" (inside the bottom of a large text area);
 *   - the suggestions come from the URL of <meta name="hf-mentions-url"> (App\Controller\MentionController).
 * Keyboard: ↑ ↓ to move, Enter / Tab to insert, Escape to close. When the list is open, these keys are consumed
 * before any other listener of the field (declare this controller's actions first), so that Enter inserts the
 * member instead of sending the message.
 */
const MENTION_TOKEN = /(^|[^\w@])@([A-Za-z0-9_.-]{1,50})$/;
const FETCH_DELAY_MS = 150;

export default class extends Controller {
    static targets = ['input'];
    static values = { placement: { type: String, default: 'up' } };

    connect() {
        this.element.classList.add('mention-suggest');
        this.list = document.createElement('ul');
        this.list.id = `mention-list-${Math.random().toString(36).slice(2)}`;
        this.list.className = `mention-list mention-list-${this.placementValue === 'inside' ? 'inside' : 'up'} hidden`;
        this.list.setAttribute('role', 'listbox');
        this.list.setAttribute('aria-label', 'Membres à mentionner');
        this.element.append(this.list);
        this.suggestions = [];
        this.activeIndex = -1;
        this.mentionState = null;
        if (this.hasInputTarget) {
            this.inputTarget.setAttribute('aria-autocomplete', 'list');
            this.inputTarget.setAttribute('aria-controls', this.list.id);
            this.inputTarget.setAttribute('aria-expanded', 'false');
        }
    }

    disconnect() {
        clearTimeout(this.fetchTimer);
        this.list.remove();
    }

    get url() {
        return document.querySelector('meta[name="hf-mentions-url"]')?.content || '';
    }

    isOpen() {
        return !this.list.classList.contains('hidden') && this.suggestions.length > 0;
    }

    onKeydown(event) {
        if (!this.isOpen()) return;
        const consume = () => {
            event.preventDefault();
            event.stopImmediatePropagation();
        };
        if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
            consume();
            const step = event.key === 'ArrowDown' ? 1 : -1;
            this.highlight((this.activeIndex + step + this.suggestions.length) % this.suggestions.length);
        } else if ((event.key === 'Enter' || event.key === 'Tab') && this.activeIndex >= 0) {
            consume();
            this.insert(this.suggestions[this.activeIndex].username);
        } else if (event.key === 'Escape') {
            consume();
            this.close();
        }
    }

    onInput() {
        const input = this.inputTarget;
        const match = input.value.slice(0, input.selectionStart).match(MENTION_TOKEN);
        if (!match || input.selectionStart !== input.selectionEnd) {
            this.close();
            return;
        }
        const query = match[2];
        this.mentionState = { start: input.selectionStart - query.length - 1, query };
        clearTimeout(this.fetchTimer);
        this.fetchTimer = setTimeout(() => this.fetchSuggestions(query), FETCH_DELAY_MS);
    }

    fetchSuggestions(query) {
        if (!this.url) return;
        fetch(`${this.url}?q=${encodeURIComponent(query)}`, { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
            .then(response => (response.ok ? response.json() : Promise.reject(response)))
            .then(data => {
                // The text changed in the meantime: outdated answer
                if (!this.mentionState || this.mentionState.query !== query) return;
                this.suggestions = data.users || [];
                this.render();
            })
            .catch(() => this.close());
    }

    render() {
        if (this.suggestions.length === 0) {
            this.close();
            return;
        }
        this.list.replaceChildren(...this.suggestions.map((user, index) => {
            const option = document.createElement('li');
            option.id = `${this.list.id}-${index}`;
            option.setAttribute('role', 'option');
            option.className = 'mention-option';
            const avatar = document.createElement('span');
            avatar.className = 'avatar avatar-xs';
            if (user.avatar) {
                const image = document.createElement('img');
                image.src = user.avatar;
                image.alt = '';
                avatar.appendChild(image);
            } else {
                const initial = document.createElement('span');
                initial.setAttribute('aria-hidden', 'true');
                initial.textContent = user.username.charAt(0).toUpperCase();
                avatar.appendChild(initial);
            }
            const name = document.createElement('span');
            name.textContent = user.username;
            option.append(avatar, name);
            // mousedown: before the field loses the focus (which closes the list)
            option.addEventListener('mousedown', event => {
                event.preventDefault();
                this.insert(user.username);
            });
            return option;
        }));
        this.list.classList.remove('hidden');
        this.inputTarget.setAttribute('aria-expanded', 'true');
        this.highlight(0);
    }

    highlight(index) {
        this.activeIndex = index;
        [...this.list.children].forEach((option, position) => option.setAttribute('aria-selected', position === index ? 'true' : 'false'));
        const active = this.list.children[index];
        if (active) {
            this.inputTarget.setAttribute('aria-activedescendant', active.id);
            active.scrollIntoView({ block: 'nearest' });
        }
    }

    /** Replaces the "@query" being typed by "@username " (keeping the undo history when the browser allows it). */
    insert(username) {
        const input = this.inputTarget;
        if (!this.mentionState) return;
        input.focus();
        input.setSelectionRange(this.mentionState.start, input.selectionStart);
        this.close();
        const text = `@${username} `;
        let inserted = false;
        try {
            inserted = document.execCommand('insertText', false, text);
        } catch (error) {
            inserted = false;
        }
        if (!inserted) {
            input.setRangeText(text, input.selectionStart, input.selectionEnd, 'end');
            input.dispatchEvent(new Event('input', { bubbles: true }));
        }
    }

    close() {
        clearTimeout(this.fetchTimer);
        this.mentionState = null;
        this.suggestions = [];
        this.activeIndex = -1;
        this.list.classList.add('hidden');
        this.list.replaceChildren();
        if (this.hasInputTarget) {
            this.inputTarget.setAttribute('aria-expanded', 'false');
            this.inputTarget.removeAttribute('aria-activedescendant');
        }
    }
}
