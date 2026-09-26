import { Controller } from '@hotwired/stimulus';
import { driver } from 'driver.js';
import 'driver.js/dist/driver.css';

/* stimulusFetch: 'lazy' */

/*
 * Guided tour of a feature (driver.js), started when the page is opened with ?visite=<key>.
 * Steps come from App\Tour\TourCatalog: each one highlights the first visible element of its selector list,
 * or is shown in the middle of the screen when none is visible. The last step offers the next tour.
 *
 * Usage (templates/base.html.twig):
 *   <div hidden {{ stimulus_controller('tour', {steps: [...], nextTitle: '…', nextUrl: '…', index: path('app_tour_index')}) }}></div>
 */
export default class extends Controller {
    static values = { steps: Array, nextTitle: String, nextUrl: String, index: String };

    connect() {
        this.removeTourParameter();
        this.tour = driver({
            steps: this.stepsValue.map(step => this.buildStep(step)),
            showProgress: true,
            progressText: '{{current}} / {{total}}',
            nextBtnText: 'Suivant',
            prevBtnText: 'Précédent',
            doneBtnText: 'Terminer',
            popoverClass: 'tour-popover',
            onPopoverRender: (popover, { driver: tour }) => {
                if (tour.isLastStep()) this.addFollowUpLinks(popover);
            },
        });
        this.tour.drive();
    }

    disconnect() {
        this.tour?.destroy();
    }

    buildStep({ element, title, text }) {
        const target = element ? this.firstVisible(element) : null;
        return { element: target ?? undefined, popover: { title, description: text } };
    }

    firstVisible(selectors) {
        return [...document.querySelectorAll(selectors)].find(candidate => candidate.getClientRects().length > 0) ?? null;
    }

    // Links under the last step: the next tour, or back to the list of tours
    addFollowUpLinks(popover) {
        const links = document.createElement('p');
        links.className = 'tour-popover-links';
        if (this.nextUrlValue) {
            links.append(this.link(this.nextUrlValue, `Visite suivante : ${this.nextTitleValue} →`));
        }
        links.append(this.link(this.indexValue, 'Toutes les visites'));
        popover.description.after(links);
    }

    link(url, text) {
        const anchor = document.createElement('a');
        anchor.href = url;
        anchor.textContent = text;
        anchor.addEventListener('click', () => this.tour.destroy());
        return anchor;
    }

    // The address loses ?visite so a reload or a shared link does not restart the tour
    removeTourParameter() {
        const url = new URL(window.location.href);
        if (!url.searchParams.has('visite')) return;
        url.searchParams.delete('visite');
        window.history.replaceState(window.history.state, '', url);
    }
}
