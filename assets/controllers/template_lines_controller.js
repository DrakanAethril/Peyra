import { Controller } from '@hotwired/stimulus';

/*
 * The lines of an entry template's fiche (design-ux.md § 3.2, « Modèles d'écriture »): each line is
 * its own tbody; « Ajouter une ligne » and Inser add one from the prototype (its fields named
 * lines[<n>][…], the server reads them in the order of the page), the bin and Ctrl Suppr remove
 * the current one; the fixed amount shows only on a line whose amount is « Montant fixe »; a bank
 * or cash journal says that its counterpart is added by itself.
 */
export default class extends Controller {
    static targets = ['line', 'prototype', 'fixed', 'counterpart'];

    static values = { next: Number, counterparts: Object };

    connect() {
        this.next = this.nextValue;
        this.counterparts = this.counterpartsValue;
        this.showCounterpart();
    }

    add(event) {
        event?.preventDefault();
        const html = this.prototypeTarget.innerHTML.replaceAll('__N__', String(this.next));
        this.next += 1;
        this.prototypeTarget.insertAdjacentHTML('beforebegin', html);
        const line = this.lineTargets.at(-1);
        line?.querySelector('input[role="combobox"]')?.focus();
    }

    remove(event) {
        event.preventDefault();
        this.drop(event.target.closest('tbody'));
    }

    drop(line) {
        if (!line) {
            return;
        }
        const lines = this.lineTargets;
        const at = lines.indexOf(line);
        line.remove();
        const neighbour = this.lineTargets[Math.min(at, this.lineTargets.length - 1)];
        if (neighbour) {
            neighbour.querySelector('input[role="combobox"]')?.focus();
        } else {
            this.add();
        }
    }

    keydown(event) {
        const ctrl = event.ctrlKey || event.metaKey;
        if (event.key === 'Insert' && !ctrl && !event.altKey) {
            event.preventDefault();
            event.stopPropagation();
            this.add();
        } else if (event.key === 'Delete' && ctrl) {
            event.preventDefault();
            event.stopPropagation();
            this.drop(event.target.closest('tbody'));
        }
    }

    /** The fixed amount is the line's own only for « Montant fixe ». */
    amount(event) {
        const line = event.target.closest('tbody');
        const fixed = line?.querySelector('[data-template-lines-target="fixed"]');
        if (fixed) {
            fixed.hidden = event.target.value !== 'fixed';
            if (!fixed.hidden) {
                fixed.querySelector('input:not([type="hidden"])')?.focus();
            }
        }
    }

    journal() {
        this.showCounterpart();
    }

    showCounterpart() {
        if (!this.hasCounterpartTarget) {
            return;
        }
        const code = this.element.querySelector('select[name="journal"]')?.value ?? '';
        const number = this.counterparts[code];
        this.counterpartTarget.hidden = !number;
        this.counterpartTarget.textContent = number
            ? `Dans le journal ${code}, la contrepartie ${number} s’ajoute seule à chaque écriture : ne la mettez pas dans les lignes.`
            : '';
    }
}
