import { Controller } from '@hotwired/stimulus';
import Cropper from 'cropperjs';
import 'cropperjs/dist/cropper.min.css';

/* stimulusFetch: 'lazy' */

/*
 * Edition of a profile image (profile photo or banner).
 *
 * - A chosen file opens the crop window (templates/_partials/_crop_dialog.html.twig) with a frame at the aspect
 *   ratio of the image kind; the frame is sent with the form ("x,y,width,height", hidden field <kind>_crop)
 *   and the server keeps exactly this rectangle. The preview shows the framed result.
 * - Cancelling the crop window forgets the chosen file.
 * - The current image can be selected then marked for deletion (hidden field delete_<kind> = 1).
 * One controller instance per image, placed on the block that holds its fields.
 *
 * Values: aspectRatio (width / height of the frame).
 * Targets: fileInput, cropInput, dialog, cropImage, preview (current img), placeholder (initial or default visual),
 * selectContainer / selectCheckbox (selection box), deleteButton ("Supprimer" button), deleteInput (hidden delete_<kind> field).
 * Actions: change->profile-image#chooseFile (file input), profile-image#confirmCrop, profile-image#cancelCrop,
 * close->profile-image#onDialogClose (dialog), change->profile-image#toggleDelete (box), profile-image#markForDeletion (button).
 */
const PREVIEW_MAX_WIDTH = 1200;

export default class extends Controller {
    static targets = ['fileInput', 'cropInput', 'dialog', 'cropImage', 'preview', 'placeholder', 'selectContainer', 'selectCheckbox', 'deleteButton', 'deleteInput'];
    static values = { aspectRatio: { type: Number, default: 1 } };

    disconnect() {
        this.destroyCropper();
    }

    chooseFile() {
        const file = this.fileInputTarget.files?.[0];
        if (!file || !file.type.startsWith('image/')) return;
        this.destroyCropper();
        this.confirmed = false;
        this.objectUrl = URL.createObjectURL(file);
        this.cropImageTarget.src = this.objectUrl;
        this.dialogTarget.showModal();
        this.cropper = new Cropper(this.cropImageTarget, {
            aspectRatio: this.aspectRatioValue,
            viewMode: 1,
            dragMode: 'move',
            autoCropArea: 1,
            background: false,
            responsive: true,
        });
    }

    confirmCrop() {
        if (!this.cropper) return;
        const { x, y, width, height } = this.cropper.getData(true);
        this.cropInputTarget.value = [x, y, width, height].join(',');
        const canvas = this.cropper.getCroppedCanvas({ maxWidth: PREVIEW_MAX_WIDTH, maxHeight: PREVIEW_MAX_WIDTH, imageSmoothingQuality: 'high' });
        this.showPreview(canvas.toDataURL('image/jpeg', 0.9));
        this.confirmed = true;
        this.dialogTarget.close();
    }

    cancelCrop() {
        this.dialogTarget.close();
    }

    // Closing the window without confirming (button, Escape) forgets the chosen file
    onDialogClose() {
        if (!this.confirmed) {
            this.fileInputTarget.value = '';
            this.cropInputTarget.value = '';
        }
        this.destroyCropper();
    }

    destroyCropper() {
        this.cropper?.destroy();
        this.cropper = null;
        if (this.objectUrl) {
            URL.revokeObjectURL(this.objectUrl);
            this.objectUrl = null;
        }
    }

    showPreview(source) {
        if (this.hasPreviewTarget) {
            this.previewTarget.src = source;
            this.previewTarget.classList.remove('hidden');
            this.previewTarget.style.display = 'block';
        }
        if (this.hasPlaceholderTarget) this.placeholderTarget.style.display = 'none';
        if (this.hasSelectContainerTarget) this.selectContainerTarget.style.display = 'block';
        if (this.hasSelectCheckboxTarget) this.selectCheckboxTarget.checked = false;
        if (this.hasDeleteButtonTarget) this.deleteButtonTarget.classList.add('hidden');
        if (this.hasDeleteInputTarget) this.deleteInputTarget.value = '0';
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
        this.fileInputTarget.value = '';
        this.cropInputTarget.value = '';
    }
}
