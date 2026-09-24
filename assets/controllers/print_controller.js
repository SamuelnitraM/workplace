import { Controller } from '@hotwired/stimulus';

/* Opens the browser print dialog (print or save as PDF). Usage: <button data-controller="print" data-action="print#print">. */
export default class extends Controller {
    print() {
        window.print();
    }
}
