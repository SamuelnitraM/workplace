import { Controller } from '@hotwired/stimulus';

/* stimulusFetch: 'lazy' */

/*
 * Edition of a profile image (profile photo or banner): preview of the chosen file, then selection and marking
 * of the current image for deletion (hidden field delete_<kind> = 1, applied when the form is saved).
 * One controller instance per image, placed on the block that holds its fields.
 *
 * Targets: preview (current img), placeholder (initial or default visual), selectContainer / selectCheckbox (selection box),
 * deleteButton ("Supprimer" button), deleteInput (hidden delete_<kind> field).
 * Actions: change->profile-image#preview (file input), change->profile-image#toggleDelete (box), profile-image#markForDeletion (button).
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
