import { Controller } from '@hotwired/stimulus';

/*
 * The payment form (step 2.6b; design-ux.md § 3.2, « Règlements », G: « affectation proposée par
 * montant et référence »): at level G, once the amount or the reference is typed, the invoice
 * whose remaining amount equals the amount - else whose number the reference carries - is ticked
 * for the student to confirm. A box the student touched is theirs: nothing is ticked over it.
 */
export default class extends Controller {
    static targets = ['amount', 'reference', 'invoice'];

    static values = { propose: Boolean };

    connect() {
        this.chosenByHand = false;
    }

    touched() {
        this.chosenByHand = true;
    }

    propose() {
        if (!this.proposeValue || this.chosenByHand || !this.hasAmountTarget) {
            return;
        }
        const cents = this.amountTarget.querySelector('input[type="hidden"]')?.value ?? '';
        const reference = this.hasReferenceTarget ? this.referenceTarget.value.toUpperCase() : '';
        const byAmount = this.invoiceTargets.filter((box) => cents !== '' && box.dataset.remaining === cents);
        const byReference = this.invoiceTargets.filter((box) => box.dataset.document && reference.includes(box.dataset.document.toUpperCase()));
        const proposed = byAmount.length === 1 ? byAmount : byReference.slice(0, 1);
        this.invoiceTargets.forEach((box) => {
            box.checked = proposed.includes(box);
        });
    }
}
