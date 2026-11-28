import { Controller } from '@hotwired/stimulus';
import { format } from './amount_input_controller.js';

/*
 * A pièce's entry (design-ux.md §§ 3.5 and 5.2, « Saisie par pièce »), the grid of the processing
 * screen: each line its own tbody. A line of the proposal is marked amber until the student goes
 * through it with Entrée or changes it. Entrée moves to the next field to fill of the line, and on a
 * complete line opens the next one (a new one at the end); Inser adds a line, Ctrl Suppr removes
 * the current one; « + » in an amount puts there what balances the entry; a debit typed empties
 * the line's credit and the reverse. The totals and the pill « Équilibrée » / « Écart … » follow.
 * Changing a read data shows the banner « Reprendre l'écriture depuis les données lues »: the
 * entry is never recalculated behind the student's back. Entrée never submits: F10 does.
 */
export default class extends Controller {
    static targets = ['line', 'prototype', 'badge', 'debit', 'credit', 'stale', 'card'];

    connect() {
        this.next = Number(this.cardTarget.dataset.next ?? this.lineTargets.length);
        this.current = this.lineTargets[0] ?? null;
        this.onAmount = (event) => this.amountChanged(event);
        this.element.addEventListener('amount-input:change', this.onAmount);
        this.onFocus = (event) => {
            const line = event.target.closest('[data-piece-entry-target="line"]');
            if (line) {
                this.current = line;
            }
        };
        this.element.addEventListener('focusin', this.onFocus);
        this.onInput = (event) => {
            event.target.closest('[data-piece-entry-target="line"]')?.classList.remove('is-proposed');
        };
        this.element.addEventListener('input', this.onInput);
        this.totals();
    }

    disconnect() {
        this.element.removeEventListener('amount-input:change', this.onAmount);
        this.element.removeEventListener('focusin', this.onFocus);
        this.element.removeEventListener('input', this.onInput);
    }

    keydown(event) {
        const line = event.target.closest('[data-piece-entry-target="line"]');
        const ctrl = event.ctrlKey || event.metaKey;
        if (event.key === 'Enter' && !ctrl && !event.altKey && event.target instanceof HTMLInputElement) {
            // Entrée evaluates an amount and never submits the form; on an account, the picker's own Entrée chose a line.
            const picked = event.defaultPrevented && event.target.getAttribute('role') === 'combobox';
            event.preventDefault();
            if (line && !picked) {
                this.advance(line, event.target);
            }

            return;
        }
        if (!line) {
            return;
        }
        if (event.key === 'Insert' && !ctrl && !event.altKey) {
            event.preventDefault();
            this.add();
        } else if (event.key === 'Delete' && ctrl) {
            event.preventDefault();
            this.drop(line);
        } else if ((event.key === '+' || event.code === 'NumpadAdd') && !ctrl && this.isAmount(event.target) && event.target.value.trim() === '') {
            event.preventDefault();
            this.balanceLine(line);
        }
    }

    /** The button « Équilibrer »: on the line being worked, else the last one with an account. */
    balance(event) {
        event?.preventDefault();
        const line = this.current ?? this.lineTargets.at(-1);
        if (line) {
            this.balanceLine(line);
        }
    }

    remove(event) {
        event.preventDefault();
        this.drop(event.target.closest('[data-piece-entry-target="line"]'));
    }

    readingChanged() {
        if (this.hasStaleTarget) {
            this.staleTarget.hidden = false;
        }
    }

    add() {
        const html = this.prototypeTarget.innerHTML.replaceAll('__N__', String(this.next));
        this.next += 1;
        const holder = this.lineTargets.at(-1) ?? this.prototypeTarget;
        holder.insertAdjacentHTML(holder === this.prototypeTarget ? 'beforebegin' : 'afterend', html);
        const line = this.lineTargets.at(-1);
        this.current = line;
        line?.querySelector('input[role="combobox"]')?.focus();

        return line;
    }

    drop(line) {
        if (!line) {
            return;
        }
        const at = this.lineTargets.indexOf(line);
        line.remove();
        const neighbour = this.lineTargets[Math.min(at, this.lineTargets.length - 1)] ?? this.add();
        neighbour?.querySelector('input[role="combobox"]')?.focus();
        this.totals();
    }

