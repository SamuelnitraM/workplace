import { Controller } from '@hotwired/stimulus';

/* stimulusFetch: 'lazy' */

/*
 * Amélioration progressive : like / unlike d'une photo sans rechargement
 * (le formulaire fonctionne aussi sans JS).
 *
 * Usage :
 *   <div data-controller="like">
 *     <form … data-like-target="form" data-action="like#submit">
 *       <input type="hidden" name="liked" data-like-target="intent">
 *       <button type="submit" data-like-target="button"><span aria-hidden="true">♡</span>
 *         <span data-like-target="label">…</span> <span data-like-target="count">…</span></button>
 *     (the label target is optional: compact buttons show only the heart and the counter)
 *     </form>
 *     <p class="hidden" data-like-target="error" role="alert"></p>
 *   </div>
 */
export default class extends Controller {
    static targets = ['form', 'intent', 'button', 'label', 'count', 'error'];

    connect() {
        this.pending = false;
    }

    render(liked, count) {
        const button = this.buttonTarget;
        this.intentTarget.value = liked ? '0' : '1';
        button.setAttribute('aria-pressed', liked ? 'true' : 'false');
        button.querySelector('[aria-hidden]').textContent = liked ? '♥' : '♡';
        if (this.hasLabelTarget) this.labelTarget.textContent = liked ? 'Je n’aime plus' : 'J’aime';
        this.countTarget.textContent = count;
        ['is-liked'].forEach(c => button.classList.toggle(c, liked));
        ['is-not-liked'].forEach(c => button.classList.toggle(c, !liked));
    }

    submit(event) {
        event.preventDefault();
        if (this.pending) return;
        const form = this.formTarget;
        const button = this.buttonTarget;
        const error = this.hasErrorTarget ? this.errorTarget : null;

        this.pending = true;
        button.disabled = true;
        error?.classList.add('hidden');

        fetch(form.action, {
            method: 'POST',
            body: new FormData(form),
            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin',
        }).then(response => {
            // Réponse non JSON (session expirée, redirection…) : repli sur l'envoi classique du formulaire
            if ((response.headers.get('Content-Type') || '').indexOf('application/json') === -1) {
                form.submit();
                return;
            }
            return response.json().then(data => {
                if (!response.ok) throw new Error(data.error || 'Une erreur est survenue.');
                this.render(data.liked, data.likeCount);
            });
        }).catch(e => {
            if (!error) return;
            error.textContent = e.message || 'Une erreur est survenue.';
            error.classList.remove('hidden');
        }).finally(() => {
            this.pending = false;
            button.disabled = false;
        });
    }
}
