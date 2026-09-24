import { Controller } from '@hotwired/stimulus';

/*
 * Éditeur Markdown du forum (templates/forum/_editor.html.twig).
 *
 *  - Barre d'outils (gras, italique, barré, citation, liste, lien, code, mention) et raccourcis
 *    Ctrl+B / Ctrl+I / Ctrl+K, Ctrl+Entrée pour envoyer.
 *  - Onglets Écrire / Aperçu : l'aperçu est rendu par le serveur (même rendu que le message publié).
 *  - Images : bouton, coller (Ctrl+V) ou glisser-déposer → envoi, puis ![](…) inséré dans le texte.
 *  - Mentions : saisir « @ps » propose les membres correspondants (↑ ↓ Entrée / Tab, Échap).
 *  - « Citer » (boutons des messages, action markdown-editor#quote) : insère la citation dans la réponse.
 *  - Brouillon enregistré localement (draftKey) et effacé à l'envoi du formulaire.
 *
 * Aucune donnée utilisateur n'est insérée via innerHTML, sauf l'aperçu renvoyé par le serveur
 * (Markdown rendu avec le HTML échappé, cf. App\Forum\ForumMarkdown).
 */
const MENTION_TOKEN = /(^|[^\w@])@([A-Za-z0-9_.-]{1,50})$/;
const DRAFT_PREFIX = 'hf-draft:';
const DRAFT_MAX_AGE = 7 * 24 * 3600 * 1000;

export default class extends Controller {
    static targets = ['input', 'tools', 'writeTab', 'previewTab', 'writePane', 'previewPane', 'mentions', 'status', 'file'];
    static values = {
        previewUrl: String,
        imageUrl: String,
        mentionsUrl: String,
        csrf: String,
        draftKey: String,
    };

    connect() {
        this.mentionState = null;   // { start, query } : jeton « @… » en cours de saisie
        this.suggestions = [];
        this.activeIndex = -1;
        this.mentionTimer = null;
        this.draftTimer = null;
        this.uploads = 0;
        this.restoreDraft();
    }

    disconnect() {
        clearTimeout(this.mentionTimer);
        clearTimeout(this.draftTimer);
        this.saveDraft();
    }

    // ─── Mise en forme ─────────────────────────────────────
    format(event) {
        const style = event.params.style;
        const input = this.inputTarget;
        const { selectionStart: start, selectionEnd: end, value } = input;
        const selected = value.slice(start, end);

        switch (style) {
            case 'bold': return this.wrap('**', '**', 'texte en gras');
            case 'italic': return this.wrap('_', '_', 'texte en italique');
            case 'strike': return this.wrap('~~', '~~', 'texte barré');
            case 'code':
                return selected.includes('\n') ? this.wrap('```\n', '\n```', '') : this.wrap('`', '`', 'code');
            case 'quote': return this.prefixLines('> ');
            case 'list': return this.prefixLines('- ');
            case 'link': {
                const text = selected || 'texte du lien';
                this.replaceSelection(`[${text}](https://)`);
                const urlStart = start + text.length + 3;
                input.setSelectionRange(urlStart, urlStart + 'https://'.length);
                return;
            }
            case 'mention': {
                const before = value.slice(0, start);
                this.replaceSelection((before === '' || /\s$/.test(before) ? '' : ' ') + '@');
                this.onInput();
                return;
            }
        }
    }

    wrap(before, after, placeholder) {
        const input = this.inputTarget;
        const { selectionStart: start, selectionEnd: end } = input;
        const selected = input.value.slice(start, end) || placeholder;
        this.replaceSelection(before + selected + after);
        input.setSelectionRange(start + before.length, start + before.length + selected.length);
    }

    prefixLines(prefix) {
        const input = this.inputTarget;
        const { value } = input;
        const lineStart = value.lastIndexOf('\n', input.selectionStart - 1) + 1;
        let lineEnd = value.indexOf('\n', Math.max(input.selectionEnd - 1, input.selectionStart));
        if (lineEnd === -1) lineEnd = value.length;
        input.setSelectionRange(lineStart, lineEnd);
        const block = value.slice(lineStart, lineEnd) || '';
        this.replaceSelection(block.split('\n').map(line => prefix + line).join('\n'));
    }

