import { Controller } from '@hotwired/stimulus';

/*
 * Theme of the site: « light », « dark » or « auto » (preference of the system, followed live by CSS through
 * color-scheme and light-dark(), see assets/styles/app.css). The choice is applied at once on <html data-theme>,
 * kept for a year in a cookie read by the server (no flash at the next page), and shared with every switch of the page.
 *
 * Usage: templates/_partials/_theme_switch.html.twig
 */
const THEME_EVENT = 'theme:changed';
const COOKIE_LIFETIME_SECONDS = 365 * 24 * 3600;

export default class extends Controller {
    static values = { cookie: String };

    connect() {
        this.syncWith = event => this.check(event.detail.theme);
        window.addEventListener(THEME_EVENT, this.syncWith);
    }

    disconnect() {
        window.removeEventListener(THEME_EVENT, this.syncWith);
    }

    choose(event) {
        const theme = event.target.value;
        if (theme === 'auto') {
            delete document.documentElement.dataset.theme;
        } else {
            document.documentElement.dataset.theme = theme;
        }
        this.updateBrowserColor(theme);
        document.cookie = `${this.cookieValue}=${theme}; path=/; max-age=${COOKIE_LIFETIME_SECONDS}; SameSite=Lax`;
        window.dispatchEvent(new CustomEvent(THEME_EVENT, { detail: { theme } }));
    }

    // Colour of the browser interface (mobile): the colour of each scheme in automatic mode, the chosen one otherwise
    updateBrowserColor(theme) {
        const chosen = document.querySelector(`meta[name="theme-color"][media*="${theme}"]`);
        document.querySelectorAll('meta[name="theme-color"][data-scheme-color]').forEach(meta => {
            meta.content = theme === 'auto' || !chosen ? meta.dataset.schemeColor : chosen.dataset.schemeColor;
        });
    }

    check(theme) {
        this.element.querySelectorAll('input[type="radio"]').forEach(input => {
            input.checked = input.value === theme;
        });
    }
}
