import { Controller } from '@hotwired/stimulus';

/*
 * Full-screen viewer for the images embedded in rendered content (forum posts).
 *
 * Usage:
 *   <div data-controller="image-lightbox">
 *       <div class="post-content" data-action="click->image-lightbox#open keydown->image-lightbox#openWithKeyboard">…</div>
 *       <dialog class="modal modal-media" data-image-lightbox-target="dialog" data-action="click->image-lightbox#closeOnBackdrop">
 *           <img data-image-lightbox-target="image"> <a data-image-lightbox-target="original"></a> …
 *       </dialog>
 *   </div>
 * The images of every element bound to "open" become keyboard-focusable; the dialog shows the image
 * at its original size within the viewport, with a link to the file itself.
 */
export default class extends Controller {
    static targets = ['dialog', 'image', 'original'];

    connect() {
        this.element.querySelectorAll('[data-action*="image-lightbox#open"] img').forEach(image => {
            image.tabIndex = 0;
            image.classList.add('is-zoomable');
            image.setAttribute('role', 'button');
            image.setAttribute('aria-label', image.alt ? `Agrandir l'image : ${image.alt}` : "Agrandir l'image");
        });
    }

    open(event) {
        const image = event.target;
        if (!(image instanceof HTMLImageElement)) return;
        event.preventDefault();
        this.show(image);
    }

    openWithKeyboard(event) {
        if ((event.key === 'Enter' || event.key === ' ') && event.target instanceof HTMLImageElement) {
            event.preventDefault();
            this.show(event.target);
        }
    }

    show(image) {
        this.imageTarget.src = image.currentSrc || image.src;
        this.imageTarget.alt = image.alt;
        if (this.hasOriginalTarget) this.originalTarget.href = image.src;
        this.dialogTarget.showModal();
    }

    close() {
        this.dialogTarget.close();
    }

    // A click on the dialog element itself (outside the image) is a click on the backdrop
    closeOnBackdrop(event) {
        if (event.target === event.currentTarget) this.close();
    }
}
