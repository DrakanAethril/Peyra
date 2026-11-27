import { Controller } from '@hotwired/stimulus';

/*
 * The confirmation bar of a gesture that commits (design-ux.md § 6.2, A7): in place of the head's
 * actions, no veil, no confirm(); the focus on the button that confirms, Entrée confirms, Échap or
 * « Annuler » renounces and gives the focus back to the button that opened it.
 */
export default class extends Controller {
    static targets = ['actions', 'bar', 'confirm'];

    open(event) {
        this.opener = event?.currentTarget ?? null;
        this.actionsTarget.hidden = true;
        this.barTarget.hidden = false;
        this.confirmTarget.focus();
    }

    close(event) {
        event?.preventDefault();
        this.barTarget.hidden = true;
        this.actionsTarget.hidden = false;
        this.opener?.focus();
    }

    keydown(event) {
        if (event.key === 'Escape') {
            event.preventDefault();
            event.stopPropagation();
            this.close();
        }
    }
}
