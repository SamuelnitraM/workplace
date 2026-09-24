import { Controller } from '@hotwired/stimulus';

/*
 * Opens and closes native <dialog class="modal"> elements.
 *
 * Usage:
 *   <button data-action="modal#open" data-modal-id-param="datasheet-12">…</button>
 *   <dialog id="datasheet-12" class="modal" data-action="click->modal#closeOnBackdrop">
 *       … <button data-action="modal#close">Fermer</button>
 *   </dialog>
 * The controller is placed on a common ancestor of the triggers and dialogs.
 * Escape closes the dialog natively; focus returns to the trigger.
 */
export default class extends Controller {
    open(event) {
        const dialog = document.getElementById(event.params.id);
        if (dialog instanceof HTMLDialogElement && !dialog.open) {
            event.preventDefault();
            dialog.showModal();
        }
    }

    close(event) {
        event.currentTarget.closest('dialog')?.close();
    }

    // A click on the dialog element itself (outside its content box) is a click on the backdrop
    closeOnBackdrop(event) {
        if (event.target === event.currentTarget) {
            event.currentTarget.close();
        }
    }
}
