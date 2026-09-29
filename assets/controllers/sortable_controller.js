import { Controller } from '@hotwired/stimulus';

/*
 * List rearranged by drag and drop (mouse) or with move up / move down buttons (keyboard); the new order is saved
 * right after each move (POST ids[] with the CSRF token). Saves are sent one at a time, in order, and moves made
 * while a save is running are grouped into the next one, so the server always ends with the latest order.
 *
 * Usage:
 *   <ul data-controller="sortable" data-sortable-url-value="/groups/ordre" data-sortable-token-value="…">
 *       <li data-sortable-target="item" data-id="12" draggable="true">…
 *           <button data-action="sortable#moveUp">↑</button> <button data-action="sortable#moveDown">↓</button></li>
 *   </ul>
 */
export default class extends Controller {
    static targets = ['item'];
    static values = { url: String, token: String };

    itemTargetConnected(item) {
        item.addEventListener('dragstart', this.onDragStart);
        item.addEventListener('dragover', this.onDragOver);
        item.addEventListener('dragend', this.onDragEnd);
    }

    itemTargetDisconnected(item) {
        item.removeEventListener('dragstart', this.onDragStart);
        item.removeEventListener('dragover', this.onDragOver);
        item.removeEventListener('dragend', this.onDragEnd);
    }

    onDragStart = event => {
        this.dragged = event.currentTarget;
        this.dragged.classList.add('is-dragging');
        event.dataTransfer.effectAllowed = 'move';
        event.dataTransfer.setData('text/plain', this.dragged.dataset.id);
    };

    onDragOver = event => {
        const target = event.currentTarget;
        if (!this.dragged || target === this.dragged) return;
        event.preventDefault();
        const box = target.getBoundingClientRect();
        const after = event.clientY > box.top + box.height / 2;
        target.parentNode.insertBefore(this.dragged, after ? target.nextSibling : target);
    };

    onDragEnd = () => {
        if (!this.dragged) return;
        this.dragged.classList.remove('is-dragging');
        this.dragged = null;
        this.save();
    };

    moveUp(event) {
        const item = event.currentTarget.closest('[data-sortable-target="item"]');
        const previous = item.previousElementSibling;
        if (!previous) return;
        item.parentNode.insertBefore(item, previous);
        event.currentTarget.focus();
        this.save();
    }

    moveDown(event) {
        const item = event.currentTarget.closest('[data-sortable-target="item"]');
        const next = item.nextElementSibling;
        if (!next) return;
        item.parentNode.insertBefore(next, item);
        event.currentTarget.focus();
        this.save();
    }

    save() {
        if (this.saveQueued) return;
        this.saveQueued = true;
        this.saving = (this.saving ?? Promise.resolve()).then(() => {
            this.saveQueued = false;
            return this.send();
        });
    }

    // The order is read when the request leaves, so a queued save carries every move made until then
    send() {
        const body = new URLSearchParams({ _token: this.tokenValue });
        this.itemTargets.forEach(item => body.append('ids[]', item.dataset.id));
        return fetch(this.urlValue, {
            method: 'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json' },
            body,
        }).catch(() => {});
    }
}
