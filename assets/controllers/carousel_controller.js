import { Controller } from '@hotwired/stimulus';

/*
 * Horizontal carousel built on a scroll-snapping list (templates/home/_photo_carousel.html.twig).
 *
 * Usage:
 *   <section data-controller="carousel">
 *       <button data-carousel-target="previous" data-action="carousel#previous">…</button>
 *       <button data-carousel-target="next" data-action="carousel#next">…</button>
 *       <ul data-carousel-target="track" data-action="scroll->carousel#update">…</ul>
 *   </section>
 * The buttons scroll by one visible page; they are disabled at both ends of the track.
 */
export default class extends Controller {
    static targets = ['track', 'previous', 'next'];

    connect() {
        this.resizeObserver = new ResizeObserver(() => this.update());
        this.resizeObserver.observe(this.trackTarget);
        this.update();
    }

    disconnect() {
        this.resizeObserver.disconnect();
    }

    previous() {
        this.scrollByPage(-1);
    }

    next() {
        this.scrollByPage(1);
    }

    scrollByPage(direction) {
        const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        this.trackTarget.scrollBy({ left: direction * this.trackTarget.clientWidth, behavior: reducedMotion ? 'auto' : 'smooth' });
    }

    update() {
        const track = this.trackTarget;
        const maxScroll = track.scrollWidth - track.clientWidth;
        this.previousTarget.disabled = track.scrollLeft <= 1;
        this.nextTarget.disabled = track.scrollLeft >= maxScroll - 1;
        this.element.classList.toggle('is-static', maxScroll <= 1);
    }
}
