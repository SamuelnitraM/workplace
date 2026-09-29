/*
 * SVG icons of the site built in JavaScript, from the sprite of the page (templates/_partials/_icon_sprite.html.twig):
 * same drawing and attributes as the Twig macro ui.icon(). The name must be listed in AppExtension::spriteIcons().
 *
 *   import { icon } from '../lib/icon.js';
 *   button.append(icon('heart', 'size-4'));
 *   button.append(icon('pin', 'size-4', { label: 'Épinglé' }));
 */
const SVG_NAMESPACE = 'http://www.w3.org/2000/svg';

/** Empty <svg> with the attributes of ui.icon(): decorative, or announced as an image when a label is given. */
function svgElement(className, { label = null, strokeWidth = '2' } = {}) {
    const svg = document.createElementNS(SVG_NAMESPACE, 'svg');
    svg.setAttribute('viewBox', '0 0 24 24');
    svg.setAttribute('fill', 'none');
    svg.setAttribute('stroke', 'currentColor');
    svg.setAttribute('stroke-width', strokeWidth);
    svg.setAttribute('stroke-linecap', 'round');
    svg.setAttribute('stroke-linejoin', 'round');
    svg.setAttribute('class', className);
    if (label) {
        svg.setAttribute('role', 'img');
        svg.setAttribute('aria-label', label);
    } else {
        svg.setAttribute('aria-hidden', 'true');
    }
    svg.setAttribute('focusable', 'false');
    return svg;
}

export function icon(name, className = 'size-5', options = {}) {
    const svg = svgElement(className, options);
    const use = document.createElementNS(SVG_NAMESPACE, 'use');
    use.setAttribute('href', `#icon-${name}`);
    svg.append(use);
    return svg;
}
