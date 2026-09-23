import { Controller } from '@hotwired/stimulus';

/* stimulusFetch: 'lazy' */

/*
 * Todo lists (personnelles et de groupe) : progression des tâches, renommage par double-clic,
 * assignation (todo de groupe). Chaque action est un POST AJAX avec le jeton CSRF « todo »
 * dans l'en-tête X-CSRF-Token ; la progression globale du projet est recalculée côté client.
 *
 * Usage :
 *   <div {{ stimulus_controller('todo', {token: csrf_token('todo')}) }}>
 *     <span id="rename-list-1" {{ stimulus_action('todo', 'rename', 'dblclick', {url: …}) }}><span data-todo-title>…</span></span>
 *     <button {{ stimulus_action('todo', 'rename:stop', 'click', {url: …, for: 'rename-list-1'}) }}>✎</button>  ← bouton crayon : renomme l'élément d'id « for »
 *     <button {{ stimulus_action('todo', 'progress', 'click', {url: …, item: item.id, project: list.id}) }}>+</button>
 *     <button {{ stimulus_action('todo', 'assign', 'click', {url: …, user: member.user.id}) }}>…</button>
 *
 * Éléments d'une tâche : #item-<id> (data-project-id, data-progress, data-is-done),
 * #item-progress-bar-<id>, #item-badge-<id> ; progression du projet : #project-progress-text-<id>, #project-progress-bar-<id>.
 */
export default class extends Controller {
    static values = { token: String };

    // url = route progress up / down / validate de la tâche
    progress({ params: { url, item, project } }) {
        this.post(url)
            .then(data => this.applyTaskState(item, data.progress, data.isDone, project))
            .catch(() => this.failed());
    }

    // url = route d'assignation du noeud ; user vide = désassigner
    assign({ params: { url, user } }) {
        this.post(url, { assigned_to: user ?? '' })
            .then(() => location.reload())
            .catch(() => this.failed());
    }

    // Renommer via double-clic (ou bouton crayon : param « for » = id de l'élément renommable) : seul l'élément [data-todo-title] est mis à jour
    rename(event) {
        const element = event.params.for ? this.find(String(event.params.for)) : event.currentTarget;
        if (!element) return; // déjà en cours de renommage
        const url = event.params.url;
        const titleEl = element.querySelector('[data-todo-title]') || element;
        const currentTitle = titleEl.textContent.trim();
        let finished = false;

        const input = document.createElement('input');
        input.type = 'text';
        input.value = currentTitle;
        input.maxLength = 150;
        input.className = 'input min-h-9 w-full py-1 text-small';
        input.setAttribute('aria-label', 'Nouveau nom');

        element.replaceWith(input);
        input.focus();
        input.select();

        const restore = () => {
            if (input.isConnected) input.replaceWith(element);
        };

        const save = () => {
            if (finished) return;
            finished = true;

            const newTitle = input.value.trim();
            if (!newTitle || newTitle === currentTitle) {
                restore();
                return;
            }

            this.post(url, { title: newTitle })
                .then(data => {
                    titleEl.textContent = data.title;
                    restore();
                })
                .catch(() => {
                    restore();
                    this.failed();
                });
        };

        input.addEventListener('blur', save);
        input.addEventListener('keydown', e => {
            if (e.key === 'Enter') save();
            if (e.key === 'Escape') {
                finished = true;
                restore();
            }
        });
    }

    // POST AJAX avec jeton CSRF ; rejette si la réponse n'est pas OK
    post(url, params = {}) {
        return fetch(url, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
                'X-CSRF-Token': this.tokenValue,
            },
            body: new URLSearchParams(params),
        }).then(r => {
            if (!r.ok) throw new Error(`HTTP ${r.status}`);
            return r.json();
        });
    }

    failed() {
        alert("L'action a échoué. Veuillez recharger la page et réessayer.");
    }

    find(id) {
        return this.element.querySelector(`#${CSS.escape(id)}`);
    }

    applyTaskState(id, progress, isDone, projectId) {
        const itemDiv = this.find(`item-${id}`);
        if (!itemDiv) return;

        itemDiv.setAttribute('data-progress', progress);
        itemDiv.setAttribute('data-is-done', isDone ? '1' : '0');

        const progressBar = this.find(`item-progress-bar-${id}`);
        const badge = this.find(`item-badge-${id}`);
        const titleP = itemDiv.querySelector('p');

        if (progressBar) progressBar.style.width = progress + '%';
        if (badge) badge.textContent = progress + '%';

        if (isDone || progress >= 100) {
            itemDiv.classList.add('bg-canvas');
            itemDiv.classList.remove('bg-surface');
            if (titleP) {
                titleP.classList.add('line-through', 'text-muted');
                titleP.classList.remove('text-fg');
            }
            if (badge) {
                badge.className = 'chip chip-success shrink-0 tabular-nums';
            }
        } else {
            itemDiv.classList.remove('bg-canvas');
            itemDiv.classList.add('bg-surface');
            if (titleP) {
                titleP.classList.remove('line-through', 'text-muted');
                titleP.classList.add('text-fg');
            }
            if (badge) {
                badge.className = 'chip shrink-0 tabular-nums';
            }
        }

        if (projectId) {
            this.refreshProjectProgress(projectId);
        }
    }

    refreshProjectProgress(projectId) {
        const projectItems = this.element.querySelectorAll(`[data-project-id="${CSS.escape(String(projectId))}"]`);
        if (!projectItems.length) return;

        let sum = 0;
        projectItems.forEach(item => {
            sum += parseInt(item.getAttribute('data-progress') || '0', 10);
        });

        const avg = Math.round(sum / projectItems.length);
        const textEl = this.find(`project-progress-text-${projectId}`);
        const barEl = this.find(`project-progress-bar-${projectId}`);

        if (textEl) textEl.textContent = avg + '%';
        if (barEl) barEl.style.width = avg + '%';
    }
}
