import { Controller } from '@hotwired/stimulus';

/* stimulusFetch: 'lazy' */

/*
 * Profile gallery:
 * - upload zone (owner): preview of the chosen file, drag and drop, locked at 10 photos;
 * - delete mode (owner): selection of tiles, the upload zone becomes a « Supprimer » button
 *   that sends the form #gallery-delete-form with the selected photo_ids[];
 * - selection actions (fillSelection): delete, create an album, add to an album ("+" of an album tile),
 *   remove from the album; each button sends its own form with the selected photo_ids[];
 * - in selection mode, a click on a tile does not navigate to the page of the photo;
 * - visibility toggle (eye of a tile, toggleVisibility): sent without reloading, the tile rendered by the server
 *   replaces the current one (the selection is kept); a non-JSON answer falls back to the regular form submission.
 * The selection is read back from the tiles on connect, so a page restored from the Turbo cache stays consistent.
 *
 * Targets: zone (upload form, data-upload-locked), input, label, preview, submit, description, deleteForm.
 * A tile: [data-photo-id] holding the button .gallery-select (.is-idle = hidden unless hovered; checked tile: .is-selected).
 * The « N sélectionnée(s) » bar of the template is shown by CSS (:has(.is-selected)), without logic here.
 */
export default class extends Controller {
    static targets = ['zone', 'input', 'label', 'preview', 'submit', 'description', 'deleteForm'];

    connect() {
        this.selectedPhotos = new Set([...this.element.querySelectorAll('[data-photo-id].is-selected')].map(tile => tile.dataset.photoId));
        if (this.selectedPhotos.size > 0) this.updateDeleteMode();
        if (this.hasInputTarget && this.hasLabelTarget) {
            const previousId = this.inputTarget.id;
            this.inputTarget.id = 'profile-photo-upload-' + Math.random().toString(36).slice(2);
            this.labelTarget.setAttribute('for', this.inputTarget.id);
            // Other labels bound to the field (e.g. « Ajouter ma première photo » button of the empty state)
            if (previousId) {
                this.element.querySelectorAll('label[for="' + previousId + '"]')
                    .forEach(label => label.setAttribute('for', this.inputTarget.id));
            }
        }
    }

    disconnect() {
        this.revokePreview();
    }

    get locked() {
        return this.hasZoneTarget && this.zoneTarget.dataset.uploadLocked === 'true';
    }

    // --- Upload zone ---

    fileChanged() {
        this.showPreview(this.inputTarget.files[0]);
    }

    dragOver(event) {
        if (this.locked) return;
        event.preventDefault();
        this.zoneTarget.classList.add('is-dragover');
    }

    dragLeave() {
        this.zoneTarget.classList.remove('is-dragover');
    }

    drop(event) {
        if (this.locked) return;
        event.preventDefault();
        this.zoneTarget.classList.remove('is-dragover');
        if (event.dataTransfer.files.length) {
            this.inputTarget.files = event.dataTransfer.files;
            this.showPreview(this.inputTarget.files[0]);
        }
    }

    // The file is shown through an object URL (no copy of the whole image in memory as a data URL)
    showPreview(file) {
        if (!file || !file.type.startsWith('image/') || !this.hasPreviewTarget) return;
        this.revokePreview();
        this.previewUrl = URL.createObjectURL(file);
        this.previewTarget.style.backgroundImage = `url("${this.previewUrl}")`;
        this.previewTarget.classList.remove('opacity-0');
        this.labelTarget.querySelector('span').textContent = file.name;
    }

    revokePreview() {
        if (!this.previewUrl) return;
        URL.revokeObjectURL(this.previewUrl);
        this.previewUrl = null;
    }

    // --- Photo selection (owner) ---

    showSelector(event) {
        const selector = event.currentTarget.querySelector('.gallery-select');
        selector.classList.remove('is-idle');
        selector.classList.add('is-shown');
    }

    hideSelector(event) {
        const photo = event.currentTarget;
        if (!this.selectedPhotos.has(photo.dataset.photoId)) {
            photo.querySelector('.gallery-select').classList.add('is-idle');
        }
    }

