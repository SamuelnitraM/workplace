/*
 * Temps réel et état partagé côté client (module singleton).
 *
 * Turbo Drive conserve le contexte JavaScript entre les pages : ce module n'est évalué qu'une
 * fois et garde la connexion Pusher, les abonnements (avec compteur de références) et l'état
 * des contrôleurs (messenger…). Tout est réinitialisé si l'utilisateur connecté change.
 *
 * Configuration lue dans les balises <meta> du <head> (rendues uniquement pour un utilisateur connecté) :
 *   hf-user-id, hf-pusher-key, hf-pusher-cluster, hf-pusher-auth, hf-csrf-<id>
 * La bibliothèque Pusher est chargée depuis le CDN (global window.Pusher).
 */

/** Délai avant un désabonnement effectif : lors d'une visite Turbo, la nouvelle page se réabonne aussitôt. */
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

/** Session de l'utilisateur courant (null si déconnecté) ; repart de zéro si l'utilisateur a changé. */
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
        if (typeof window.Pusher !== 'function' || !key) return null;
        s.pusher = new window.Pusher(key, {
            cluster: meta('hf-pusher-cluster'),
            channelAuthorization: { endpoint: meta('hf-pusher-auth'), transport: 'ajax' },
        });
    }
    return s.pusher;
}

export function getPusher() {
    const s = currentSession();
    return s ? pusherFor(s) : null;
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
 * Écoute un évènement sur un canal (abonnement partagé entre contrôleurs).
 * Retourne une fonction qui retire l'écouteur et libère l'abonnement (à appeler dans disconnect()).
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

/** État conservé entre les visites Turbo (propre à l'utilisateur connecté). */
export function store(key, init) {
    const s = currentSession();
    if (!s) return init();
    if (!s.store.has(key)) s.store.set(key, init());
    return s.store.get(key);
}

/**
 * Contexte affiché par la page courante (ex. 'conversation' => id, 'notificationKey' => clé d'agrégation) :
 * le messenger et les notifications l'utilisent pour ignorer ce que l'utilisateur voit déjà.
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

/** Compteurs globaux ('notifications', 'messages') : badges et préfixe « (N) » du titre. */
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

// Anciens compteurs de groupes stockés localement (remplacés par les notifications serveur)
try {
    localStorage.removeItem('hf-group-notifications');
} catch (e) { /* stockage indisponible */ }

// Turbo remplace le titre à chaque visite : on réapplique le préfixe (et on détecte un changement d'utilisateur)
document.addEventListener('turbo:load', () => {
    currentSession();
    applyTitle();
});

/** Requête POST protégée par CSRF (en-tête X-CSRF-Token), réponse JSON attendue. */
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

/** Navigation (Turbo si disponible). */
export function visit(url, options = {}) {
    if (window.Turbo?.visit) {
        window.Turbo.visit(url, options);
    } else {
        window.location.href = url;
    }
}
