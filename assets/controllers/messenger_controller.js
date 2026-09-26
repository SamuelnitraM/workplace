import { Controller } from '@hotwired/stimulus';
import { esc, listen, userChannelName, currentUserId, store, getActive, setCount, getCount, postJson } from '../lib/realtime.js';
import { playNotificationSound } from '../lib/sounds.js';

/*
 * Messenger (onglets de discussion collés en bas de l'écran).
 *
 * L'état (onglets ouverts, messages chargés, carrousel) est conservé entre les visites Turbo
 * dans le module lib/realtime.js ; le DOM est reconstruit à chaque connexion du contrôleur.
 * Les évènements « private-message » arrivent sur le canal privé de l'utilisateur.
 *
 * Le compteur global des messages privés non lus (badges « messages ») reflète le serveur :
 * il est rechargé à chaque page et après chaque lecture, et incrémenté en temps réel.
 *
 * Toute donnée utilisateur est échappée (esc) avant insertion ; aucune donnée dans des attributs onclick.
 * Message content is inserted from contentHtml, already escaped by the server (App\Text\MentionResolver::linkify,
 * only the @pseudo mentions are links); esc(content) is the fallback.
 */
const CSRF_ID = 'private-message';
// Icônes du design system (templates/_partials/_icon.html.twig), décoratives
const svg = paths => `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">${paths}</svg>`;
const ICON_X = svg('<path d="M18 6 6 18"/><path d="m6 6 12 12"/>');
const ICON_SEND = svg('<path d="M14.536 21.686a.5.5 0 0 0 .937-.024l6.5-19a.496.496 0 0 0-.635-.635l-19 6.5a.5.5 0 0 0-.024.937l7.93 3.18a2 2 0 0 1 1.112 1.11z"/><path d="m21.854 2.147-10.94 10.939"/>');

export default class extends Controller {
    static targets = ['prev', 'next', 'tabs', 'mainBody', 'mainArrow', 'conversations'];
    static values = {
        conversationsUrl: String,
        messagesUrl: String,   // contient __USER__
        sendUrl: String,       // contient __USER__
        readUrl: String,       // URL de lecture pour la conversation 0 (l'identifiant est remplacé)
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
        this.state.mainOpen = false; // le DOM est neuf : l'onglet principal est replié

        this.stopListening = listen(userChannelName(), 'private-message', data => this.onPrivateMessage(data));

        // Point d'entrée pour les autres scripts : document.dispatchEvent(new CustomEvent('messenger:open', { detail: { username, avatar, conversationId } }))
        this.onOpenRequest = event => {
            const { username, avatar, conversationId } = event.detail || {};
            if (username) this.openConversation(String(username), avatar || '', Number(conversationId) || null);
        };
        document.addEventListener('messenger:open', this.onOpenRequest);

        const known = getCount('messages');
        if (known !== undefined) setCount('messages', known);

        this.renderTabs();
        this.loadNotificationContext();
    }

    disconnect() {
        this.saveDrafts();
        this.stopListening?.();
        document.removeEventListener('messenger:open', this.onOpenRequest);
    }

    // ─── Évènements temps réel ─────────────────────────────
    onPrivateMessage(data) {
        if (data.authorId === currentUserId()) return;
        // La page de cette conversation est ouverte : elle gère l'affichage et la lecture
        if (getActive('conversation') === data.conversationId) return;

        playNotificationSound();
        const conv = this.state.openConvs.find(c => c.username === data.author);
        if (conv) {
            conv.convId = data.conversationId;
            if (conv.loaded) conv.messages.push({ ...data, isCurrentUser: false });
            if (conv.open) {
                this.markConversationRead(data.conversationId);
                this.scrollChat(conv);
            } else {
                conv.unread = (conv.unread || 0) + 1;
                this.incrementUnread();
            }
            this.renderTabs();
        } else {
            this.incrementUnread();
        }

        if (this.state.mainOpen) this.loadConversations();
    }

    incrementUnread() {
        setCount('messages', (getCount('messages') || 0) + 1);
    }

