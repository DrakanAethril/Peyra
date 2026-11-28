import { Controller } from '@hotwired/stimulus';
import { amount } from '../entry_grid/model.js';

/*
 * The selection of the lettering's face-à-face (design-ux.md §§ 3.2 and 5.3, « Lettrage »): Space
 * on a line (rows_controller.js) ticks its box, Tab goes to the other side, Suppr on a lettered line
 * délettre its group. The selection's debit,
 * credit and gap are added up here, at each change: the head's « Sélection » figure, the summary
 * under the tables and « Lettrer » - which waits for a debit and a credit, and for a selection
 * that balances at levels G and B (`partial` false). A line of a partial group brings its whole
 * group, as the server does. The server checks everything again.
 */
export default class extends Controller {
    static targets = ['box', 'summary', 'state', 'reason', 'submit', 'clear'];
    static values = { partial: Boolean };

    connect() {
        this.refresh();
    }

    refresh(event) {
        const box = event?.target;
        if (box instanceof HTMLInputElement && box.dataset.group) {
            this.boxTargets
                .filter((other) => other.dataset.group === box.dataset.group)
                .forEach((other) => { other.checked = box.checked; });
        }

        const chosen = this.boxTargets.filter((one) => one.checked);
        let debit = 0;
        let credit = 0;
        chosen.forEach((one) => {
            const cents = Number(one.dataset.cents);
            if (cents > 0) {
                debit += cents;
            } else {
                credit -= cents;
            }
        });
        const gap = debit - credit;
        const bothSides = debit > 0 && credit > 0;
        const balanced = bothSides && gap === 0;

        let reason = '';
        if (chosen.length === 0) {
            reason = 'Choisissez au moins un débit et un crédit.';
        } else if (!bothSides) {
            reason = `Choisissez aussi ${debit > 0 ? 'un crédit' : 'un débit'} : un lettrage rapproche les deux côtés.`;
        } else if (gap !== 0 && !this.partialValue) {
            reason = `La sélection n’est pas soldée : écart de ${amount(Math.abs(gap))} au ${gap > 0 ? 'débit' : 'crédit'}.`;
        }

        this.summaryTarget.textContent = chosen.length === 0
            ? 'Choisissez au moins un débit et un crédit : Espace sur une ligne, Tab pour changer de côté.'
            : `${chosen.length} ligne${chosen.length > 1 ? 's' : ''} · débit ${amount(debit)} · crédit ${amount(credit)}`;
        this.stateTarget.replaceChildren();
        if (bothSides) {
            this.stateTarget.append(this.badge(
                balanced ? 'success' : (this.partialValue ? 'warning' : 'danger'),
                balanced ? 'Soldée' : `${this.partialValue ? 'Partiel, reste' : 'Écart'} ${amount(Math.abs(gap))} au ${gap > 0 ? 'débit' : 'crédit'}`,
            ));
        }
        this.reasonTarget.textContent = reason;

        const submit = this.submitTarget;
        submit.disabled = reason !== '';
        submit.title = reason;
        submit.querySelector('span').textContent = bothSides && !balanced && this.partialValue ? 'Lettrer partiellement' : 'Lettrer';
        if (this.hasClearTarget) {
            this.clearTarget.disabled = chosen.length === 0;
        }

        const value = this.element.ownerDocument.querySelector('[data-lettering-kpi-value]');
        const note = this.element.ownerDocument.querySelector('[data-lettering-kpi-note]');
        if (value && note) {
            value.textContent = chosen.length === 0 ? '—' : (gap === 0 ? '0,00' : `${amount(Math.abs(gap))} ${gap > 0 ? 'D' : 'C'}`);
            value.classList.toggle('px-kpi__value--ok', balanced);
            value.classList.toggle('px-kpi__value--alert', bothSides && !balanced);
            note.textContent = chosen.length === 0 ? 'Aucune ligne choisie' : (balanced ? 'soldée : prête à lettrer' : 'solde de la sélection');
        }
    }

    /** Suppr on a lettered line (rows_controller.js gives the rows the focus) délettre its group. */
    keydown(event) {
        if (event.key !== 'Delete' || event.ctrlKey || event.altKey || event.metaKey || !(event.target instanceof HTMLTableRowElement)) {
            return;
        }
        const unletter = event.target.querySelector('[data-lettering-unletter]');
        if (unletter) {
            event.preventDefault();
            unletter.click();
        }
    }

    clear() {
        this.boxTargets.forEach((one) => { one.checked = false; });
        this.refresh();
    }

    badge(tone, text) {
        const badge = this.element.ownerDocument.createElement('span');
        badge.className = `px-badge px-badge--${tone} px-badge--dot`;
        badge.textContent = text;

        return badge;
    }
}
