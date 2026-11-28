import { Controller } from '@hotwired/stimulus';
import { amount } from '../entry_grid/model.js';

/*
 * The selection of the bank reconciliation (mock-up 2; design-ux.md § 4.5): Space on a line
 * (rows_controller.js) ticks its box, Tab goes to the other side. As soon as the statement's side
 * and the books' side both hold a line and add up to the same amount, the pair is pointed - but
 * Shift+Space adds a line without pointing, for a remittance that holds several cheques. Suppr on a
 * pointed line unpoints its letter. The server checks everything again.
 */
export default class extends Controller {
    static targets = ['box', 'summary', 'state', 'submit', 'clear'];

    connect() {
        this.adding = false;
        this.noteShift = (event) => { this.adding = event.shiftKey; };
        this.element.addEventListener('keydown', this.noteShift, true);
        this.refresh();
    }

    disconnect() {
        this.element.removeEventListener('keydown', this.noteShift, true);
    }

    refresh(event) {
        const chosen = this.boxTargets.filter((one) => one.checked);
        const sum = (side) => chosen.filter((one) => one.dataset.side === side).reduce((total, one) => total + Number(one.dataset.cents), 0);
        const bank = sum('bank');
        const books = sum('books');
        const bankCount = chosen.filter((one) => one.dataset.side === 'bank').length;
        const booksCount = chosen.length - bankCount;
        const meets = chosen.length >= 2 && bank === books;

        if (this.hasSummaryTarget) {
            this.summaryTarget.textContent = chosen.length === 0
                ? 'Espace sur une ligne du relevé, Tab, Espace sur l’écriture qui lui répond : la paire est pointée. Maj+Espace ajoute une ligne sans pointer.'
                : `Relevé ${bankCount} · ${this.signed(bank)} — comptabilité ${booksCount} · ${this.signed(books)}`;
            this.stateTarget.replaceChildren();
            if (chosen.length >= 2) {
                this.stateTarget.append(this.badge(meets ? 'success' : 'warning', meets ? 'Tombe juste' : `Écart ${amount(Math.abs(bank - books))}`));
            }
        }
        if (this.hasSubmitTarget) {
            this.submitTarget.disabled = !meets;
            this.clearTarget.disabled = chosen.length === 0;
        }
        if (meets && event && !this.adding && bankCount > 0 && booksCount > 0) {
            this.element.requestSubmit(this.submitTarget);
        }
    }

    /** Suppr on a pointed line unpoints its letter. */
    keydown(event) {
        if (!(event.target instanceof HTMLTableRowElement) || event.ctrlKey || event.altKey || event.metaKey) {
            return;
        }
        if (event.key === 'Delete') {
            const unpoint = event.target.querySelector('[data-reconciliation-unpoint]');
            if (unpoint) {
                event.preventDefault();
                unpoint.click();
            }
        }
    }

    clear() {
        this.boxTargets.forEach((one) => { one.checked = false; });
        this.refresh();
    }

    signed(cents) {
        return `${cents < 0 ? '− ' : ''}${amount(Math.abs(cents))}`;
    }

    badge(tone, text) {
        const badge = this.element.ownerDocument.createElement('span');
        badge.className = `px-badge px-badge--${tone} px-badge--dot`;
        badge.textContent = text;

        return badge;
    }
}
