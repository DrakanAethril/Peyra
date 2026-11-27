import { Controller } from '@hotwired/stimulus';

/*
 * The boxes of a list (design-ux.md § 3.1, L): a box per row in the first column, one in the head
 * that ticks them all; once a row is ticked, the lot actions take the place of the filters and say
 * how many rows they act on. Boxes that are disabled (a row the gesture does not apply to) are left
 * as they are.
 */
export default class extends Controller {
    static targets = ['box', 'all', 'bar', 'idle', 'count'];
    static values = { one: String, many: String };

    connect() {
        this.refresh();
    }

    toggleAll() {
        const checked = this.allTarget.checked;
        this.enabledBoxes().forEach((box) => {
            box.checked = checked;
        });
        this.refresh();
    }

    refresh() {
        const boxes = this.enabledBoxes();
        const ticked = boxes.filter((box) => box.checked).length;
        if (this.hasAllTarget) {
            this.allTarget.checked = boxes.length > 0 && ticked === boxes.length;
            this.allTarget.indeterminate = ticked > 0 && ticked < boxes.length;
        }
        this.barTargets.forEach((bar) => {
            bar.hidden = ticked === 0;
        });
        this.idleTargets.forEach((idle) => {
            idle.hidden = ticked > 0;
        });
        this.countTargets.forEach((count) => {
            count.textContent = (ticked > 1 ? this.manyValue : this.oneValue).replace('%d', String(ticked));
        });
    }

    enabledBoxes() {
        return this.boxTargets.filter((box) => !box.disabled);
    }
}
