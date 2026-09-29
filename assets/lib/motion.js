/*
 * Motion helpers shared by the controllers.
 *
 *   import { prefersReducedMotion, scrollBehavior, afterTransitions } from '../lib/motion.js';
 *   element.scrollIntoView({ behavior: scrollBehavior(), block: 'center' });
 *   afterTransitions(toast).then(() => toast.remove());
 */
export function prefersReducedMotion() {
    return window.matchMedia('(prefers-reduced-motion: reduce)').matches;
}

/** Scroll behaviour honouring the reduced motion preference of the visitor. */
export function scrollBehavior() {
    return prefersReducedMotion() ? 'auto' : 'smooth';
}

/**
 * Resolves once the CSS transitions and animations running on the element are over (at once when there is none,
 * e.g. durations reduced to zero); a cancelled transition counts as over.
 */
export function afterTransitions(element) {
    const running = element.getAnimations?.() ?? [];
    return Promise.all(running.map(animation => animation.finished.catch(() => {}))).then(() => {});
}
