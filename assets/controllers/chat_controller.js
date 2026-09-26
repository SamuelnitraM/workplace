import { Controller } from '@hotwired/stimulus';
import { listen, setActive, clearActive, postJson, visit } from '../lib/realtime.js';

/* stimulusFetch: 'lazy' */

/*
 * Real-time chat (private conversation or group channel).
 *
 * - subscribes to the private Pusher channel on connect, unsubscribes on disconnect (Turbo visits included);
 * - AJAX sending: the message is displayed from the server response;
 * - messages built with textContent (never interpreted as HTML), except contentHtml: content escaped
 *   by the server (App\Text\MentionResolver::linkify) where only @pseudo mentions are links;
 * - tells the messenger / notifications which content is displayed (activeConversation, notificationKey).
 */
export default class extends Controller {
    static targets = ['messages', 'scrollButton', 'scrollBadge', 'form', 'input', 'empty'];
    static values = {
        channel: String,          // private Pusher channel (empty: no conversation yet)
        sendUrl: String,
        userId: Number,
        avatarBase: String,
        showAuthor: { type: Boolean, default: false },
        conversationId: Number,   // displayed private conversation (0: none)
        readUrl: String,          // mark the conversation as read when a message is received
        readCsrf: { type: String, default: 'private-message' },
        notificationKey: String,  // key of the notifications matching the displayed content
        reloadAfterFirstMessage: { type: Boolean, default: false },
        reportUrl: String,        // report of another member's message ("__ID__" replaced by the message id)
    };

    connect() {
        this.scrollToBottom();

        if (this.conversationIdValue) setActive('conversation', this.conversationIdValue);
        if (this.notificationKeyValue) setActive('notificationKey', this.notificationKeyValue);

        if (this.channelValue) {
            this.stopListening = listen(this.channelValue, 'new-message', data => this.onMessage(data));
        }
    }

    disconnect() {
        this.stopListening?.();
        if (this.conversationIdValue) clearActive('conversation', this.conversationIdValue);
        if (this.notificationKeyValue) clearActive('notificationKey', this.notificationKeyValue);
    }

    onMessage(data) {
        this.appendMessage(data);
        if (this.readUrlValue && data.authorId !== this.userIdValue) {
            postJson(this.readUrlValue, this.readCsrfValue).catch(() => {});
        }
    }

    // ─── Scrolling ─────────────────────────────────────────
    isAtBottom() {
        const el = this.messagesTarget;
        return el.scrollHeight - el.scrollTop - el.clientHeight < 50;
    }

    scrollToBottom() {
        if (this.hasMessagesTarget) this.messagesTarget.scrollTop = this.messagesTarget.scrollHeight;
    }

    onScroll() {
        if (!this.hasScrollButtonTarget) return;
        if (this.isAtBottom()) {
            this.scrollButtonTarget.classList.add('hidden');
            this.scrollBadgeTarget.classList.add('hidden');
        } else {
            this.scrollButtonTarget.classList.remove('hidden');
        }
    }

    jumpToBottom() {
        this.scrollToBottom();
        this.scrollButtonTarget.classList.add('hidden');
        this.scrollBadgeTarget.classList.add('hidden');
    }

    // ─── Sending ───────────────────────────────────────────
    send(event) {
        event.preventDefault();
        const input = this.inputTarget;
        const content = input.value.trim();
        if (!content) return;

        const formData = new FormData(this.formTarget);
        input.value = '';

        fetch(this.sendUrlValue || this.formTarget.action, {
            method: 'POST',
            body: formData,
            headers: { 'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json' },
        })
            .then(r => r.json())
            .then(data => {
                if (data.error) {
                    input.value = content;
                    alert(data.error);
                    return;
                }
                this.appendMessage(data);
                // First conversation created: reload to subscribe to the real-time channel
                if (this.reloadAfterFirstMessageValue) visit(window.location.href, { action: 'replace' });
            })
            .catch(() => { input.value = content; });
    }

