import { Controller } from '@hotwired/stimulus';

/*
 * A subscription's fiche: the call's fields follow the template chosen - the amount (labelled HT or
 * TTC) and the rate when the template asks an amount, the third party when one of its collective
 * lines asks one. Choosing a template proposes its rate when the field is empty. The server ignores
 * a field the template does not ask; hiding it only keeps the form readable.
 */
export default class extends Controller {
    static targets = ['template', 'amount', 'rateField', 'rate', 'party'];
    static values = { calls: Object };

    template() {
        const call = this.callsValue[this.templateTarget.value] ?? null;
        const asksAmount = call?.amount === true;
        this.amountTarget.hidden = !asksAmount;
        this.rateFieldTarget.hidden = !asksAmount;
        this.partyTarget.hidden = call?.party !== true;
        if (call !== null) {
            const label = this.amountTarget.querySelector('.px-field__label');
            if (label?.firstChild) {
                label.firstChild.textContent = `${call.base} `;
            }
            if (asksAmount && this.rateTarget.value.trim() === '') {
                this.rateTarget.value = call.rate;
            }
        }
    }
}
