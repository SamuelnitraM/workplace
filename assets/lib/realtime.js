/*
 * Real time and client-side shared state (singleton module).
 *
 * Turbo Drive keeps the JavaScript context between pages: this module is evaluated once and keeps the Pusher
 * connection, the subscriptions (reference counted) and the state of the controllers (messenger…).
 * Everything is reset when the signed-in user changes.
 *
 * Configuration read from the <meta> tags of the <head> (rendered for a signed-in user only):
 *   hf-user-id, hf-pusher-key, hf-pusher-cluster, hf-pusher-auth, hf-csrf-<id>
 * The Pusher library is served by the importmap from the site itself.
 */

import Pusher from 'pusher-js';

/**
 * Grace period before a channel is actually unsubscribed: during a Turbo visit the controllers of the new page
 * (lazy ones included, loaded asynchronously) subscribe again to the same channels without losing events.
 */
const UNSUBSCRIBE_DELAY = 1000;

let session = null;

export function meta(name) {
    return document.querySelector(`meta[name="${name}"]`)?.content || '';
}

export function csrfToken(id) {
    return meta(`hf-csrf-${id}`);
}

export function esc(value) {
    return String(value ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' }[c]));
}

/** Session of the current user (null when signed out); starts from scratch when the user changes. */
function currentSession() {
    const userId = Number(meta('hf-user-id')) || null;
    if (session && session.userId !== userId) {
        teardown();
    }
    if (!session && userId) {
        session = {
            userId,
            pusher: null,
            refs: new Map(),
            timers: new Map(),
            store: new Map(),
            active: new Map(),
            counts: {},
        };
    }
    return session;
}

function teardown() {
    if (!session) return;
    session.timers.forEach(timer => clearTimeout(timer));
    if (session.pusher) session.pusher.disconnect();
    session = null;
    applyTitle();
}

export function currentUserId() {
    return currentSession()?.userId ?? null;
}

export function userChannelName(userId = currentUserId()) {
    return `private-user-${userId}`;
}

function pusherFor(s) {
    if (!s.pusher) {
        const key = meta('hf-pusher-key');
        if (!key) return null;
        s.pusher = new Pusher(key, {
            cluster: meta('hf-pusher-cluster'),
            channelAuthorization: { endpoint: meta('hf-pusher-auth'), transport: 'ajax' },
        });
    }
    return s.pusher;
}

function acquire(s, name) {
    const pusher = pusherFor(s);
    if (!pusher) return null;
    clearTimeout(s.timers.get(name));
    s.timers.delete(name);
    s.refs.set(name, (s.refs.get(name) || 0) + 1);
    return pusher.subscribe(name);
}

function release(s, name) {
    const remaining = (s.refs.get(name) || 0) - 1;
    if (remaining > 0) {
        s.refs.set(name, remaining);
        return;
    }
    s.refs.delete(name);
    clearTimeout(s.timers.get(name));
    s.timers.set(name, setTimeout(() => {
        s.timers.delete(name);
        if (!s.refs.has(name) && s.pusher) s.pusher.unsubscribe(name);
    }, UNSUBSCRIBE_DELAY));
}

/**
 * Listens to an event of a channel (subscription shared between controllers).
 * Returns a function that removes the listener and releases the subscription (to call in disconnect()).
 */
export function listen(channelName, eventName, handler) {
    const s = currentSession();
    const channel = s ? acquire(s, channelName) : null;
    if (!channel) return () => {};

    channel.bind(eventName, handler);
    let released = false;
    return () => {
        if (released) return;
        released = true;
        channel.unbind(eventName, handler);
        if (session === s) release(s, channelName);
    };
}

/** State kept across Turbo visits (specific to the signed-in user). */
export function store(key, init) {
    const s = currentSession();
    if (!s) return init();
    if (!s.store.has(key)) s.store.set(key, init());
    return s.store.get(key);
}

/**
 * Context displayed by the current page (e.g. 'conversation' => id, 'notificationKey' => aggregation key):
 * the messenger and the notifications use it to ignore what the user already sees.
 */
export function setActive(kind, value) {
    currentSession()?.active.set(kind, value);
}

export function clearActive(kind, value) {
    const s = currentSession();
    if (s && s.active.get(kind) === value) s.active.delete(kind);
}

export function getActive(kind) {
    return currentSession()?.active.get(kind) ?? null;
}

/** Global counters ('notifications', 'messages'): badges and « (N) » prefix of the title. */
export function setCount(kind, value) {
    const s = currentSession();
    if (!s) return;
    s.counts[kind] = Math.max(0, Number(value) || 0);
    applyTitle();
    document.dispatchEvent(new CustomEvent('hf:counts', { detail: { ...s.counts } }));
}

export function getCount(kind) {
    return session?.counts[kind];
}

function applyTitle() {
    const total = session ? Object.values(session.counts).reduce((sum, n) => sum + n, 0) : 0;
    const base = document.title.replace(/^\(\d+\+?\)\s/, '');
    const title = total > 0 ? `(${total > 99 ? '99+' : total}) ${base}` : base;
    if (document.title !== title) document.title = title;
}

// Obsolete local key of the group counters (group activity comes from the server notifications)
try {
    localStorage.removeItem('hf-group-notifications');
} catch (e) { /* storage unavailable */ }

// Turbo replaces the title on each visit: the prefix is applied again (and a change of user is detected)
document.addEventListener('turbo:load', () => {
    currentSession();
    applyTitle();
});

/** POST request protected by CSRF (X-CSRF-Token header), JSON response expected. */
export function postJson(url, csrfId, body = null, options = {}) {
    return fetch(url, {
        method: 'POST',
        headers: {
            'X-CSRF-Token': csrfToken(csrfId),
            'X-Requested-With': 'XMLHttpRequest',
            Accept: 'application/json',
        },
        body,
        ...options,
    });
}
