import { Controller } from '@hotwired/stimulus';

/* stimulusFetch: 'lazy' */

/*
 * Édition de la photo de profil : aperçu du fichier choisi, sélection puis marquage de la photo
 * actuelle pour suppression (champ caché delete_avatar = 1, appliqué à l'enregistrement du formulaire).
 *
 * Cibles : preview (img actuelle), placeholder (initiale), selectContainer / selectCheckbox (case de sélection),
 * deleteButton (bouton « Supprimer la photo »), deleteInput (champ caché delete_avatar).
 * Actions : change->avatar#preview (input fichier), change->avatar#toggleDelete (case), avatar#markForDeletion (bouton).
 */
export default class extends Controller {
    static targets = ['preview', 'placeholder', 'selectContainer', 'selectCheckbox', 'deleteButton', 'deleteInput'];

    preview(event) {
        const input = event.currentTarget;
        if (!(input.files && input.files[0])) return;

        const reader = new FileReader();
        reader.onload = e => {
            if (this.hasPreviewTarget) {
                this.previewTarget.src = e.target.result;
                this.previewTarget.style.display = 'block';
            }
            if (this.hasPlaceholderTarget) this.placeholderTarget.style.display = 'none';
            if (this.hasSelectContainerTarget) this.selectContainerTarget.style.display = 'block';
            if (this.hasSelectCheckboxTarget) this.selectCheckboxTarget.checked = false;
            if (this.hasDeleteButtonTarget) this.deleteButtonTarget.classList.add('hidden');
            if (this.hasDeleteInputTarget) this.deleteInputTarget.value = '0';
        };
        reader.readAsDataURL(input.files[0]);
    }

    toggleDelete() {
        const isSelected = this.hasSelectCheckboxTarget && this.selectCheckboxTarget.checked;
        if (this.hasDeleteButtonTarget) this.deleteButtonTarget.classList.toggle('hidden', !isSelected);
    }

    markForDeletion() {
        if (this.hasPreviewTarget) this.previewTarget.style.display = 'none';
        if (this.hasPlaceholderTarget) this.placeholderTarget.style.display = 'flex';
        if (this.hasSelectContainerTarget) this.selectContainerTarget.style.display = 'none';
        if (this.hasSelectCheckboxTarget) this.selectCheckboxTarget.checked = false;
        if (this.hasDeleteButtonTarget) this.deleteButtonTarget.classList.add('hidden');
        if (this.hasDeleteInputTarget) this.deleteInputTarget.value = '1';
    }
}
