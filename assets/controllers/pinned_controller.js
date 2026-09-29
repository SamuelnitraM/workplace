import { Controller } from '@hotwired/stimulus';
import { listen } from '../lib/realtime.js';
import { icon } from '../lib/icon.js';
import { scrollBehavior } from '../lib/motion.js';

/* stimulusFetch: 'lazy' */

/*
 * Pinned messages of a group channel (placed next to chat_controller on the same element).
 *
 * - bar at the top of the chat: rendered by the server, rebuilt here on each change
 *   (1 message: compact row; several: <details> « N messages épinglés », most recently pinned first);
 * - pin / unpin: POST forms (working without JS), intercepted here for a fetch call;
 * - real time: Pusher events "message-pinned" / "message-unpinned" on the channel of the group channel;
 * - messages appended live by chat_controller (chat:appended event): the indicator and the button are added;
 * - jump to a pinned message: the message is highlighted for HIGHLIGHT_MS (inherently time-based);
 * - all the user content is inserted with textContent.
 */
const PREVIEW_LENGTH = 140;
const HIGHLIGHT_MS = 2000;

export default class extends Controller {
    static targets = ['bar'];
    static values = {
        channel: String,
        pins: Array,        // serialised pinned messages (GroupController::serializePinnedMessage)
        canPin: Boolean,    // the user can pin in this channel
        pinUrl: String,     // URL with « __ID__ » replaced by the id of the message
        unpinUrl: String,
        csrf: String,
    };

    connect() {
        this.pins = Array.isArray(this.pinsValue) ? [...this.pinsValue] : [];
        this.sortPins();

        if (this.channelValue) {
            const offPinned = listen(this.channelValue, 'message-pinned', data => {
                if (data?.message) this.upsert(data.message);
            });
            const offUnpinned = listen(this.channelValue, 'message-unpinned', data => this.remove(Number(data?.id)));
            this.stopListening = () => { offPinned(); offUnpinned(); };
        }
    }

    disconnect() {
        this.stopListening?.();
        clearTimeout(this.highlightTimer);
    }

    // ─── Actions ───────────────────────────────────────────
    toggle(event) {
        event.preventDefault();
        const form = event.currentTarget;
        if (form.dataset.busy) return;
        form.dataset.busy = '1';

        fetch(form.action, {
            method: 'POST',
            body: new FormData(form),
            headers: { 'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json' },
        })
            .then(async response => {
                const data = await response.json().catch(() => ({}));
                if (!response.ok || data.error) {
                    alert(data.error || 'Action impossible.');
                    return;
                }
                if (data.pinned && data.message) {
                    this.upsert(data.message);
                } else {
                    this.remove(Number(data.id));
                }
            })
            .catch(() => alert('Action impossible.'))
            .finally(() => { delete form.dataset.busy; });
    }

    jump({ params }) {
        const target = this.element.querySelector(`#msg-${Number(params.id)}`);
        if (!target) return;
        target.scrollIntoView({ behavior: scrollBehavior(), block: 'center' });
        const bubble = target.querySelector('[data-pin-bubble]') || target;
        this.clearHighlight();
        bubble.classList.add('ring-2', 'ring-accent');
        this.highlighted = bubble;
        this.highlightTimer = setTimeout(() => this.clearHighlight(), HIGHLIGHT_MS);
    }

    /** Message appended live by chat_controller: indicator + pin button. */
    decorate(event) {
        const { element, meta, data } = event.detail || {};
        if (!element || !meta) return;
        const id = Number(data?.id);
        element.classList.add('group');
        meta.classList.add('flex', 'flex-wrap', 'items-center', 'gap-1.5');
        // Existing label (username, title, date) moved as is, without flattening its elements
        let label = meta.firstElementChild;
        if (!label || meta.childNodes.length !== 1) {
            label = document.createElement('span');
            label.append(...meta.childNodes);
        }
        meta.replaceChildren(label, this.buildIndicator(this.isPinned(id)));
        meta.nextElementSibling?.setAttribute('data-pin-bubble', '');
        if (this.canPinValue && id) meta.appendChild(this.buildPinForm(id, this.isPinned(id)));
    }

    // ─── State ─────────────────────────────────────────────
    isPinned(id) {
        return this.pins.some(p => Number(p.id) === id);
    }

    sortPins() {
        this.pins.sort((a, b) => (Number(b.pinnedAtTs) || 0) - (Number(a.pinnedAtTs) || 0) || Number(b.id) - Number(a.id));
    }

    upsert(message) {
        const id = Number(message.id);
        if (!id) return;
        this.pins = this.pins.filter(p => Number(p.id) !== id);
        this.pins.push(message);
        this.sortPins();
        this.render();
        this.markInStream(id, true);
    }

    remove(id) {
        if (!id) return;
        this.pins = this.pins.filter(p => Number(p.id) !== id);
        this.render();
        this.markInStream(id, false);
    }

    // ─── Rendering ─────────────────────────────────────────
    url(template, id) {
        return template.replace('__ID__', String(Number(id)));
    }

    markInStream(id, pinned) {
        const message = this.element.querySelector(`#msg-${id}`);
        if (!message) return;
        message.querySelector('[data-pin-indicator]')?.classList.toggle('hidden', !pinned);
        const form = message.querySelector('[data-pin-form]');
        if (form) {
            form.action = this.url(pinned ? this.unpinUrlValue : this.pinUrlValue, id);
            this.setPinButton(form.querySelector('button'), pinned);
        }
    }

