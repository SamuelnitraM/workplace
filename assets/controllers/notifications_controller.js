import { Controller } from '@hotwired/stimulus';
import { icon } from '../lib/icon.js';
import { skeletonRows } from '../lib/skeleton.js';
import { listen, userChannelName, setCount, getCount, getActive, postJson } from '../lib/realtime.js';
import { visit } from '../lib/turbo.js';
import { playNotificationSound } from '../lib/sounds.js';
import { latestRequest, isAbortError } from '../lib/http.js';

/*
 * Notification bell (navigation bar): unread badge, dropdown of the latest notifications, « Tout marquer comme lu »,
 * real-time update ("notification" event of the private channel of the user).
 * Only the answer of the latest loading of the dropdown is used.
 *
 * All the content coming from users is inserted through textContent (never innerHTML).
 */
const CSRF_ID = 'notification';

export default class extends Controller {
    static targets = ['button', 'badge', 'panel', 'list', 'markAll'];
    static values = {
        recentUrl: String,
        readUrl: String,      // URL of /notifications/0/read (the id is replaced)
        readAllUrl: String,
        avatarBase: String,
        unread: Number,       // counter rendered by the server
        fresh: { type: Boolean, default: true }, // false in a snapshot of the Turbo cache
    };

    connect() {
        this.items = null;
        this.open = false;
        this.request = latestRequest();
        // Page restored from the Turbo cache: the counter known by the client is more recent
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
        this.request.abort();
    }

    // ─── Dropdown ──────────────────────────────────────────
    toggle(event) {
        // The button is a link to /notifications: without JavaScript, it leads to the full page
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
        if (!this.items) this.listTarget.replaceChildren(skeletonRows(3));
        fetch(this.recentUrlValue, { headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, signal: this.request.next() })
            .then(r => (r.ok ? r.json() : Promise.reject(r)))
            .then(data => {
                this.items = data.notifications || [];
                this.setUnread(data.unreadCount);
                this.render();
            })
            .catch(error => {
                if (!isAbortError(error) && !this.items) this.renderMessage('Impossible de charger les notifications.');
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
        if (event.type === 'auxclick' && event.button !== 1) return; // right click: context menu
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

    // ─── Real time ─────────────────────────────────────────
    onNotification(data) {
        const notification = data?.notification;
        if (!notification) return;

        // The user already looks at this content (e.g. the open group channel): read at once
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

    // ─── Rendering ─────────────────────────────────────────
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
            + (item.read ? 'hover:bg-hover' : 'bg-primary-soft hover:bg-hover');

        const avatar = document.createElement('div');
        avatar.className = 'avatar avatar-sm';   // design system (.avatar: photo or initial)
        if (item.actor?.avatar) {
            const img = document.createElement('img');
            img.src = this.avatarBaseValue + encodeURIComponent(item.actor.avatar);
            img.alt = '';
            avatar.appendChild(img);
        } else {
            const initial = document.createElement('span');
            initial.setAttribute('aria-hidden', 'true');
            if (item.actor?.username) {
                initial.textContent = item.actor.username.charAt(0).toUpperCase();
            } else {
                initial.append(icon(item.icon || 'bell', 'size-4'));
            }
            avatar.appendChild(initial);
        }

        const body = document.createElement('div');
        body.className = 'flex-1 min-w-0';
        const text = document.createElement('p');
        text.className = `text-sm break-words ${item.read ? 'text-fg-secondary' : 'text-fg'}`;
        text.textContent = item.text;
        const meta = document.createElement('p');
        meta.className = 'flex items-center gap-1 text-xs text-muted mt-0.5';
        meta.append(icon(item.icon || 'bell', 'size-3.5'), timeAgo(item.updatedAt || item.createdAt));
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

/** Relative date in French (« à l'instant », « il y a 5 min »…), same as the Twig filter time_ago. */
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
