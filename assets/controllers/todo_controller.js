import { Controller } from '@hotwired/stimulus';

/* stimulusFetch: 'lazy' */

/*
 * Todo lists (personnelles et de groupe) : progression des tâches, renommage par double-clic,
 * assignations (todo de groupe). Chaque action est un POST AJAX avec le jeton CSRF « todo »
 * dans l'en-tête X-CSRF-Token ; la progression de la catégorie et du projet est recalculée côté client.
 * Assignations : le serveur renvoie les fragments à jour (assignés de la tâche #assignees-<id>, résumé de la
 * catégorie #category-assignees-<id>) qui remplacent les anciens ; un clic d'un gestionnaire sur un assigné
 * ouvre la fenêtre de décision (accepter / refuser une demande, retirer un assigné).
 *
 * Usage :
 *   <div {{ stimulus_controller('todo', {token: csrf_token('todo')}) }}>
 *     <span id="rename-list-1" {{ stimulus_action('todo', 'rename', 'dblclick', {url: …}) }}><span data-todo-title>…</span></span>
 *     <button {{ stimulus_action('todo', 'rename:stop', 'click', {url: …, for: 'rename-list-1'}) }}>✎</button>  ← bouton crayon : renomme l'élément d'id « for »
 *     <button {{ stimulus_action('todo', 'progress', 'click', {url: …, item: item.id, project: list.id}) }}>+</button>
 *     <button {{ stimulus_action('todo', 'assignment', 'click', {url: …, user: member.user.id}) }}>…</button>  ← assignation (fragments rafraîchis)
 *
 * Éléments d'une tâche : #item-<id> (data-project-id, data-progress, data-is-done),
 * #item-progress-bar-<id>, #item-badge-<id> ; progression du projet : #project-progress-text-<id>, #project-progress-bar-<id>.
 */
export default class extends Controller {
    static values = { token: String };
    static targets = ['assignmentDialog', 'assignmentText', 'assignmentError', 'acceptButton', 'refuseButton', 'removeButton'];

    // url = route progress up / down / validate de la tâche
    progress({ params: { url, item, project, category } }) {
        this.post(url)
            .then(data => this.applyTaskState(item, data.progress, data.isDone, project, category))
            .catch(() => this.failed());
    }

    // ─── Assignations ─────────────────────────────────────
    // url = request / assign / decision ; user = membre assigné par un gestionnaire
    assignment({ params: { url, user } }) {
        this.sendAssignment(url, user ? { user_id: user } : {}).then(error => {
            if (error) alert(error);
        });
    }

    openAssignment({ params }) {
        this.assignmentTextTarget.textContent = params.pending
            ? `${params.username} demande à être assigné à « ${params.task} ».`
            : `${params.username} est assigné à « ${params.task} ».`;
        this.acceptButtonTarget.hidden = !params.pending;
        this.refuseButtonTarget.hidden = !params.pending;
        this.removeButtonTarget.hidden = Boolean(params.pending);
        this.acceptButtonTarget.dataset.url = params.accept;
        this.refuseButtonTarget.dataset.url = params.refuse;
        this.removeButtonTarget.dataset.url = params.remove;
        this.assignmentErrorTarget.hidden = true;
        this.assignmentDialogTarget.showModal();
    }

    decideAssignment(event) {
        this.sendAssignment(event.currentTarget.dataset.url).then(error => {
            if (error) {
                this.assignmentErrorTarget.textContent = error;
                this.assignmentErrorTarget.hidden = false;
                return;
            }
            this.closeAssignment();
        });
    }

    closeAssignment() {
        this.assignmentDialogTarget.close();
    }

    closeAssignmentOnBackdrop(event) {
        if (event.target === event.currentTarget) this.closeAssignment();
    }

    /** Sends an assignment action, puts the refreshed fragments in place; resolves with the error message, if any. */
    sendAssignment(url, params = {}) {
        return fetch(url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-CSRF-Token': this.tokenValue, Accept: 'application/json' },
            body: new URLSearchParams(params),
        })
            .then(response => response.json().catch(() => ({ error: 'L\'action a échoué. Veuillez recharger la page et réessayer.' })))
            .then(data => {
                if (data.task) this.replaceFragment(`assignees-${data.taskId}`, data.task);
                if (data.category) this.replaceFragment(`category-assignees-${data.categoryId}`, data.category);
                return data.error || null;
            })
            .catch(() => 'L\'action a échoué. Veuillez recharger la page et réessayer.');
    }

    replaceFragment(id, html) {
        const current = this.find(id);
        if (!current) return;
        const template = document.createElement('template');
        template.innerHTML = html.trim();
        current.replaceWith(template.content);
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

    applyTaskState(id, progress, isDone, projectId, categoryId) {
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
            this.refreshProgress('project', projectId);
        }
        if (categoryId) {
            this.refreshProgress('category', categoryId);
        }
    }

    // Average progress of the tasks of a project or a category (data-project-id / data-category-id)
    refreshProgress(scope, id) {
        const items = this.element.querySelectorAll(`[data-${scope}-id="${CSS.escape(String(id))}"]`);
        if (!items.length) return;

        let sum = 0;
        items.forEach(item => {
            sum += parseInt(item.getAttribute('data-progress') || '0', 10);
        });

        const avg = Math.round(sum / items.length);
        const textEl = this.find(`${scope}-progress-text-${id}`);
        const barEl = this.find(`${scope}-progress-bar-${id}`);

        if (textEl) textEl.textContent = avg + '%';
        if (barEl) barEl.style.width = avg + '%';
    }
}
