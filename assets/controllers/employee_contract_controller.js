import { Controller } from '@hotwired/stimulus';

/*
 * The contract of an employee's fiche: a field is shown only for the choices it belongs to - the
 * end of the contract for a CDD and an apprenticeship, the ground for a CDD, the diploma and the
 * training centre for an apprenticeship, the monthly hours for a part-time contract. Each field
 * names its select and the values that show it, « contractType:fixed_term,apprenticeship ». The
 * server drops what a choice does not use; hiding it only keeps the form readable.
 */
export default class extends Controller {
    static targets = ['field'];

    connect() {
        this.toggle();
    }

    toggle() {
        for (const field of this.fieldTargets) {
            const [id, values] = (field.dataset.when ?? '').split(':');
            const select = this.element.querySelector(`#${id}`);
            field.hidden = !(values ?? '').split(',').includes(select ? select.value : '');
        }
    }
}