    loadNotificationContext() {
        fetch(this.contextUrlValue, { headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' } })
            .then(r => (r.ok ? r.json() : Promise.reject(r)))
            .then(context => setCount('messages', context.unreadMessages || 0))
            .catch(() => {});
    }

    markConversationRead(conversationId) {
        return postJson(this.readUrlValue.replace(/\/0$/, `/${Number(conversationId)}`), CSRF_ID)
            .then(() => this.loadNotificationContext())
            .catch(() => {});
    }

    // ─── Onglet principal ──────────────────────────────────
    toggleMain() {
        this.state.mainOpen = !this.state.mainOpen;

        if (this.state.mainOpen) {
            this.mainBodyTarget.classList.remove('hidden');
            this.mainArrowTarget.textContent = '▼';
            this.loadConversations();
        } else {
            this.mainBodyTarget.classList.add('hidden');
            this.mainArrowTarget.textContent = '▲';
        }
    }

    loadConversations() {
        fetch(this.conversationsUrlValue, { headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' } })
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

    // Délégation : clic sur une conversation de la liste
    openFromList(event) {
        const item = event.target.closest('[data-open-conversation]');
        if (!item) return;
        this.openConversation(item.dataset.openConversation, item.dataset.avatar, Number(item.dataset.convId) || null);
    }

    // ─── Onglets de conversation ───────────────────────────
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

    // Charge les messages (le serveur les marque comme lus)
    loadMessages(conv) {
        fetch(this.messagesUrlValue.replace('__USER__', encodeURIComponent(conv.username)), {
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
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
        this.renderTabs();
    }

    toggleConversation(conv) {
        conv.open = !conv.open;

        if (conv.open) {
            conv.unread = 0;
            this.loadMessages(conv); // recharge les messages reçus pendant le repli et les marque lus
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

        postJson(this.sendUrlValue.replace('__USER__', encodeURIComponent(conv.username)), CSRF_ID, new URLSearchParams({ content }))
            .then(r => r.json())
            .then(data => {
                if (data.error) {
                    alert(data.error);
                    const currentInput = this.inputFor(conv.key);
                    if (currentInput && !currentInput.value) currentInput.value = content;
                    return;
                }

                if (data.conversationId) conv.convId = data.conversationId;
                conv.messages.push({ ...data, isCurrentUser: true });
                this.renderTabs();
                this.scrollChat(conv);
            })
            .catch(() => {});
    }

    // Délégation : clics dans les onglets (fermer, envoyer, déplier)
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

    // ─── Carrousel ─────────────────────────────────────────
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

    // ─── Rendu ─────────────────────────────────────────────
    /** Mémorise la saisie en cours de chaque onglet (conservée lors des re-rendus et des visites Turbo). */
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

    scrollChat(conv) {
        setTimeout(() => {
            const chatEl = this.hasTabsTarget ? this.tabsTarget.querySelector(`[data-chat-key="${Number(conv.key)}"]`) : null;
            if (chatEl) chatEl.scrollTop = chatEl.scrollHeight;
        }, 50);
    }

    /** Contenu d'un .avatar (design system) : photo ou initiale. */
    avatarHtml(avatar, username) {
        return avatar
            ? `<img src="${esc(this.avatarBaseValue + encodeURIComponent(avatar))}" alt="" loading="lazy">`
            : `<span aria-hidden="true">${esc((username || '?').charAt(0).toUpperCase())}</span>`;
    }

    renderTabs() {
        if (!this.hasTabsTarget) return;
        const state = this.state;
        const container = this.tabsTarget;

        // Conserver la saisie en cours lors du re-rendu
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
                    : '<div class="text-muted text-xs text-center py-4">Commencez la conversation !</div>'
                )
                : '<div class="text-muted text-xs text-center py-4">Chargement...</div>';

            const unreadBadge = conv.unread > 0
                ? `<span class="count-badge">${Number(conv.unread)}</span>`
                : '';

            return `
                <div class="w-64">
                    <div class="bg-surface border border-line border-b-0 rounded-t-xl shadow-lg">
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
                                <span class="text-fg-secondary text-xs">${conv.open ? '▼' : '▲'}</span>
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
                                    <input data-input-key="${conv.key}"
                                           id="messenger-input-${conv.key}"
                                           type="text"
                                           maxlength="2000"
                                           placeholder="Message..."
                                           autocomplete="off"
                                           class="input flex-1 min-h-9 px-2.5 py-1 text-small">
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
