import { Controller } from '@hotwired/stimulus';
import { skeletonRowsHtml } from '../lib/skeleton.js';
import { esc, listen, userChannelName, currentUserId, store, getActive, setCount, getCount, postJson } from '../lib/realtime.js';
import { playNotificationSound } from '../lib/sounds.js';
import { latestRequest } from '../lib/http.js';
import { icon } from '../lib/icon.js';

/*
 * Messenger (chat tabs docked at the bottom of the screen).
 *
 * The state (open tabs, loaded messages, carousel) is kept across Turbo visits in the module lib/realtime.js;
 * the DOM is rebuilt each time the controller connects (the main tab starts collapsed).
 * The "private-message" events arrive on the private channel of the user.
 *
 * The global counter of unread private messages ("messages" badges) mirrors the server:
 * it is reloaded on each page and after each read, and incremented in real time.
 * Each kind of request only keeps its latest answer (the list, the context, the messages of a conversation).
 *
 * Every user data is escaped (esc) before insertion; no data in onclick attributes.
 * Message content is inserted from contentHtml, already escaped by the server (App\Text\MentionResolver::linkify,
 * only the @pseudo mentions are links); esc(content) is the fallback.
 */
const CSRF_ID = 'private-message';
// Decorative icons of the design system, as markup for the tab templates
const ICON_X = icon('x', '').outerHTML;
const ICON_SEND = icon('send', '').outerHTML;
const ICON_CHEVRON_DOWN = icon('chevron-down', 'size-4').outerHTML;
const ICON_CHEVRON_UP = icon('chevron-up', 'size-4').outerHTML;

export default class extends Controller {
    static targets = ['prev', 'next', 'tabs', 'mainBody', 'mainArrow', 'mainToggle', 'conversations'];
    static values = {
        conversationsUrl: String,
        messagesUrl: String,   // contains __USER__
        sendUrl: String,       // contains __USER__
        readUrl: String,       // read URL of conversation 0 (the id is replaced)
        contextUrl: String,
        avatarBase: String,
    };

    connect() {
        this.state = store('messenger', () => ({
            mainOpen: false,
            openConvs: [],      // { key, username, avatar, convId, open, messages, loaded, unread }
            visibleStart: 0,
            maxVisible: 3,
            nextKey: 1,
        }));
        this.state.mainOpen = false;
        this.renderMain();
        this.conversationsRequest = latestRequest();
        this.contextRequest = latestRequest();
        this.messagesRequests = new Map();

        this.stopListening = listen(userChannelName(), 'private-message', data => this.onPrivateMessage(data));

        // Entry point for the other scripts: document.dispatchEvent(new CustomEvent('messenger:open', { detail: { username, avatar, conversationId } }))
        this.onOpenRequest = event => {
            const { username, avatar, conversationId } = event.detail || {};
            if (username) this.openConversation(String(username), avatar || '', Number(conversationId) || null);
        };
        document.addEventListener('messenger:open', this.onOpenRequest);

        const known = getCount('messages');
        if (known !== undefined) setCount('messages', known);

        this.renderTabs();
        // Messages whose loading was interrupted by the previous page
        this.state.openConvs.filter(conv => conv.open && !conv.loaded).forEach(conv => this.loadMessages(conv));
        this.loadNotificationContext();
    }

    disconnect() {
        this.saveDrafts();
        this.stopListening?.();
        document.removeEventListener('messenger:open', this.onOpenRequest);
        this.conversationsRequest.abort();
        this.contextRequest.abort();
        this.messagesRequests.forEach(request => request.abort());
    }

    // ─── Real-time events ──────────────────────────────────
    onPrivateMessage(data) {
        if (data.authorId === currentUserId()) return;
        // The page of this conversation is open: it handles the display and the read state
        if (getActive('conversation') === data.conversationId) return;

        playNotificationSound();
        const conv = this.state.openConvs.find(c => c.username === data.author);
        if (conv) {
            conv.convId = data.conversationId;
            if (conv.loaded) conv.messages.push({ ...data, isCurrentUser: false });
            if (conv.open) {
                this.markConversationRead(data.conversationId);
            } else {
                conv.unread = (conv.unread || 0) + 1;
                this.incrementUnread();
            }
            this.renderTabs();
            if (conv.open) this.scrollChat(conv);
        } else {
            this.incrementUnread();
        }

        if (this.state.mainOpen) this.loadConversations();
    }

