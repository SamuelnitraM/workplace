import { Controller } from '@hotwired/stimulus';

/*
 * Photo page viewer:
 * - enlargement: the photo opens full screen (<dialog class="modal modal-media">) only when it is displayed smaller
 *   than its real size; otherwise the zoom affordance stays hidden;
 * - keyboard navigation: left / right arrows go to the previous / next photo (not while typing or in a window).
 *
 * Targets: image (photo of the page), zoom (button wrapping the photo), dialog (enlarged view).
 * Values: previousUrl, nextUrl (empty at the ends of the sequence).
 */
export default class extends Controller {
    static targets = ['image', 'zoom', 'dialog'];
    static values = { previousUrl: String, nextUrl: String };

    connect() {
        this.onKeydown = this.keydown.bind(this);
        document.addEventListener('keydown', this.onKeydown);
        if (this.imageTarget.complete) {
            this.updateZoomAvailability();
        }
        this.onResize = () => this.updateZoomAvailability();
        window.addEventListener('resize', this.onResize);
    }

    disconnect() {
        document.removeEventListener('keydown', this.onKeydown);
        window.removeEventListener('resize', this.onResize);
    }

    // Image loaded or viewport resized: enlargement only makes sense when the photo is shown reduced
    updateZoomAvailability() {
        const image = this.imageTarget;
        const reduced = image.naturalWidth > image.clientWidth + 1 || image.naturalHeight > image.clientHeight + 1;
        this.zoomTarget.classList.toggle('is-zoomable', reduced);
        this.zoomTarget.disabled = !reduced;
    }

    open() {
        if (!this.zoomTarget.disabled) {
            this.dialogTarget.showModal();
        }
    }

    close() {
        this.dialogTarget.close();
    }

    closeOnBackdrop(event) {
        if (event.target === this.dialogTarget || event.target.tagName === 'IMG') {
            this.dialogTarget.close();
        }
    }

    keydown(event) {
        if (event.defaultPrevented || event.altKey || event.ctrlKey || event.metaKey || document.querySelector('dialog[open]')) return;
        if (event.target.closest('input, textarea, select, [contenteditable="true"]')) return;
        const url = event.key === 'ArrowLeft' ? this.previousUrlValue : (event.key === 'ArrowRight' ? this.nextUrlValue : '');
        if (!url) return;
        event.preventDefault();
        if (window.Turbo) {
            window.Turbo.visit(url);
        } else {
            window.location.assign(url);
        }
    }
}
