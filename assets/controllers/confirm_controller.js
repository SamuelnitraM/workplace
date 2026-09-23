import { Controller } from '@hotwired/stimulus';

/*
 * Demande de confirmation avant l'envoi d'un formulaire (ou le suivi d'un lien).
 * Si l'utilisateur annule, l'évènement est bloqué (Turbo ne soumet pas le formulaire non plus).
 *
 * Usage : <form {{ stimulus_controller('confirm', {message: 'Supprimer ?'})|stimulus_action('confirm', 'ask', 'submit') }}>
 */
export default class extends Controller {
    static values = { message: { type: String, default: 'Confirmer ?' } };

    ask(event) {
        if (!window.confirm(this.messageValue)) {
            event.preventDefault();
            event.stopImmediatePropagation();
        }
    }
}
