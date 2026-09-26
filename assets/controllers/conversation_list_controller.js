import { Controller } from '@hotwired/stimulus';
import { listen, userChannelName, visit } from '../lib/realtime.js';

/*
 * Private conversations page: the list is ordered by most recent activity and stays so live.
 * Each "private-message" event of the member's channel moves the conversation to the top and refreshes
 * its preview, time and unread counter; a conversation not listed yet reloads the list from the server.
 *
 * Usage: <ul data-controller="conversation-list"> <li data-conversation-id="…"> … [data-conversation-preview]
 *        [data-conversation-time] [data-conversation-unread] [data-conversation-name] </li> </ul>
 */
export default class extends Controller {
    connect() {
        this.stopListening = listen(userChannelName(), 'private-message', data => this.onMessage(data));
    }

    disconnect() {
        this.stopListening?.();
    }

    onMessage(data) {
        const row = this.element.querySelector(`[data-conversation-id="${Number(data.conversationId)}"]`);
        if (!row) {
            visit(window.location.href, { action: 'replace' });
            return;
        }
        this.element.prepend(row);
        const preview = row.querySelector('[data-conversation-preview]');
        preview.textContent = String(data.content ?? '');
        preview.classList.replace('text-fg-secondary', 'text-fg');
        const time = row.querySelector('[data-conversation-time]');
        time.textContent = "à l'instant";
        time.dateTime = new Date().toISOString();
        time.classList.remove('text-muted');
        time.classList.add('font-semibold', 'text-primary-text');
        row.querySelector('[data-conversation-name]')?.classList.add('font-bold');
        const unread = row.querySelector('[data-conversation-unread]');
        const count = Number(unread.dataset.count || 0) + 1;
        unread.dataset.count = String(count);
        unread.firstChild.textContent = count > 99 ? '99+' : String(count);
        unread.classList.remove('hidden');
    }
}
