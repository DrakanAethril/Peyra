import { Controller } from '@hotwired/stimulus';

/*
 * The students of a passage de classe (design-ux.md § 4.12): a ticked student goes to the next
 * class, so the « Sinon » choice of their row has no meaning and is disabled (a disabled field is
 * not submitted); unticking enables it.
 */
export default class extends Controller {
    static targets = ['box', 'other'];

    connect() {
        this.sync();
    }

    sync() {
        const ticked = new Map(this.boxTargets.map((box) => [box.dataset.student, box.checked]));
        this.otherTargets.forEach((select) => {
            select.disabled = ticked.get(select.dataset.student) === true;
        });
    }
}