    // ─── Rendering ─────────────────────────────────────────
    appendMessage(data) {
        if (!this.hasMessagesTarget || this.messagesTarget.querySelector(`#msg-${Number(data.id)}`)) return; // already displayed
        const isCurrentUser = data.authorId === this.userIdValue;
        const wasAtBottom = this.isAtBottom();

        if (this.hasEmptyTarget) this.emptyTarget.remove();

        this.ensureDaySeparator();

        // Design system classes (assets/styles/app.css, "Forum & communication" section: .chat-msg, .chat-bubble…)
        const msgDiv = document.createElement('div');
        msgDiv.className = `chat-msg${isCurrentUser ? ' chat-msg-own' : ''}`;
        msgDiv.id = `msg-${Number(data.id)}`;

        // Private conversation: no avatar for one's own messages; group channel (showAuthor): avatar for everyone
        let avatarDiv = null;
        if (!isCurrentUser || this.showAuthorValue) {
            avatarDiv = document.createElement('span');
            avatarDiv.className = 'avatar avatar-sm';
            if (data.avatar) {
                const img = document.createElement('img');
                img.src = this.avatarBaseValue + encodeURIComponent(data.avatar);
                img.alt = '';
                avatarDiv.appendChild(img);
            } else {
                const initial = document.createElement('span');
                initial.setAttribute('aria-hidden', 'true');
                initial.textContent = (data.author || '?').charAt(0).toUpperCase();
                avatarDiv.appendChild(initial);
            }
        }

        const body = document.createElement('div');
        body.className = 'chat-msg-body';
        const meta = document.createElement('div');
        meta.className = 'chat-meta';
        if (this.showAuthorValue) {
            const label = document.createElement('span');
            label.className = 'inline-flex flex-wrap items-center gap-1';
            label.append(String(data.author ?? ''));
            const title = this.buildTitle(data.title);
            if (title) label.append(title);
            label.append(` — ${data.createdAt ?? ''}`);
            meta.appendChild(label);
            if (!isCurrentUser && this.reportUrlValue) meta.appendChild(this.buildReportLink(data.id));
        } else {
            // createdAt = "dd/mm HH:ii": the time is enough under the day separator
            meta.textContent = String(data.createdAt ?? '').split(' ').pop();
        }
        // The bubble immediately follows the meta line (pinned_controller relies on meta.nextElementSibling)
        const bubble = document.createElement('div');
        bubble.className = 'chat-bubble';
        if (typeof data.contentHtml === 'string') {
            bubble.innerHTML = data.contentHtml;
        } else {
            bubble.textContent = data.content;
        }
        body.append(meta, bubble);

        if (avatarDiv) msgDiv.append(avatarDiv);
        msgDiv.append(body);
        this.messagesTarget.appendChild(msgDiv);
        // Lets other controllers (e.g. pinned) complete the appended message (pin button…)
        this.dispatch('appended', { detail: { element: msgDiv, meta, data } });

        if (wasAtBottom || isCurrentUser) {
            this.scrollToBottom();
        } else if (this.hasScrollBadgeTarget) {
            this.scrollBadgeTarget.classList.remove('hidden');
        }
    }

    /**
     * "Today" separator before a live-appended message, if the stream shows day separators
     * (.chat-day[data-chat-day], see templates/private_message/show.html.twig) or was empty.
     */
    ensureDaySeparator() {
        const stream = this.messagesTarget;
        const days = stream.querySelectorAll('[data-chat-day]');
        if (days.length === 0 && stream.querySelector('[id^="msg-"]')) return; // stream without separators
        const now = new Date();
        const key = `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}-${String(now.getDate()).padStart(2, '0')}`;
        if (days.length > 0 && days[days.length - 1].dataset.chatDay === key) return;

        const separator = document.createElement('div');
        separator.className = 'chat-day';
        separator.dataset.chatDay = key;
        separator.setAttribute('role', 'separator');
        separator.setAttribute('aria-label', 'Aujourd\'hui');
        const label = document.createElement('span');
        label.textContent = 'Aujourd\'hui';
        separator.appendChild(label);
        stream.appendChild(separator);
    }

    /** "Report this message" link (same rendering as the channel template). */
    buildReportLink(messageId) {
        const link = document.createElement('a');
        link.href = this.reportUrlValue.replace('__ID__', String(Number(messageId)));
        link.rel = 'nofollow';
        link.className = 'reveal-on-hover inline-flex text-muted hover:text-danger-text';
        link.setAttribute('aria-label', 'Signaler ce message');
        link.title = 'Signaler ce message';
        link.innerHTML = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="size-3.5" aria-hidden="true"><path d="M4 15s1-1 4-1 5 2 8 2 4-1 4-1V3s-1 1-4 1-5-2-8-2-4 1-4 1z"/><path d="M4 22v-7"/></svg>';
        return link;
    }

    /** Author's title ({name, tier, icon}), same rendering as templates/gamification/_user_title.html.twig. */
    buildTitle(title) {
        if (!title || !title.name) return null;
        const tier = ['bronze', 'silver', 'gold', 'premium', 'honorary'].includes(title.tier) ? title.tier : 'bronze';
        const pill = document.createElement('span');
        pill.className = `user-title badge-tier-${tier}`;
        pill.title = `Titre : ${title.name}`;
        const icon = document.createElement('span');
        icon.className = 'user-title-icon';
        icon.setAttribute('aria-hidden', 'true');
        icon.textContent = String(title.icon ?? '');
        const name = document.createElement('span');
        name.className = 'user-title-name';
        name.textContent = String(title.name);
        pill.append(icon, name);
        return pill;
    }
}
