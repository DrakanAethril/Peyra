import { Controller } from '@hotwired/stimulus';

/*
 * The payment terms of a third party's fiche: the number of days (N) is shown for the terms that
 * count days, the day of the month (J) for « le J du mois suivant », neither for « comptant ». The
 * server ignores the field a term does not use; hiding it only keeps the form readable.
 */
export default class extends Controller {
    static targets = ['kind', 'days', 'day'];
    static values = { days: Array, day: Array };

    connect() {
        this.toggle();
    }

    toggle() {
        const kind = this.kindTarget.value;
        const days = this.daysValue;
        const day = this.dayValue;
        this.daysTarget.hidden = !days.includes(kind);
        this.dayTarget.hidden = !day.includes(kind);
    }
}