    toggleSelection(event) {
        event.stopPropagation();
        const selector = event.currentTarget;
        const photo = selector.closest('[data-photo-id]');
        const id = photo.dataset.photoId;
        if (this.selectedPhotos.has(id)) {
            this.selectedPhotos.delete(id);
            photo.classList.remove('is-selected');
            selector.classList.add('is-idle');
            selector.classList.remove('is-shown');
        } else {
            this.selectedPhotos.add(id);
            photo.classList.add('is-selected');
            selector.classList.remove('is-idle');
        }
        this.updateDeleteMode();
    }

    toggleVisibility(event) {
        event.preventDefault();
        const form = event.currentTarget;
        const tile = form.closest('[data-photo-id]');
        const button = form.querySelector('button');
        button.disabled = true;
        fetch(form.action, {
            method: 'POST',
            body: new FormData(form),
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin',
        }).then(response => {
            if (!(response.headers.get('Content-Type') || '').includes('application/json')) {
                form.submit();
                return;
            }
            return response.json().then(data => {
                if (!response.ok) throw new Error(data.error || 'Une erreur est survenue.');
                this.replaceTile(tile, data.tile);
            });
        }).catch(error => {
            button.disabled = false;
            button.title = error.message;
        });
    }

    replaceTile(tile, html) {
        const template = document.createElement('template');
        template.innerHTML = html.trim();
        const freshTile = template.content.firstElementChild;
        if (this.selectedPhotos.has(tile.dataset.photoId)) {
            freshTile.classList.add('is-selected');
            freshTile.querySelector('.gallery-select')?.classList.remove('is-idle');
        }
        tile.replaceWith(freshTile);
        freshTile.querySelector('.gallery-photo-action button')?.focus();
    }

    // In selection mode (deletion), a click on the tile does not navigate to the page of the photo
    followLink(event) {
        if (this.selectedPhotos.size > 0) event.preventDefault();
    }

    // Upload zone button in selection mode: fills the delete form before it is sent
    submitClick() {
        if (this.selectedPhotos.size === 0 || !this.hasDeleteFormTarget) return;
        this.fillForm(this.deleteFormTarget);
    }

    // Any selection action (delete, create an album, add to or remove from an album): the clicked button's form
    // receives the selected photo ids
    fillSelection(event) {
        const form = event.currentTarget.form;
        if (!form) return;
        if (this.selectedPhotos.size === 0 && form !== this.element.querySelector('#gallery-album-create form')) {
            event.preventDefault();
            return;
        }
        this.fillForm(form);
    }

    fillForm(form) {
        form.querySelectorAll('input[name="photo_ids[]"]').forEach(input => input.remove());
        this.selectedPhotos.forEach(id => {
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = 'photo_ids[]';
            input.value = id;
            form.appendChild(input);
        });
    }

    updateDeleteMode() {
        if (!this.hasZoneTarget) return;
        const uploadZone = this.zoneTarget;
        const uploadLabel = this.labelTarget;
        const uploadSubmit = this.submitTarget;
        const hasSelection = this.selectedPhotos.size > 0;
        const uploadLocked = this.locked;

        if (this.hasDescriptionTarget) this.descriptionTarget.hidden = hasSelection;
        uploadZone.classList.toggle('is-delete-mode', hasSelection);
        uploadZone.classList.toggle('border-danger', hasSelection);
        uploadZone.classList.toggle('bg-surface', !hasSelection);
        uploadLabel.classList.toggle('pointer-events-none', hasSelection || uploadLocked);
        uploadLabel.querySelector('span').textContent = hasSelection ? '' : (uploadLocked ? 'Limite de 10 photos atteinte' : 'Ajouter une photo');
        uploadSubmit.textContent = hasSelection ? 'Supprimer' : 'Publier';
        uploadSubmit.classList.toggle('btn-danger', hasSelection);
        uploadSubmit.classList.toggle('btn-md', hasSelection);
        uploadSubmit.classList.toggle('btn-primary', !hasSelection);
        uploadSubmit.classList.toggle('btn-sm', !hasSelection);
        uploadSubmit.classList.toggle('hidden', !hasSelection && uploadLocked);
        uploadSubmit.classList.toggle('bottom-3', !hasSelection);
        uploadSubmit.classList.toggle('top-1/2', hasSelection);
        uploadSubmit.classList.toggle('-translate-y-1/2', hasSelection);
        if (hasSelection) {
            uploadSubmit.setAttribute('form', this.hasDeleteFormTarget ? this.deleteFormTarget.id : 'gallery-delete-form');
        } else {
            uploadSubmit.removeAttribute('form');
        }
    }
}
