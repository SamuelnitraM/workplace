import { Controller } from '@hotwired/stimulus';

/*
 * Signal d'activité (« en ligne ») : un POST à intervalle régulier tant que l'onglet est visible,
 * et immédiatement quand l'onglet redevient visible (retour sur l'onglet, réveil de l'ordinateur).
 * Un seul intervalle pour toute la session, même si Turbo reconnecte le contrôleur à chaque visite.
 *
 * Usage : <div data-controller="heartbeat" data-heartbeat-url-value="/heartbeat" data-heartbeat-interval-value="30000" hidden></div>
 */
let timer = null;
let instances = 0;
let url = null;

function onVisibilityChange() {
    if (document.visibilityState === 'visible') beat();
}

function beat() {
    if (!url || document.visibilityState !== 'visible') return;
    fetch(url, { method: 'POST', headers: { 'X-Requested-With': 'XMLHttpRequest' } }).catch(() => {});
}

export default class extends Controller {
    static values = {
        url: String,
        interval: { type: Number, default: 30000 },
    };

    connect() {
        instances++;
        url = this.urlValue;
        if (!timer) {
            beat();
            timer = setInterval(beat, this.intervalValue);
            document.addEventListener('visibilitychange', onVisibilityChange);
        }
    }

    disconnect() {
        instances--;
        // Arrêt différé : pendant une visite Turbo, la nouvelle page reconnecte le contrôleur aussitôt
        setTimeout(() => {
            if (instances === 0 && timer) {
                clearInterval(timer);
                timer = null;
                document.removeEventListener('visibilitychange', onVisibilityChange);
            }
        }, 1000);
    }
}
