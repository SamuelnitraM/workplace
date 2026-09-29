/*
 * Navigation through Turbo Drive, or a regular page load when Turbo is not available.
 *
 *   import { visit } from '../lib/turbo.js';
 *   visit(url, { action: 'replace' });
 */
export function visit(url, options = {}) {
    if (window.Turbo?.visit) {
        window.Turbo.visit(url, options);
    } else {
        window.location.href = url;
    }
}