    incrementUnread() {
        setCount('messages', (getCount('messages') || 0) + 1);
    }

    loadNotificationContext() {
        fetch(this.contextUrlValue, { headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, signal: this.contextRequest.next() })
            .then(r => (r.ok ? r.json() : Promise.reject(r)))
            .then(context => setCount('messages', context.unreadMessages || 0))
            .catch(() => {});
    }

    markConversationRead(conversationId) {
        return postJson(this.readUrlValue.replace(/\/0$/, `/${Number(conversationId)}`), CSRF_ID)
            .then(() => this.loadNotificationContext())
            .catch(() => {});
    }

    // ─── Main tab ──────────────────────────────────────────
    toggleMain() {
        this.state.mainOpen = !this.state.mainOpen;
        this.renderMain();
        if (this.state.mainOpen) this.loadConversations();
    }

    renderMain() {
        this.mainBodyTarget.classList.toggle('hidden', !this.state.mainOpen);
        this.mainArrowTarget.replaceChildren(icon(this.state.mainOpen ? 'chevron-down' : 'chevron-up', 'size-4'));
        this.mainToggleTarget.setAttribute('aria-expanded', String(this.state.mainOpen));
    }

    loadConversations() {
        fetch(this.conversationsUrlValue, { headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, signal: this.conversationsRequest.next() })
            .then(r => (r.ok ? r.json() : Promise.reject(r)))
            .then(data => {
                if (!this.hasConversationsTarget) return;
                const container = this.conversationsTarget;

                if (data.length === 0) {
                    container.innerHTML = '<div class="text-muted text-sm text-center py-4">Aucune conversation</div>';
                    return;
                }

                container.innerHTML = data.map(conv => `
                    <div class="flex items-center gap-3 px-2 py-2 rounded-lg hover:bg-surface-raised cursor-pointer transition group"
                         data-open-conversation="${esc(conv.username)}"
                         data-avatar="${esc(conv.avatar || '')}"
                         data-conv-id="${Number(conv.id)}">
                        <span class="avatar avatar-sm">
                            ${this.avatarHtml(conv.avatar, conv.username)}
                        </span>
                        <div class="flex-1 min-w-0">
                            <div class="text-sm font-semibold text-fg">${esc(conv.username)}</div>
                            <div class="text-xs text-fg-secondary truncate">${esc(conv.lastMessage)}</div>
                        </div>
                        <div class="flex items-center gap-1 shrink-0">
                            ${conv.unread > 0
                                ? `<span class="count-badge">${Number(conv.unread)}</span>`
                                : ''
                            }
                        </div>
                    </div>
                `).join('');
            })
            .catch(() => {});
    }

    // Delegation: click on a conversation of the list
    openFromList(event) {
        const item = event.target.closest('[data-open-conversation]');
        if (!item) return;
        this.openConversation(item.dataset.openConversation, item.dataset.avatar, Number(item.dataset.convId) || null);
    }

    // ─── Conversation tabs ─────────────────────────────────
    openConversation(username, avatar, convId) {
        const state = this.state;
        const existing = state.openConvs.find(c => c.username === username);
        if (existing) {
            existing.open = true;
            existing.unread = 0;
            this.renderTabs();
            this.loadMessages(existing);
            return;
        }

        const conv = {
            key: state.nextKey++,
            username,
            avatar,
            convId,
            open: true,
            messages: [],
            loaded: false,
            unread: 0,
        };
        state.openConvs.push(conv);

        state.visibleStart = Math.max(0, state.openConvs.length - state.maxVisible);
        this.renderTabs();
        this.loadMessages(conv);

        if (state.mainOpen) this.toggleMain();
    }

    // Loads the messages (the server marks them as read)
    loadMessages(conv) {
        if (!this.messagesRequests.has(conv.key)) this.messagesRequests.set(conv.key, latestRequest());
        fetch(this.messagesUrlValue.replace('__USER__', encodeURIComponent(conv.username)), {
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            signal: this.messagesRequests.get(conv.key).next(),
        })
            .then(r => (r.ok ? r.json() : Promise.reject(r)))
            .then(data => {
                if (!this.state.openConvs.includes(conv)) return;

                conv.messages = data.messages || [];
                conv.convId = data.conversationId;
                conv.loaded = true;
                conv.unread = 0;

                this.renderTabs();
                this.scrollChat(conv);
                this.loadNotificationContext();
            })
            .catch(() => {});
    }

