import { Controller } from '@hotwired/stimulus';

/*
 * Share of a page: the share sheet of the system (Web Share API, phones and some desktop browsers),
 * otherwise the link is copied to the clipboard with a confirmation in the "label" target.
 * Once shared or copied, the « share » activity of a member is recorded (badge), when "activityUrl" is set.
 *
 * Usage: templates/_partials/_share_button.html.twig
 */
export default class extends Controller {
    static targets = ['label'];
    static values = { url: String, title: String, activityUrl: String, token: String };

    async share() {
        const shared = navigator.share ? await this.shareWithSystem() : await this.copyLink();
        if (shared) this.recordActivity();
    }

    async shareWithSystem() {
        try {
            await navigator.share({ title: this.titleValue, url: this.urlValue });
            return true;
        } catch (error) {
            // AbortError: the member closed the share sheet
            return error.name === 'AbortError' ? false : this.copyLink();
        }
    }

    async copyLink() {
        try {
            await navigator.clipboard.writeText(this.urlValue);
        } catch {
            // Clipboard API unavailable (insecure context): the link is offered for a manual copy
            window.prompt('Copiez le lien :', this.urlValue);
            return true;
        }
        this.confirm('Lien copié !');
        return true;
    }

    // The confirmation stays until the pointer or the focus leaves the button
    confirm(text) {
        if (!this.hasLabelTarget) return;
        const label = this.labelTarget;
        label.dataset.originalText ??= label.textContent;
        label.textContent = text;
        const button = label.closest('button');
        const restore = () => { label.textContent = label.dataset.originalText; };
        button.addEventListener('mouseleave', restore, { once: true });
        button.addEventListener('blur', restore, { once: true });
    }

    recordActivity() {
        if (!this.activityUrlValue) return;
        fetch(this.activityUrlValue, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest' },
            body: new URLSearchParams({ _token: this.tokenValue }),
        });
    }
}