    setPinButton(button, pinned) {
        if (!button) return;
        button.replaceChildren(icon(pinned ? 'x' : 'pin', 'size-4'));
        button.classList.toggle('text-accent-text', pinned);
        button.classList.toggle('text-muted', !pinned);
        button.title = pinned ? 'Désépingler' : 'Épingler';
        button.setAttribute('aria-label', pinned ? 'Désépingler ce message' : 'Épingler ce message');
    }

    buildIndicator(pinned) {
        const span = document.createElement('span');
        span.dataset.pinIndicator = '';
        span.className = `${pinned ? '' : 'hidden'} text-accent-text`;
        span.title = 'Message épinglé';
        span.appendChild(icon('pin', 'size-3.5', { label: 'Épinglé' }));
        return span;
    }

    buildForm(action) {
        const form = document.createElement('form');
        form.method = 'post';
        form.action = action;
        form.dataset.action = 'pinned#toggle';
        const token = document.createElement('input');
        token.type = 'hidden';
        token.name = '_token';
        token.value = this.csrfValue;
        form.appendChild(token);
        return form;
    }

    buildPinForm(id, pinned) {
        const form = this.buildForm(this.url(pinned ? this.unpinUrlValue : this.pinUrlValue, id));
        form.dataset.pinForm = '';
        form.className = 'reveal-on-hover inline-flex';
        const button = document.createElement('button');
        button.type = 'submit';
        button.className = '-my-1.5 inline-flex size-7 items-center justify-center rounded-control hover:bg-surface-raised hover:text-accent-text pointer-coarse:-my-2.5 pointer-coarse:size-11';
        this.setPinButton(button, pinned);
        form.appendChild(button);
        return form;
    }

    buildRow(pin) {
        const row = document.createElement('div');
        row.className = 'flex min-w-0 items-center gap-1';
        row.dataset.pinnedRow = String(Number(pin.id));

        const jump = document.createElement('button');
        jump.type = 'button';
        jump.dataset.action = 'pinned#jump';
        jump.dataset.pinnedIdParam = String(Number(pin.id));
        jump.title = `Épinglé${pin.pinnedBy ? ` par ${pin.pinnedBy}` : ''} le ${pin.pinnedAt ?? ''} — aller au message`;
        jump.className = 'flex min-h-9 min-w-0 flex-1 items-center gap-2 rounded-control px-2 py-1 text-left hover:bg-overlay pointer-coarse:min-h-11';

        const author = document.createElement('span');
        author.className = 'shrink-0 font-semibold text-fg';
        author.textContent = pin.author ?? '?';
        const date = document.createElement('span');
        date.className = 'shrink-0 text-caption text-muted';
        date.textContent = pin.createdAt ?? '';
        const text = document.createElement('span');
        text.className = 'truncate text-fg-secondary';
        const chars = Array.from(String(pin.content ?? ''));
        text.textContent = chars.length > PREVIEW_LENGTH ? `${chars.slice(0, PREVIEW_LENGTH).join('')}…` : chars.join('');
        jump.append(icon('pin', 'size-4 shrink-0 text-accent-text'), author, date, text);
        row.appendChild(jump);

        if (this.canPinValue) {
            const form = this.buildForm(this.url(this.unpinUrlValue, pin.id));
            form.classList.add('shrink-0');
            const button = document.createElement('button');
            button.type = 'submit';
            button.title = 'Désépingler';
            button.setAttribute('aria-label', 'Désépingler ce message');
            button.className = 'btn btn-ghost btn-icon btn-sm hover:text-danger-text';
            button.appendChild(icon('x', 'size-5'));
            form.appendChild(button);
            row.appendChild(form);
        }

        return row;
    }

    render() {
        if (!this.hasBarTarget) return;
        const bar = this.barTarget;
        const wasOpen = bar.querySelector('details')?.open ?? false;
        bar.replaceChildren();
        bar.classList.toggle('hidden', this.pins.length === 0);
        if (this.pins.length === 0) return;

        if (this.pins.length === 1) {
            bar.appendChild(this.buildRow(this.pins[0]));
            return;
        }

        const details = document.createElement('details');
        details.open = wasOpen;
        const summary = document.createElement('summary');
        summary.className = 'flex min-h-9 items-center gap-2 rounded-control px-2 py-1 font-semibold text-accent-text select-none hover:bg-overlay pointer-coarse:min-h-11';
        const label = document.createElement('span');
        label.className = 'flex-1';
        label.textContent = `${this.pins.length} messages épinglés`;
        summary.append(icon('pin', 'size-4 shrink-0'), label, icon('chevron-down', 'details-chevron size-4 shrink-0'));
        details.className = 'details-reset';
        const list = document.createElement('ul');
        list.className = 'mt-1 max-h-60 space-y-0.5 overflow-y-auto';
        this.pins.forEach(pin => {
            const item = document.createElement('li');
            item.appendChild(this.buildRow(pin));
            list.appendChild(item);
        });
        details.append(summary, list);
        bar.appendChild(details);
    }

    clearHighlight() {
        clearTimeout(this.highlightTimer);
        this.highlighted?.classList.remove('ring-2', 'ring-accent');
        this.highlighted = null;
    }
}