    /** Entrée: the line gone through loses its amber mark; the focus goes to what is left to fill, or to the next line. */
    advance(line, field) {
        line.classList.remove('is-proposed');
        const account = line.querySelector('input[role="combobox"]');
        const [debit, credit] = this.amountsOf(line);
        const complete = account && account.value.trim() !== '' && (this.cents(debit) !== 0 || this.cents(credit) !== 0);
        if (!complete) {
            const order = [account, debit?.visible, credit?.visible].filter(Boolean);
            const from = order.indexOf(field);
            const empty = order.slice(from + 1).find((input) => input.value.trim() === '' && !(input === credit?.visible && this.cents(debit) !== 0));
            (empty ?? order[Math.min(from + 1, order.length - 1)])?.focus();

            return;
        }
        const lines = this.lineTargets;
        const following = lines[lines.indexOf(line) + 1] ?? this.add();
        following?.querySelector('input[role="combobox"]')?.focus();
    }

    amountChanged(event) {
        const holder = event.target.closest('.px-amount');
        const line = event.target.closest('[data-piece-entry-target="line"]');
        if (line && holder && (event.detail?.cents ?? 0) !== 0) {
            // A debit typed empties the credit of the line, and the reverse.
            const [debit, credit] = this.amountsOf(line);
            const other = holder.dataset.side === 'debit' ? credit : debit;
            if (other) {
                this.write(other, 0);
            }
        }
        this.totals();
    }

    /** « + »: what balances the entry, on the side it is missing, on this line. */
    balanceLine(line) {
        const [debit, credit] = this.amountsOf(line);
        if (!debit || !credit) {
            return;
        }
        const { totalDebit, totalCredit } = this.sums(line);
        const gap = totalDebit - totalCredit;
        if (gap === 0) {
            return;
        }
        this.write(gap > 0 ? credit : debit, Math.abs(gap));
        this.write(gap > 0 ? debit : credit, 0);
        line.classList.remove('is-proposed');
        (gap > 0 ? credit.visible : debit.visible).focus();
        this.totals();
    }

    totals() {
        const { totalDebit, totalCredit } = this.sums(null);
        this.debitTarget.textContent = totalDebit === 0 ? '' : format(BigInt(totalDebit));
        this.creditTarget.textContent = totalCredit === 0 ? '' : format(BigInt(totalCredit));
        const badge = this.badgeTarget;
        if (totalDebit === 0 && totalCredit === 0) {
            badge.className = 'px-badge px-badge--neutral px-badge--dot';
            badge.textContent = 'Vide';
        } else if (totalDebit === totalCredit) {
            badge.className = 'px-badge px-badge--success px-badge--dot';
            badge.textContent = 'Équilibrée';
        } else {
            const gap = totalDebit - totalCredit;
            badge.className = 'px-badge px-badge--warning px-badge--dot';
            badge.textContent = `Écart ${format(BigInt(Math.abs(gap)))} au ${gap > 0 ? 'crédit' : 'débit'}`;
        }
    }

    /** The totals of the lines - without `except` when it is given. */
    sums(except) {
        let totalDebit = 0;
        let totalCredit = 0;
        for (const line of this.lineTargets) {
            if (line === except) {
                continue;
            }
            const [debit, credit] = this.amountsOf(line);
            totalDebit += this.cents(debit);
            totalCredit += this.cents(credit);
        }

        return { totalDebit, totalCredit };
    }

    /** @return {[{visible: HTMLInputElement, hidden: HTMLInputElement}|null, …]} the debit and the credit of a line */
    amountsOf(line) {
        return ['debit', 'credit'].map((side) => {
            const holder = line.querySelector(`.px-amount[data-side="${side}"]`);
            if (!holder) {
                return null;
            }

            return { visible: holder.querySelector('[data-amount-input-target="visible"]'), hidden: holder.querySelector('[data-amount-input-target="cents"]') };
        });
    }

    cents(amount) {
        const value = Number.parseInt(amount?.hidden?.value ?? '', 10);

        return Number.isNaN(value) ? 0 : value;
    }

    write(amount, cents) {
        amount.hidden.value = cents === 0 ? '' : String(cents);
        amount.visible.value = cents === 0 ? '' : format(BigInt(cents));
    }

    isAmount(element) {
        return element instanceof HTMLInputElement && element.dataset.amountInputTarget === 'visible';
    }
}
