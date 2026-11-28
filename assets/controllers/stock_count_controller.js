import { Controller } from '@hotwired/stimulus';

/*
 * The sheet of a physical inventory (step 2.5b; design-ux.md § 3.2, gabarit G):
 * - entering a cell selects what it shows, so typing replaces it;
 * - Entrée in a counted quantity goes to its reason when the line shows a gap, else to the next
 *   line's quantity; Entrée in a reason goes to the next line's quantity (Tab visits every cell);
 * - the gap follows what is typed, computed in thousandths of a unit - whole numbers, no float
 *   touches a quantity -; the server reads and computes everything again on F10.
 */
export default class extends Controller {
    static targets = ['row', 'cell', 'gap'];

    select(event) {
        event.target.select();
    }

    key(event) {
        if (event.key !== 'Enter' || event.ctrlKey || event.metaKey || event.altKey || event.shiftKey) {
            return;
        }
        event.preventDefault();
        const row = event.target.closest('tr');
        const index = this.rowTargets.indexOf(row);
        const [counted, reason] = row.querySelectorAll('input');
        if (event.target === counted && reason && this.gapOf(row) !== 0) {
            reason.focus();

            return;
        }
        const next = this.rowTargets[index + 1];
        if (next) {
            next.querySelector('input').focus();
        }
    }

    follow(event) {
        const row = event.target.closest('tr');
        const gap = this.gapOf(row);
        row.querySelector('[data-stock-count-target="gap"]').textContent = gap === null || gap === 0 ? '' : format(gap);
    }

    /** The counted quantity minus the theoretical one, in thousandths; null while uncounted or unreadable. */
    gapOf(row) {
        const counted = thousandths(row.querySelector('input').value);
        const theoretical = thousandths(row.dataset.theoretical ?? '0');

        return counted === null || theoretical === null ? null : counted - theoretical;
    }
}

/** « 12,5 » or « 12.500 » as 12500; null when it is not a quantity of three decimals at most. */
function thousandths(text) {
    const plain = text.replace(/[\s  ]/g, '').replace(',', '.');
    const match = /^(\d+)(?:\.(\d{0,3}))?$/.exec(plain);
    if (!match) {
        return null;
    }

    return Number(match[1]) * 1000 + Number((match[2] ?? '').padEnd(3, '0'));
}

/** « +2,00 », « −0,125 »: as the server writes a gap, two decimals or three. */
function format(gap) {
    const abs = Math.abs(gap);
    let decimals = String(abs % 1000).padStart(3, '0');
    decimals = decimals.endsWith('0') ? decimals.slice(0, 2) : decimals;
    const whole = String(Math.floor(abs / 1000)).replace(/\B(?=(\d{3})+(?!\d))/g, ' ');

    return `${gap > 0 ? '+' : '−'}${whole},${decimals}`;
}
