/*
 * Distribuer, step 2 (design-ux.md § 4.2): the groups' table shows only while « Un dossier partagé
 * par groupe » is chosen. The server reads the groups in that mode alone, so a hidden table posts
 * harmless values.
 */
import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['groups'];

    sync(event) {
        if (event.target.name !== 'mode') {
            return;
        }
        this.groupsTarget.hidden = event.target.value !== 'group';
    }
}
