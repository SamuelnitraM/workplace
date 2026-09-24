import { Controller } from '@hotwired/stimulus';
import { listen, userChannelName, setCount, getCount, getActive, postJson, visit } from '../lib/realtime.js';
import { playNotificationSound } from '../lib/sounds.js';

/*
 * Cloche de notifications (barre de navigation) : badge des non-lues, menu déroulant des
 * dernières notifications, « Tout marquer comme lu », mise à jour en temps réel (évènement
 * « notification » du canal privé de l'utilisateur).
 *
 * Tout le contenu provenant des utilisateurs est inséré via textContent (jamais innerHTML).
 */
const CSRF_ID = 'notification';

export default class extends Controller {
    static targets = ['button', 'badge', 'panel', 'list', 'markAll'];
    static values = {
        recentUrl: String,
        readUrl: String,      // URL de /notifications/0/read (l'identifiant est remplacé)
        readAllUrl: String,
        avatarBase: String,
        unread: Number,       // compteur rendu côté serveur
        fresh: { type: Boolean, default: true }, // faux dans un instantané du cache Turbo
    };

    connect() {
        this.items = null;
        this.open = false;
        // Page restaurée depuis le cache Turbo : le compteur connu côté client est plus récent
        const known = getCount('notifications');
        this.setUnread(!this.freshValue && known !== undefined ? known : this.unreadValue);
        this.onBeforeCache = () => {
            this.close();
            this.freshValue = false;
        };
        document.addEventListener('turbo:before-cache', this.onBeforeCache);

        this.stopListening = listen(userChannelName(), 'notification', data => this.onNotification(data));

        this.onDocumentClick = event => {
            if (this.open && !this.element.contains(event.target)) this.close();
        };
        this.onKeydown = event => {
            if (event.key === 'Escape' && this.open) {
                this.close();
                this.buttonTarget.focus();
            }
        };
        document.addEventListener('click', this.onDocumentClick);
        document.addEventListener('keydown', this.onKeydown);
    }

    disconnect() {
        this.stopListening?.();
        document.removeEventListener('click', this.onDocumentClick);
        document.removeEventListener('keydown', this.onKeydown);
        document.removeEventListener('turbo:before-cache', this.onBeforeCache);
    }

    // ─── Menu déroulant ────────────────────────────────────
    toggle(event) {
        // Le bouton est un lien vers /notifications : sans JavaScript, il mène à la page complète
        event.preventDefault();
        this.open ? this.close() : this.show();
    }

    show() {
        this.open = true;
        this.panelTarget.classList.remove('hidden');
        this.buttonTarget.setAttribute('aria-expanded', 'true');
        this.load();
    }

    close() {
        this.open = false;
        this.panelTarget.classList.add('hidden');
        this.buttonTarget.setAttribute('aria-expanded', 'false');
    }

