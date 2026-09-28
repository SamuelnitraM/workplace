/*
 * Loading placeholder of the zones filled by JavaScript: a copy of the rows of templates/_partials/_skeleton_rows.html.twig,
 * rendered once in <template id="skeleton-rows"> (base.html.twig).
 *
 *   import { skeletonRows } from '../lib/skeleton.js';
 *   list.replaceChildren(skeletonRows(3));
 */
export function skeletonRows(count = 3, { avatar = true } = {}) {
    const source = document.getElementById('skeleton-rows');
    const placeholder = source.content.firstElementChild.cloneNode(true);
    const rows = placeholder.querySelectorAll('.skeleton-row');
    rows.forEach((row, index) => {
        if (index >= count) row.remove();
        if (!avatar) row.querySelector('.skeleton-circle')?.remove();
    });
    return placeholder;
}

export function skeletonRowsHtml(count = 3, options = {}) {
    return skeletonRows(count, options).outerHTML;
}
