import { Controller } from '@hotwired/stimulus';
import { listen, setActive, clearActive, postJson, visit } from '../lib/realtime.js';

/* stimulusFetch: 'lazy' */

/*
 * Discussion en temps réel (conversation privée ou channel de groupe).
 *
 * - abonnement au canal Pusher privé à la connexion, désabonnement à la déconnexion (visites Turbo comprises) ;
 * - envoi AJAX : le message est affiché depuis la réponse du serveur ;
 * - messages construits avec textContent (jamais interprétés comme du HTML), sauf contentHtml : contenu échappé
 *   par le serveur (App\Text\MentionResolver::linkify) où seules les mentions @pseudo sont des liens ;
 * - signale au messenger / aux notifications le contenu affiché (activeConversation, notificationKey).
 */
export default class extends Controller {
    static targets = ['messages', 'scrollButton', 'scrollBadge', 'form', 'input', 'empty'];
    static values = {
        channel: String,          // canal Pusher privé (vide : pas encore de conversation)
        sendUrl: String,
        userId: Number,
        avatarBase: String,
        showAuthor: { type: Boolean, default: false },
        conversationId: Number,   // conversation privée affichée (0 : aucune)
        readUrl: String,          // marquer la conversation comme lue à la réception d'un message
        readCsrf: { type: String, default: 'private-message' },
        notificationKey: String,  // clé des notifications correspondant au contenu affiché
        reloadAfterFirstMessage: { type: Boolean, default: false },
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

    // ─── Défilement ────────────────────────────────────────
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

    // ─── Envoi ─────────────────────────────────────────────
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
                // Première conversation créée : recharger pour s'abonner au canal temps réel
                if (this.reloadAfterFirstMessageValue) visit(window.location.href, { action: 'replace' });
            })
            .catch(() => { input.value = content; });
    }

    // ─── Rendu ─────────────────────────────────────────────
    appendMessage(data) {
        if (!this.hasMessagesTarget || this.messagesTarget.querySelector(`#msg-${Number(data.id)}`)) return; // déjà affiché
        const isCurrentUser = data.authorId === this.userIdValue;
        const wasAtBottom = this.isAtBottom();

        if (this.hasEmptyTarget) this.emptyTarget.remove();

        this.ensureDaySeparator();

        // Classes du design system (assets/styles/app.css, section « Forum & communication » : .chat-msg, .chat-bubble…)
        const msgDiv = document.createElement('div');
        msgDiv.className = `chat-msg${isCurrentUser ? ' chat-msg-own' : ''}`;
        msgDiv.id = `msg-${Number(data.id)}`;

        // Conversation privée : pas d'avatar pour ses propres messages ; salon de groupe (showAuthor) : avatar pour tous
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
        } else {
            // createdAt = « jj/mm HH:ii » : l'heure suffit sous le séparateur de jour
            meta.textContent = String(data.createdAt ?? '').split(' ').pop();
        }
        // La bulle suit immédiatement la ligne meta (pinned_controller s'appuie sur meta.nextElementSibling)
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
        // Permet à d'autres contrôleurs (ex. pinned) de compléter le message ajouté (bouton épingler…)
        this.dispatch('appended', { detail: { element: msgDiv, meta, data } });

        if (wasAtBottom || isCurrentUser) {
            this.scrollToBottom();
        } else if (this.hasScrollBadgeTarget) {
            this.scrollBadgeTarget.classList.remove('hidden');
        }
    }

    /**
     * Séparateur « Aujourd'hui » avant un message ajouté en direct, si le fil affiche des séparateurs de jour
     * (.chat-day[data-chat-day], cf. templates/private_message/show.html.twig) ou s'il était vide.
     */
    ensureDaySeparator() {
        const stream = this.messagesTarget;
        const days = stream.querySelectorAll('[data-chat-day]');
        if (days.length === 0 && stream.querySelector('[id^="msg-"]')) return; // fil sans séparateurs
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

    /** Titre de l'auteur ({name, tier, icon}), même rendu que templates/gamification/_user_title.html.twig. */
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