    load() {
        if (!this.items) this.renderMessage('Chargement…');
        fetch(this.recentUrlValue, { headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' } })
            .then(r => (r.ok ? r.json() : Promise.reject(r)))
            .then(data => {
                this.items = data.notifications || [];
                this.setUnread(data.unreadCount);
                this.render();
            })
            .catch(() => {
                if (!this.items) this.renderMessage('Impossible de charger les notifications.');
            });
    }

    // ─── Actions ───────────────────────────────────────────
    markAllRead() {
        postJson(this.readAllUrlValue, CSRF_ID)
            .then(r => (r.ok ? r.json() : Promise.reject(r)))
            .then(() => {
                (this.items || []).forEach(item => { item.read = true; });
                this.setUnread(0);
                this.render();
            })
            .catch(() => {});
    }

    openItem(event) {
        if (event.type === 'auxclick' && event.button !== 1) return; // clic droit : menu contextuel
        const link = event.target.closest('a[data-notification-id]');
        if (!link) return;
        const item = (this.items || []).find(n => n.id === Number(link.dataset.notificationId));
        if (!item) return;

        const newTab = event.ctrlKey || event.metaKey || event.shiftKey || event.button === 1;
        if (!newTab) event.preventDefault();

        const done = () => { if (!newTab) visit(item.url); };
        if (item.read) {
            done();
            return;
        }

        item.read = true;
        this.markRead(item.id, { keepalive: true }).finally(done);
    }

    markRead(id, options = {}) {
        return postJson(this.readUrlValue.replace(/\/0\/read$/, `/${Number(id)}/read`), CSRF_ID, null, options)
            .then(r => (r.ok ? r.json() : Promise.reject(r)))
            .then(data => this.setUnread(data.unreadCount))
            .catch(() => {});
    }

    // ─── Temps réel ────────────────────────────────────────
    onNotification(data) {
        const notification = data?.notification;
        if (!notification) return;

        // L'utilisateur regarde déjà ce contenu (ex. le channel de groupe ouvert) : lue immédiatement
        if (notification.groupKey && notification.groupKey === getActive('notificationKey')) {
            this.markRead(notification.id);
            return;
        }

        this.setUnread(data.unreadCount);
        this.badgeTarget.classList.add('notification-blink');
        playNotificationSound();

        if (this.items) {
            this.items = [notification, ...this.items.filter(n => n.id !== notification.id)].slice(0, 15);
            this.render();
        }
    }

    // ─── Rendu ─────────────────────────────────────────────
    setUnread(count) {
        const value = Math.max(0, Number(count) || 0);
        this.badgeTarget.textContent = value > 99 ? '99+' : String(value);
        this.badgeTarget.classList.toggle('hidden', value === 0);
        if (value === 0) this.badgeTarget.classList.remove('notification-blink');
        if (this.hasMarkAllTarget) this.markAllTarget.disabled = value === 0;
        this.buttonTarget.setAttribute('aria-label', value > 0 ? `Notifications (${value} non lues)` : 'Notifications');
        setCount('notifications', value);
    }

    renderMessage(text) {
        const p = document.createElement('p');
        p.className = 'text-muted text-sm text-center py-6';
        p.textContent = text;
        this.listTarget.replaceChildren(p);
    }

    render() {
        if (!this.items || this.items.length === 0) {
            this.renderMessage('Aucune notification pour le moment.');
            return;
        }
        this.listTarget.replaceChildren(...this.items.map(item => this.buildItem(item)));
    }

    buildItem(item) {
        const link = document.createElement('a');
        link.href = item.url;
        link.dataset.notificationId = String(item.id);
        link.className = 'flex items-start gap-3 px-4 py-3 border-b border-line last:border-b-0 transition '
            + (item.read ? 'hover:bg-white/5' : 'bg-primary-soft hover:bg-white/5');

        const avatar = document.createElement('div');
        avatar.className = 'avatar avatar-sm';   // design system (.avatar : photo ou initiale)
        if (item.actor?.avatar) {
            const img = document.createElement('img');
            img.src = this.avatarBaseValue + encodeURIComponent(item.actor.avatar);
            img.alt = '';
            avatar.appendChild(img);
        } else {
            const initial = document.createElement('span');
            initial.setAttribute('aria-hidden', 'true');
            initial.textContent = item.actor?.username ? item.actor.username.charAt(0).toUpperCase() : (item.icon || '🔔');
            avatar.appendChild(initial);
        }

        const body = document.createElement('div');
        body.className = 'flex-1 min-w-0';
        const text = document.createElement('p');
        text.className = `text-sm break-words ${item.read ? 'text-fg-secondary' : 'text-fg'}`;
        text.textContent = item.text;
        const meta = document.createElement('p');
        meta.className = 'text-xs text-muted mt-0.5';
        meta.textContent = `${item.icon || ''} ${timeAgo(item.updatedAt || item.createdAt)}`.trim();
        body.append(text, meta);

        link.append(avatar, body);

        if (!item.read) {
            const dot = document.createElement('span');
            dot.className = 'w-2 h-2 mt-2 rounded-full bg-primary-text shrink-0';
            dot.setAttribute('aria-label', 'Non lue');
            link.appendChild(dot);
        }

        return link;
    }
}

/** Date relative en français (« à l'instant », « il y a 5 min »…), identique au filtre Twig time_ago. */
export function timeAgo(iso) {
    const date = new Date(iso);
    if (Number.isNaN(date.getTime())) return '';
    const seconds = Math.max(0, Math.floor((Date.now() - date.getTime()) / 1000));
    if (seconds < 60) return 'à l\'instant';
    if (seconds < 3600) return `il y a ${Math.floor(seconds / 60)} min`;
    if (seconds < 86400) return `il y a ${Math.floor(seconds / 3600)} h`;
    if (seconds < 7 * 86400) return `il y a ${Math.floor(seconds / 86400)} j`;
    return `le ${date.toLocaleDateString('fr-FR')}`;
}
