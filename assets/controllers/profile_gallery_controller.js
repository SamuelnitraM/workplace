import { Controller } from '@hotwired/stimulus';

/* stimulusFetch: 'lazy' */

/*
 * Galerie du profil :
 * - zone d'envoi (propriétaire) : aperçu du fichier choisi, glisser-déposer, verrouillage à 10 photos ;
 * - mode suppression (propriétaire) : sélection de vignettes, la zone d'envoi devient un bouton « Supprimer »
 *   qui envoie le formulaire #gallery-delete-form avec les photo_ids[] sélectionnés ;
 * - en mode sélection, un clic sur une vignette ne navigue pas vers la page de la photo.
 *
 * Cibles : zone (formulaire d'envoi, data-upload-locked), input, label, preview, submit, description, deleteForm.
 * Une vignette : [data-photo-id] contenant le bouton .gallery-select (.is-idle = masqué hors survol ; vignette cochée : .is-selected).
 * La barre « N sélectionnée(s) » du gabarit s'affiche en CSS (:has(.is-selected)), sans logique ici.
 */
export default class extends Controller {
    static targets = ['zone', 'input', 'label', 'preview', 'submit', 'description', 'deleteForm'];

    connect() {
        this.selectedPhotos = new Set();
        if (this.hasInputTarget && this.hasLabelTarget) {
            const previousId = this.inputTarget.id;
            this.inputTarget.id = 'profile-photo-upload-' + Math.random().toString(36).slice(2);
            this.labelTarget.setAttribute('for', this.inputTarget.id);
            // Autres libellés liés au champ (ex. bouton « Ajouter ma première photo » de l'état vide)
            if (previousId) {
                this.element.querySelectorAll('label[for="' + previousId + '"]')
                    .forEach(label => label.setAttribute('for', this.inputTarget.id));
            }
        }
    }

    get locked() {
        return this.hasZoneTarget && this.zoneTarget.dataset.uploadLocked === 'true';
    }

    // --- Zone d'envoi ---

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

    showPreview(file) {
        if (!file || !file.type.startsWith('image/')) return;
        const reader = new FileReader();
        reader.onload = event => {
            if (!this.hasPreviewTarget) return;
            this.previewTarget.style.backgroundImage = 'url("' + event.target.result + '")';
            this.previewTarget.classList.remove('opacity-0');
            this.labelTarget.querySelector('span').textContent = file.name;
        };
        reader.readAsDataURL(file);
    }

    // --- Sélection des photos (propriétaire) ---

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

    // En mode sélection (suppression), un clic sur la vignette ne navigue pas vers la page de la photo
    followLink(event) {
        if (this.selectedPhotos.size > 0) event.preventDefault();
    }

    // Clic sur le bouton de la zone : en mode suppression, remplit le formulaire de suppression avant son envoi
    submitClick() {
        if (this.selectedPhotos.size === 0 || !this.hasDeleteFormTarget) return;
        const deleteForm = this.deleteFormTarget;
        deleteForm.querySelectorAll('input[name="photo_ids[]"]').forEach(input => input.remove());
        this.selectedPhotos.forEach(id => {
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = 'photo_ids[]';
            input.value = id;
            deleteForm.appendChild(input);
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