    findConv(key) {
        return this.state.openConvs.find(c => c.key === Number(key));
    }

    closeConversation(conv) {
        const state = this.state;
        const idx = state.openConvs.indexOf(conv);
        if (idx !== -1) {
            state.openConvs.splice(idx, 1);
            state.visibleStart = Math.min(
                state.visibleStart,
                Math.max(0, state.openConvs.length - state.maxVisible)
            );
        }
        this.messagesRequests.get(conv.key)?.abort();
        this.messagesRequests.delete(conv.key);
        this.renderTabs();
    }

    toggleConversation(conv) {
        conv.open = !conv.open;

        if (conv.open) {
            conv.unread = 0;
            this.loadMessages(conv); // reloads the messages received while collapsed and marks them as read
        }

        this.renderTabs();
        if (conv.open) this.scrollChat(conv);
    }

    sendMessage(conv) {
        const input = this.inputFor(conv.key);
        if (!input) return;
        const content = input.value.trim();
        if (!content) return;

        input.value = '';
        conv.draft = '';
        // The message goes back into the field (or the draft of a collapsed tab) when it could not be sent
        const restore = () => {
            const currentInput = this.inputFor(conv.key);
            if (currentInput && !currentInput.value) currentInput.value = content;
            else if (!currentInput && !conv.draft) conv.draft = content;
        };

        postJson(this.sendUrlValue.replace('__USER__', encodeURIComponent(conv.username)), CSRF_ID, new URLSearchParams({ content }))
            .then(r => r.json())
            .then(data => {
                if (data.error) {
                    alert(data.error);
                    restore();
                    return;
                }

                if (data.conversationId) conv.convId = data.conversationId;
                conv.messages.push({ ...data, isCurrentUser: true });
                this.renderTabs();
                this.scrollChat(conv);
            })
            .catch(restore);
    }

    // Delegation: clicks in the tabs (close, send, expand)
    tabsClick(event) {
        const close = event.target.closest('[data-close-conv]');
        if (close) {
            event.stopPropagation();
            const conv = this.findConv(close.dataset.closeConv);
            if (conv) this.closeConversation(conv);
            return;
        }
        const send = event.target.closest('[data-send-conv]');
        if (send) {
            const conv = this.findConv(send.dataset.sendConv);
            if (conv) this.sendMessage(conv);
            return;
        }
        const toggle = event.target.closest('[data-toggle-conv]');
        if (toggle) {
            const conv = this.findConv(toggle.dataset.toggleConv);
            if (conv) this.toggleConversation(conv);
        }
    }

    tabsKeydown(event) {
        if (event.key !== 'Enter' || !event.target.dataset.inputKey) return;
        const conv = this.findConv(event.target.dataset.inputKey);
        if (conv) this.sendMessage(conv);
    }

    // ─── Carousel ──────────────────────────────────────────
    prev() {
        if (this.state.visibleStart > 0) {
            this.state.visibleStart--;
            this.renderTabs();
        }
    }

    next() {
        if (this.state.visibleStart + this.state.maxVisible < this.state.openConvs.length) {
            this.state.visibleStart++;
            this.renderTabs();
        }
    }

    // ─── Rendering ─────────────────────────────────────────
    /** Keeps the text being typed in each tab (across re-renders and Turbo visits). */
    saveDrafts() {
        if (!this.hasTabsTarget) return;
        this.tabsTarget.querySelectorAll('input[data-input-key]').forEach(input => {
            const conv = this.findConv(input.dataset.inputKey);
            if (conv) conv.draft = input.value;
        });
    }

    inputFor(key) {
        return this.hasTabsTarget ? this.tabsTarget.querySelector(`input[data-input-key="${Number(key)}"]`) : null;
    }

    // Called after renderTabs(): the tab is in the DOM and its height is known
    scrollChat(conv) {
        const chatEl = this.hasTabsTarget ? this.tabsTarget.querySelector(`[data-chat-key="${Number(conv.key)}"]`) : null;
        if (chatEl) chatEl.scrollTop = chatEl.scrollHeight;
    }

