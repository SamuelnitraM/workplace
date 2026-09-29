import { Controller } from '@hotwired/stimulus';

/*
 * Activity signal (« online »): a POST at a regular interval while the tab is visible, and at once when the tab
 * becomes visible again (back on the tab, computer waking up). The interval is inherently time-based.
 * A single interval for the whole session, even though Turbo connects the controller again on each visit:
 * once the last instance is gone, the interval stops at the next page load unless that page connects one again.
 *
 * Usage: <div data-controller="heartbeat" data-heartbeat-url-value="/heartbeat" data-heartbeat-interval-value="30000" hidden></div>
 */
let timer = null;
let instances = 0;
let url = null;
let stopPending = false;

function onVisibilityChange() {
    if (document.visibilityState === 'visible') beat();
}

function beat() {
    if (!url || document.visibilityState !== 'visible') return;
    fetch(url, { method: 'POST', headers: { 'X-Requested-With': 'XMLHttpRequest' } }).catch(() => {});
}

function stopIfUnused() {
    stopPending = false;
    if (instances > 0 || !timer) return;
    clearInterval(timer);
    timer = null;
    document.removeEventListener('visibilitychange', onVisibilityChange);
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
        // During a Turbo visit the new page connects the controller again before "turbo:load"
        if (instances === 0 && !stopPending) {
            stopPending = true;
            document.addEventListener('turbo:load', stopIfUnused, { once: true });
        }
    }
}
