import { Controller } from '@hotwired/stimulus';

/*
 * Onglets : un clic sur un onglet affiche le panneau de même nom et masque les autres.
 *
 * Usage :
 *   <div data-controller="tabs">
 *     <div class="tabs" role="tablist">
 *       <button type="button" role="tab" class="tab" aria-selected="true" data-tabs-target="tab" data-tab="a" data-action="tabs#select">A</button>
 *     </div>
 *     <div data-tabs-target="panel" data-panel="a">…</div>
 *     <div data-tabs-target="panel" data-panel="b" class="hidden">…</div>
 *   </div>
 *
 * L'état actif est porté par aria-selected (style .tab du design system) ; data-tabs-active-class / -inactive-class restent facultatifs.
 * L'onglet dont le nom est l'ancre de l'URL (#a) est affiché au chargement.
 * Un panneau inactif est masqué par l'attribut « hidden » (la classe « hidden » éventuelle est retirée à l'affichage).
 */
export default class extends Controller {
    static targets = ['tab', 'panel'];
    static classes = ['active', 'inactive'];

    // Ouverture directe d'un onglet par l'ancre de l'URL (#badges), ex. après une redirection
    connect() {
        const name = window.location.hash.slice(1);
        if (name && this.tabTargets.some(tab => tab.dataset.tab === name)) this.show(name);
    }

    select(event) {
        this.show(event.currentTarget.dataset.tab);
    }

    show(name) {
        this.tabTargets.forEach(tab => {
            const selected = tab.dataset.tab === name;
            tab.setAttribute('aria-selected', selected ? 'true' : 'false');
            if (this.hasActiveClass) this.activeClasses.forEach(c => tab.classList.toggle(c, selected));
            if (this.hasInactiveClass) this.inactiveClasses.forEach(c => tab.classList.toggle(c, !selected));
        });

        this.panelTargets.forEach(panel => {
            const selected = panel.dataset.panel === name;
            panel.hidden = !selected;
            if (selected) panel.classList.remove('hidden');
        });
    }
}