    /** Remplace la sélection (en conservant l'historique Ctrl+Z quand le navigateur le permet). */
    replaceSelection(text) {
        const input = this.inputTarget;
        input.focus();
        let done = false;
        try {
            done = document.execCommand('insertText', false, text);
        } catch (e) { /* non pris en charge */ }
        if (!done) {
            input.setRangeText(text, input.selectionStart, input.selectionEnd, 'end');
            input.dispatchEvent(new Event('input', { bubbles: true }));
        }
    }

    // ─── Clavier ───────────────────────────────────────────
    onKeydown(event) {
        if (this.mentionsOpen()) {
            if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
                event.preventDefault();
                const step = event.key === 'ArrowDown' ? 1 : -1;
                this.highlight((this.activeIndex + step + this.suggestions.length) % this.suggestions.length);
                return;
            }
            if ((event.key === 'Enter' || event.key === 'Tab') && this.activeIndex >= 0) {
                event.preventDefault();
                this.insertMention(this.suggestions[this.activeIndex].username);
                return;
            }
            if (event.key === 'Escape') {
                event.preventDefault();
                this.closeMentions();
                return;
            }
        }

        const mod = event.ctrlKey || event.metaKey;
        if (!mod) return;
        const key = event.key.toLowerCase();
        if (key === 'enter') {
            event.preventDefault();
            this.inputTarget.form?.requestSubmit();
        } else if (key === 'b' || key === 'i' || key === 'k') {
            event.preventDefault();
            this.format({ params: { style: { b: 'bold', i: 'italic', k: 'link' }[key] } });
        }
    }

    onInput() {
        this.scheduleDraft();
        const input = this.inputTarget;
        const before = input.value.slice(0, input.selectionStart);
        const match = before.match(MENTION_TOKEN);
        if (!match || input.selectionStart !== input.selectionEnd) {
            this.closeMentions();
            return;
        }
        const query = match[2];
        this.mentionState = { start: input.selectionStart - query.length - 1, query };
        clearTimeout(this.mentionTimer);
        this.mentionTimer = setTimeout(() => this.fetchMentions(query), 150);
    }

    // ─── Mentions ──────────────────────────────────────────
    fetchMentions(query) {
        fetch(`${this.mentionsUrlValue}?q=${encodeURIComponent(query)}`, { headers: { Accept: 'application/json' } })
            .then(r => (r.ok ? r.json() : Promise.reject(r)))
            .then(data => {
                // La saisie a changé entre-temps : réponse périmée
                if (!this.mentionState || this.mentionState.query !== query) return;
                this.suggestions = data.users || [];
                this.renderMentions();
            })
            .catch(() => this.closeMentions());
    }

    renderMentions() {
        const list = this.mentionsTarget;
        if (this.suggestions.length === 0) {
            this.closeMentions();
            return;
        }
        list.replaceChildren(...this.suggestions.map((user, index) => {
            const option = document.createElement('li');
            option.id = `${list.id}-${index}`;
            option.setAttribute('role', 'option');
            option.className = 'md-mention-option';
            const avatar = document.createElement('span');
            avatar.className = 'avatar avatar-xs';
            if (user.avatar) {
                const img = document.createElement('img');
                img.src = user.avatar;
                img.alt = '';
                avatar.appendChild(img);
            } else {
                const initial = document.createElement('span');
                initial.setAttribute('aria-hidden', 'true');
                initial.textContent = user.username.charAt(0).toUpperCase();
                avatar.appendChild(initial);
            }
            const name = document.createElement('span');
            name.textContent = user.username;
            option.append(avatar, name);
            // mousedown : avant la perte de focus du champ (qui fermerait la liste)
            option.addEventListener('mousedown', event => {
                event.preventDefault();
                this.insertMention(user.username);
            });
            return option;
        }));
        list.classList.remove('hidden');
        this.inputTarget.setAttribute('aria-expanded', 'true');
        this.highlight(0);
    }

    highlight(index) {
        this.activeIndex = index;
        [...this.mentionsTarget.children].forEach((option, i) => option.setAttribute('aria-selected', i === index ? 'true' : 'false'));
        const active = this.mentionsTarget.children[index];
        if (active) {
            this.inputTarget.setAttribute('aria-activedescendant', active.id);
            active.scrollIntoView({ block: 'nearest' });
        }
    }

    insertMention(username) {
        const input = this.inputTarget;
        if (!this.mentionState) return;
        input.setSelectionRange(this.mentionState.start, input.selectionStart);
        this.closeMentions();
        this.replaceSelection(`@${username} `);
    }

    mentionsOpen() {
        return this.hasMentionsTarget && !this.mentionsTarget.classList.contains('hidden') && this.suggestions.length > 0;
    }

    closeMentions() {
        clearTimeout(this.mentionTimer);
        this.mentionState = null;
        this.suggestions = [];
        this.activeIndex = -1;
        if (!this.hasMentionsTarget) return;
        this.mentionsTarget.classList.add('hidden');
        this.mentionsTarget.replaceChildren();
        this.inputTarget.setAttribute('aria-expanded', 'false');
        this.inputTarget.removeAttribute('aria-activedescendant');
    }

    // ─── Aperçu ────────────────────────────────────────────
    write() {
        this.showTab(false);
        this.inputTarget.focus();
    }

    preview() {
        this.showTab(true);
        const pane = this.previewPaneTarget;
        const content = this.inputTarget.value;
        if (content.trim() === '') {
            pane.replaceChildren(this.message('Rien à prévisualiser pour l’instant.'));
            return;
        }
        pane.replaceChildren(this.message('Chargement de l’aperçu…'));
        const body = new FormData();
        body.append('content', content);
        this.post(this.previewUrlValue, body)
            .then(data => { pane.innerHTML = data.html; }) // HTML rendu et assaini par le serveur
            .catch(error => pane.replaceChildren(this.message(error.message)));
    }

    showTab(preview) {
        this.writeTabTarget.setAttribute('aria-selected', preview ? 'false' : 'true');
        this.previewTabTarget.setAttribute('aria-selected', preview ? 'true' : 'false');
        this.writeTabTarget.tabIndex = preview ? -1 : 0;
        this.previewTabTarget.tabIndex = preview ? 0 : -1;
        this.writePaneTarget.hidden = preview;
        this.previewPaneTarget.hidden = !preview;
        this.toolsTarget.querySelectorAll('button').forEach(button => { button.disabled = preview; });
        this.closeMentions();
    }

    message(text) {
        const p = document.createElement('p');
        p.className = 'text-small text-muted';
        p.textContent = text;
        return p;
    }

    // ─── Images ────────────────────────────────────────────
    pickImage() {
        this.fileTarget.click();
    }

    onFileChosen() {
        [...this.fileTarget.files].forEach(file => this.upload(file));
        this.fileTarget.value = '';
    }

    onPaste(event) {
        const files = [...(event.clipboardData?.files || [])].filter(file => file.type.startsWith('image/'));
        if (files.length === 0) return;
        event.preventDefault();
        files.forEach(file => this.upload(file));
    }

    onDragover(event) {
        if ([...(event.dataTransfer?.items || [])].some(item => item.kind === 'file')) event.preventDefault();
    }

    onDrop(event) {
        const files = [...(event.dataTransfer?.files || [])].filter(file => file.type.startsWith('image/'));
        if (files.length === 0) return;
        event.preventDefault();
        files.forEach(file => this.upload(file));
    }

    upload(file) {
        const placeholder = `![Envoi de l’image ${++this.uploads}…]()`;
        const input = this.inputTarget;
        const before = input.value.slice(0, input.selectionStart);
        this.replaceSelection((before === '' || before.endsWith('\n') ? '' : '\n') + placeholder + '\n');
        this.setStatus('Envoi de l’image…');

        const body = new FormData();
        body.append('image', file);
        this.post(this.imageUrlValue, body)
            .then(data => {
                this.swapText(placeholder, `![${this.altFor(file)}](${data.url})`);
                this.setStatus('Image ajoutée.');
            })
            .catch(error => {
                this.swapText(placeholder + '\n', '');
                this.swapText(placeholder, '');
                this.setStatus(error.message);
            });
    }

    altFor(file) {
        return (file.name || 'image').replace(/\.[a-z0-9]+$/i, '').replace(/[[\]()]/g, ' ').trim().slice(0, 60) || 'image';
    }

    swapText(search, replacement) {
        const input = this.inputTarget;
        const index = input.value.indexOf(search);
        if (index === -1) return;
        const caret = input.selectionStart;
        input.value = input.value.slice(0, index) + replacement + input.value.slice(index + search.length);
        const shift = caret > index ? replacement.length - search.length : 0;
        input.setSelectionRange(caret + shift, caret + shift);
        this.scheduleDraft();
    }

    setStatus(text) {
        if (this.hasStatusTarget) this.statusTarget.textContent = text;
    }

    // ─── Citer un message ──────────────────────────────────
    quote(event) {
        event.preventDefault();
        if (!this.hasInputTarget) return;
        const { author, content } = event.params;
        // Sans les citations imbriquées : seul le propos du message cité est repris
        const body = String(content || '')
            .split('\n')
            .filter(line => !line.startsWith('>'))
            .join('\n')
            .trim()
            .split('\n')
            .map(line => `> ${line}`)
            .join('\n');
        const block = `> **@${author}** a écrit :\n${body}\n\n`;

        this.showTab(false);
        const input = this.inputTarget;
        const value = input.value;
        input.setSelectionRange(value.length, value.length);
        this.replaceSelection((value === '' || value.endsWith('\n\n') ? '' : value.endsWith('\n') ? '\n' : '\n\n') + block);
        input.scrollIntoView({ block: 'center', behavior: 'smooth' });
        input.focus({ preventScroll: true });
    }

    // ─── Brouillon local ───────────────────────────────────
    get draftStorageKey() {
        return this.draftKeyValue ? DRAFT_PREFIX + this.draftKeyValue : null;
    }

    restoreDraft() {
        const key = this.draftStorageKey;
        if (!key || !this.hasInputTarget || this.inputTarget.value.trim() !== '') return;
        try {
            const draft = JSON.parse(localStorage.getItem(key) || 'null');
            if (draft && Date.now() - draft.at < DRAFT_MAX_AGE && draft.text) {
                this.inputTarget.value = draft.text;
                this.setStatus('Brouillon restauré.');
            }
        } catch (e) { /* stockage indisponible */ }
    }

    scheduleDraft() {
        clearTimeout(this.draftTimer);
        this.draftTimer = setTimeout(() => this.saveDraft(), 500);
    }

    saveDraft() {
        const key = this.draftStorageKey;
        if (!key || !this.hasInputTarget || this.submitted) return;
        try {
            const text = this.inputTarget.value;
            if (text.trim() === '') localStorage.removeItem(key);
            else localStorage.setItem(key, JSON.stringify({ text, at: Date.now() }));
        } catch (e) { /* stockage indisponible */ }
    }

    onSubmit() {
        this.submitted = true;
        clearTimeout(this.draftTimer);
        const key = this.draftStorageKey;
        if (!key) return;
        try { localStorage.removeItem(key); } catch (e) { /* stockage indisponible */ }
    }

    // ─── Réseau ────────────────────────────────────────────
    post(url, body) {
        return fetch(url, {
            method: 'POST',
            body,
            headers: { 'X-CSRF-Token': this.csrfValue, 'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json' },
            credentials: 'same-origin',
        }).then(response => response.json().catch(() => ({})).then(data => {
            if (!response.ok) throw new Error(data.error || 'Une erreur est survenue, veuillez réessayer.');
            return data;
        }));
    }
}