    /** Content of an .avatar (design system): photo or initial. */
    avatarHtml(avatar, username) {
        return avatar
            ? `<img src="${esc(this.avatarBaseValue + encodeURIComponent(avatar))}" alt="" loading="lazy">`
            : `<span aria-hidden="true">${esc((username || '?').charAt(0).toUpperCase())}</span>`;
    }

    renderTabs() {
        if (!this.hasTabsTarget) return;
        const state = this.state;
        const container = this.tabsTarget;

        // Keeps the text being typed across the re-render
        this.saveDrafts();
        const focusedKey = container.contains(document.activeElement) ? document.activeElement?.dataset?.inputKey : undefined;

        const needArrows = state.openConvs.length > state.maxVisible;
        this.prevTarget.classList.toggle('hidden', !needArrows || state.visibleStart === 0);
        this.nextTarget.classList.toggle('hidden', !needArrows || state.visibleStart + state.maxVisible >= state.openConvs.length);

        const visible = state.openConvs.slice(state.visibleStart, state.visibleStart + state.maxVisible);

        container.innerHTML = visible.map(conv => {
            const messagesHtml = conv.loaded
                ? (conv.messages.length > 0
                    ? conv.messages.map(msg => `
                        <div class="flex gap-2 ${msg.isCurrentUser ? 'flex-row-reverse' : ''}">
                            <div class="px-3 py-1.5 rounded-xl text-xs max-w-xs break-words ${msg.isCurrentUser ? 'bg-primary text-primary-fg' : 'bg-overlay text-fg'}">${typeof msg.contentHtml === 'string' ? msg.contentHtml : esc(msg.content)}</div>
                        </div>
                    `).join('')
                    : '<div class="text-muted text-xs text-center py-4">Commence la conversation !</div>'
                )
                : skeletonRowsHtml(2);

            const unreadBadge = conv.unread > 0
                ? `<span class="count-badge">${Number(conv.unread)}</span>`
                : '';

            return `
                <div class="w-64">
                    <div class="bg-surface border border-line border-b-0 rounded-t-xl">
                        <div class="flex justify-between items-center px-3 py-2 cursor-pointer hover:bg-surface-raised rounded-t-xl transition"
                             data-toggle-conv="${conv.key}">
                            <div class="flex items-center gap-2">
                                <span class="avatar avatar-xs">
                                    ${this.avatarHtml(conv.avatar, conv.username)}
                                </span>
                                <span class="text-sm font-semibold text-fg truncate">${esc(conv.username)}</span>
                                ${unreadBadge}
                            </div>
                            <div class="flex items-center gap-1">
                                <span class="text-fg-secondary">${conv.open ? ICON_CHEVRON_DOWN : ICON_CHEVRON_UP}</span>
                                <button type="button" data-close-conv="${conv.key}"
                                        class="btn btn-ghost btn-icon btn-sm hover:text-danger-text" aria-label="Fermer la conversation">${ICON_X}</button>
                            </div>
                        </div>
                        ${conv.open ? `
                            <div class="border-t border-line">
                                <div data-chat-key="${conv.key}" class="h-64 overflow-y-auto p-3 space-y-2">
                                    ${messagesHtml}
                                </div>
                                <div class="p-2 border-t border-line flex gap-2">
                                    <div class="min-w-0 flex-1" data-controller="mention-suggest">
                                    <input data-input-key="${conv.key}"
                                           id="messenger-input-${conv.key}"
                                           data-mention-suggest-target="input"
                                           data-action="keydown->mention-suggest#onKeydown input->mention-suggest#onInput blur->mention-suggest#close"
                                           type="text"
                                           maxlength="2000"
                                           placeholder="Message..."
                                           autocomplete="off"
                                           class="input w-full min-h-9 px-2.5 py-1 text-small">
                                    </div>
                                    <div data-controller="emoji-picker" data-emoji-picker-input-value="messenger-input-${conv.key}"></div>
                                    <button type="button" data-send-conv="${conv.key}"
                                            class="btn btn-primary btn-icon btn-sm shrink-0" aria-label="Envoyer">
                                        ${ICON_SEND}
                                    </button>
                                </div>
                            </div>
                        ` : ''}
                    </div>
                </div>
            `;
        }).join('');

        visible.forEach(conv => {
            const input = conv.draft ? this.inputFor(conv.key) : null;
            if (input) input.value = conv.draft;
        });
        if (focusedKey) this.inputFor(focusedKey)?.focus();
    }
}
