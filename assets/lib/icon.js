/*
 * SVG icon of the site built in JavaScript, from the sprite of the page (templates/_partials/_icon_sprite.html.twig):
 * same drawing and attributes as the Twig macro ui.icon(). The name must be listed in AppExtension::spriteIcons().
 *
 *   import { icon } from '../lib/icon.js';
 *   button.append(icon('heart', 'size-4'));
 */
const SVG_NAMESPACE = 'http://www.w3.org/2000/svg';

export function icon(name, className = 'size-5') {
    const svg = document.createElementNS(SVG_NAMESPACE, 'svg');
    svg.setAttribute('viewBox', '0 0 24 24');
    svg.setAttribute('fill', 'none');
    svg.setAttribute('stroke', 'currentColor');
    svg.setAttribute('stroke-width', '2');
    svg.setAttribute('stroke-linecap', 'round');
    svg.setAttribute('stroke-linejoin', 'round');
    svg.setAttribute('class', className);
    svg.setAttribute('aria-hidden', 'true');
    svg.setAttribute('focusable', 'false');
    const use = document.createElementNS(SVG_NAMESPACE, 'use');
    use.setAttribute('href', `#icon-${name}`);
    svg.append(use);
    return svg;
}
