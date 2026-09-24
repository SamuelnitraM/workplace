import { Controller } from '@hotwired/stimulus';
import { playSound } from '../lib/sounds.js';

/*
 * Aperçu des sons de notification (paramètres du profil) : bouton « Écouter » de chaque son,
 * et lecture du son choisi quand on change de choix.
 *
 *   <div data-controller="sound-preview">
 *     <input type="radio" value="auspex" data-action="change->sound-preview#choose">
 *     <button type="button" data-action="sound-preview#play" data-sound-preview-sound-param="auspex">Écouter</button>
 */
export default class extends Controller {
    play(event) {
        playSound(event.params.sound);
    }

    choose(event) {
        playSound(event.target.value);
    }
}
